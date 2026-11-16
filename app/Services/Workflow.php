<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentSignature;
use App\Models\FormType;
use App\Models\Individual;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Document life cycle: draft -> signing -> (to_pay) -> done, signatures and payment. */
class Workflow
{
    public static function fail(string|array $msg): never
    {
        throw ValidationException::withMessages(['workflow' => (array) $msg]);
    }

    public static function log(Document $doc, string $msg, string $type = 'note', ?User $u = null): void
    {
        DocumentEvent::create(['document_id' => $doc->id, 'user_id' => ($u ?? auth()->user())?->id,
            'type' => $type, 'message' => $msg]);
    }

    /** Signature chain from Forms Management. */
    public static function initSignatures(Document $doc, User $preparer): void
    {
        $ft = FormType::byCode($doc->form_code);
        $roles = $ft ? $ft->roles()->where('active', true)->with('slot')->get() : collect();
        $i = 0;
        foreach ($roles as $r) {
            $i++;
            DocumentSignature::create([
                'document_id' => $doc->id, 'sequence' => $i * 10, 'role' => $r->name, 'slot_code' => $r->slot?->code,
                'is_preparer' => $r->is_preparer, 'is_payer' => $r->is_payer, 'has_date' => $r->has_date,
                'required' => $r->required, 'print_label' => $r->print_label,
                'individual_id' => $r->is_preparer ? $preparer->individual_id : $r->default_individual_id,
            ]);
        }
    }

    /** Save the header + tables of a draft. */
    public static function save(Document $doc, array $input, array $extra, User $u, array $signers = []): Document
    {
        $form = $doc->form();
        $data = $form->normalize($input);
        $errors = $form->validate($data);
        if ($errors) {
            static::fail($errors);
        }
        return DB::transaction(function () use ($doc, $data, $form, $extra, $u, $signers) {
            $isNew = !$doc->exists;
            $doc->fill([
                'data' => $data,
                'extra' => $extra,
                'date' => $data['date'] ?? date('Y-m-d'),
                'description' => mb_substr($form->describe($data), 0, 250),
                'amount' => $form->amount($data),
                'currency' => $form->currency($data),
            ]);
            if ($isNew) {
                $doc->user_id = $u->id;
                $doc->state = 'draft';
            }
            $doc->save();
            if ($isNew) {
                static::initSignatures($doc, $doc->user);
                static::log($doc, 'Created.', 'create', $u);
            }
            foreach ($signers as $sigId => $indId) {
                $s = $doc->signatures()->find($sigId);
                if ($s && (!$s->is_preparer || $u->hasPerm('documents.manage'))) {
                    $s->update(['individual_id' => $indId ?: null]);
                }
            }
            return $doc->refresh();
        });
    }

    /** Use a reserved number (own) for the document. */
    public static function attachNumber(Document $doc, Reservation $res, User $u): void
    {
        if ($res->state !== 'reserved' || ($res->user_id !== $u->id && !$u->hasPerm('numbers.manage'))) {
            static::fail('This number is not one of your free reserved numbers.');
        }
        if ($doc->number) {
            static::fail('The form already has the number ' . $doc->number . '.');
        }
        DB::transaction(function () use ($doc, $res) {
            $doc->update(['number' => $res->number, 'reservation_id' => $res->id]);
            $res->markUsed($doc, $doc->form()->short);
        });
        static::log($doc, 'Number ' . $res->number . ' assigned.', 'number', $u);
    }

    /** Reserve and attach a number for a form that every user may fill (they may have no access to numbers). */
    public static function autoNumber(Document $doc, User $u): void
    {
        $form = $doc->form();
        $typeName = $form->settings()['number_type'] ?? null;
        $type = ($typeName ? \App\Models\TxnType::where('name', $typeName)->first() : null) ?? \App\Models\TxnType::orderBy('id')->first();
        if (!$type) {
            return;
        }
        $res = Reservation::reserve(['date' => $doc->data['date'] ?? date('Y-m-d'), 'txn_type_id' => $type->id,
            'description' => mb_substr($form->describe($doc->data ?? []) ?: $form->title . ' - ' . $u->name, 0, 250)], $u);
        $doc->update(['number' => $res->number, 'reservation_id' => $res->id]);
        $res->markUsed($doc, $form->short);
        static::log($doc, 'Number ' . $res->number . ' reserved automatically.', 'number', $u);
    }

    /** Saving makes a form final: nobody signs or pays in the system, the signature fields are only filled for the printout. */
    public static function finalize(Document $doc, User $u): void
    {
        $err = [];
        if (!$doc->number && !empty($doc->form()->settings()['open_to_all'])) {
            static::autoNumber($doc, $u);
        }
        if (!$doc->number) {
            $err[] = 'Choose a transaction number first.';
        }
        $err = array_merge($err, $doc->form()->checkSubmit($doc));
        if ($err) {
            static::fail($err);
        }
        if ($doc->state === 'done') {
            $doc->update(['edit_open' => false]);
            static::log($doc, 'Changes saved - locked again.', 'note', $u);
            \App\Services\Expenses::sync($doc->refresh());
            return;
        }
        $doc->update(['state' => 'done', 'edit_open' => false, 'current_signer_id' => null, 'submitted_at' => $doc->submitted_at ?? now(), 'done_at' => now()]);
        static::log($doc, 'Saved - final and locked.', 'done', $u);
        \App\Services\Expenses::sync($doc->refresh());
    }

    public static function cancel(Document $doc, User $u): void
    {
        if (!$u->hasPerm('documents.manage') && !($doc->canEdit($u))) {
            static::fail('Only the preparer (in draft) or a document manager can cancel.');
        }
        $doc->update(['state' => 'cancel', 'current_signer_id' => null]);
        \App\Support\Audit::log('cancel', $doc, 'Document cancelled', ['amount' => $doc->amount]);
        \App\Services\Expenses::sync($doc->refresh());
        static::log($doc, 'Cancelled.', 'cancel', $u);
    }

}
