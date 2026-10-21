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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

    public static function submit(Document $doc, User $u): void
    {
        if ($doc->state !== 'draft') {
            return;
        }
        if (!$doc->isOwner($u) && !$u->hasPerm('form.' . $doc->form_code . '.edit')) {
            static::fail('Only the preparer can submit this form.');
        }
        $err = [];
        if (!$doc->number) {
            $err[] = 'Reserve a transaction number first.';
        }
        $err = array_merge($err, $doc->form()->checkSubmit($doc));
        $doc->load('signatures.individual');
        $prep = $doc->signatures->firstWhere('is_preparer', true);
        if ($prep && !$prep->individual_id) {
            $err[] = 'Your login is not linked to an employee code - ask the administrator to link you (Users), so your signature can be added.';
        }
        $missing = $doc->signatures->filter(fn ($s) => $s->required && !$s->individual_id)->pluck('role')->all();
        if ($missing) {
            $err[] = 'Choose the signer of: ' . implode(', ', $missing) . '.';
        }
        foreach ($doc->activeSignatures() as $s) {
            if (!$s->signerUser()) {
                $err[] = $s->individual->label . ' (' . $s->role . ') has no login - ask the administrator to create a user for this employee.';
            }
        }
        if ($err) {
            static::fail($err);
        }
        DB::transaction(function () use ($doc, $u) {
            $doc->update(['state' => 'signing', 'submitted_at' => now()]);
            static::log($doc, 'Sent for signature.', 'submit', $u);
            foreach (\App\Models\ReviewComment::where('document_id', $doc->id)->where('status', '!=', 'closed')->get() as $n) {
                \App\Models\Alert::send($n->user_id, $doc->number . ' was corrected and sent again', $n->body,
                    route('documents.show', [$doc, 'tab' => 'review']) . '#lower', 'fa-paper-plane', $u->id);
            }
            $first = $doc->refresh()->nextSignature();
            if ($first && !$first->is_payer && ($first->is_preparer || $first->signerUser()?->id === $u->id)) {
                static::doSign($first, $first->signerUser() ?? $u);
                static::log($doc, 'Signed by ' . ($first->individual->name ?? $u->name) . ' as ' . $first->role . '.', 'sign', $u);
            }
            static::advance($doc->refresh());
        });
    }

    protected static function doSign(DocumentSignature $s, User $signer): void
    {
        $s->update([
            'state' => 'signed', 'signature' => $signer->signature, 'signed_by' => $signer->id, 'signed_at' => now(),
            'date' => $s->has_date ? now()->toDateString() : null,
        ]);
    }

    /** Next signer / payment / final. */
    public static function advance(Document $doc): void
    {
        $doc->load('signatures.individual');
        $next = $doc->nextSignature();
        if (!$next) {
            $doc->update(['state' => 'done', 'current_signer_id' => null, 'done_at' => now()]);
            static::log($doc, 'All signatures complete - transaction final.', 'done');
            static::notify($doc->user, $doc, 'is final');
            \App\Services\Advances::onDocumentDone($doc->refresh());
            return;
        }
        $user = $next->signerUser();
        $vals = ['current_signer_id' => $user?->id];
        if ($next->is_payer && $doc->state !== 'to_pay') {
            $vals['state'] = 'to_pay';
            static::log($doc, 'Fully signed - complete to accruals, waiting for payment by ' . ($next->individual->name ?? '') . '.', 'to_pay');
        }
        $doc->update($vals);
        if ($user) {
            static::notify($user, $doc, $next->is_payer ? 'is waiting for your payment' : 'is waiting for your signature');
        }
    }

    public static function sign(Document $doc, User $u): void
    {
        $doc->load('signatures.individual');
        $next = $doc->nextSignature();
        if (!in_array($doc->state, ['signing', 'to_pay'], true) || !$next || $doc->current_signer_id !== $u->id) {
            static::fail('It is not your turn to sign this document.');
        }
        if ($next->is_payer && (!$doc->paid_reservation_id || !$doc->paid_amount)) {
            static::fail('Fill the payment details first: reserve the payment number and enter the paid amount.');
        }
        if (!$u->signature) {
            static::fail('Add your signature image first (My Signature).');
        }
        DB::transaction(function () use ($doc, $u, $next) {
            static::doSign($next, $u);
            static::log($doc, 'Signed by ' . $u->name . ' as ' . $next->role . '.', 'sign', $u);
            static::advance($doc->refresh());
        });
    }

    public static function returnToPreparer(Document $doc, User $u, string $reason): void
    {
        if (!$doc->canSign($u) && !($u->hasPerm('documents.manage') && in_array($doc->state, ['signing', 'to_pay'], true))
            && !($u->hasPerm('review.access') && in_array($doc->state, ['signing', 'to_pay', 'done'], true))) {
            static::fail('Only the current signer can return the document.');
        }
        DB::transaction(function () use ($doc, $u, $reason) {
            $doc->signatures()->update(['state' => 'pending', 'signature' => null, 'signed_at' => null, 'signed_by' => null, 'date' => null]);
            $doc->update(['state' => 'draft', 'current_signer_id' => null, 'done_at' => null]);
            static::log($doc, 'Returned to the preparer by ' . $u->name . ': ' . $reason, 'return', $u);
        });
        static::notify($doc->user, $doc, 'was returned to you: ' . $reason);
    }

    public static function resetDraft(Document $doc, User $u): void
    {
        if (!$u->hasPerm('documents.manage')) {
            static::fail('You cannot reset documents to draft.');
        }
        $doc->signatures()->update(['state' => 'pending', 'signature' => null, 'signed_at' => null, 'signed_by' => null, 'date' => null]);
        $doc->update(['state' => 'draft', 'current_signer_id' => null, 'done_at' => null]);
        static::log($doc, 'Reset to draft.', 'reset', $u);
    }

    public static function cancel(Document $doc, User $u): void
    {
        if (!$u->hasPerm('documents.manage') && !($doc->canEdit($u))) {
            static::fail('Only the preparer (in draft) or a document manager can cancel.');
        }
        $doc->update(['state' => 'cancel', 'current_signer_id' => null]);
        static::log($doc, 'Cancelled.', 'cancel', $u);
    }

    /** Payment step: link the reserved payment number + amounts. */
    public static function savePayment(Document $doc, User $u, array $in): void
    {
        if (!$doc->isPayStep($u)) {
            static::fail('Only the treasury / cashier of this document can fill the payment.');
        }
        $resId = $in['paid_reservation_id'] ?? null;
        DB::transaction(function () use ($doc, $u, $in, $resId) {
            if ($resId && (int) $resId !== (int) $doc->paid_reservation_id) {
                $res = Reservation::findOrFail($resId);
                if ($res->state !== 'reserved' || ($res->user_id !== $u->id && !$u->hasPerm('numbers.manage'))) {
                    static::fail('Pick one of your free reserved numbers.');
                }
                $doc->paidReservation?->release();
                $res->markUsed($doc, 'Payment of ' . $doc->number);
                $doc->paid_reservation_id = $res->id;
                $doc->paid_ref = $res->number;
                static::log($doc, 'Payment number ' . $res->number . ' linked.', 'payment', $u);
            }
            $doc->paid_amount = $in['paid_amount'] !== null && $in['paid_amount'] !== '' ? (float) $in['paid_amount'] : null;
            $doc->paid_rate = $in['paid_rate'] !== null && $in['paid_rate'] !== '' ? (float) $in['paid_rate'] : null;
            $doc->paid_date = $in['paid_date'] ?: null;
            $doc->save();
        });
    }

    public static function notify(?User $to, Document $doc, string $what): void
    {
        if (!$to) {
            return;
        }
        $icon = str_contains($what, 'returned') ? 'fa-rotate-left' : (str_contains($what, 'final') ? 'fa-flag-checkered' : (str_contains($what, 'payment') ? 'fa-money-bill-wave' : 'fa-signature'));
        \App\Models\Alert::send($to, ($doc->number ?: $doc->form()->short) . ' ' . $what, $doc->description, route('documents.show', $doc), $icon);
        if (!$to->email || config('mail.default') === 'log') {
            return;
        }
        try {
            Mail::raw(sprintf("%s %s.\n\n%s", $doc->title, $what, route('documents.show', $doc)), function ($m) use ($to, $doc, $what) {
                $m->to($to->email)->subject($doc->title . ' ' . $what);
            });
        } catch (\Throwable $e) {
            Log::warning('notify failed: ' . $e->getMessage());
        }
    }

}
