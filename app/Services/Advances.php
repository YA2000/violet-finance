<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\AdvanceExpense;
use App\Models\AdvanceSettlement;
use App\Models\Alert;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Formula\Evaluator;
use Illuminate\Support\Facades\DB;

/** Operating advances: opened from a final Advance Application, cleared through JAR. */
class Advances
{
    /** Called when any document becomes final. */
    public static function onDocumentDone(Document $doc): void
    {
        $form = $doc->form();
        $s = $form->settings();
        // 1) advance application -> open an advance on the holder
        if (!empty($s['advance']['holder'])) {
            $d = $doc->data;
            $holderId = $d[$s['advance']['holder']] ?? null;
            if ($holderId && !Advance::where('document_id', $doc->id)->exists()) {
                $curField = $s['advance']['currency'] ?? null;
                $cur = $curField ? ($d[$curField] ?? null) : null;
                $code = is_numeric($cur) ? Currency::find($cur)?->code : $cur;
                $amount = Evaluator::num($d[$s['advance']['amount'] ?? 'amount'] ?? $doc->amount);
                $adv = Advance::make([
                    'holder_id' => $holderId, 'document_id' => $doc->id, 'kind' => 'operating', 'amount' => $amount,
                    'currency' => $code ?: $doc->currency, 'granted_at' => ($doc->paid_date ?? now())->toDateString(),
                    'due_date' => $d[$s['advance']['due'] ?? 'settlement_due'] ?? null, 'description' => $doc->description,
                    'created_by' => $doc->user_id,
                ]);
                Workflow::log($doc, 'Advance ' . $adv->number . ' opened on ' . $adv->holder->name . '.', 'note');
                if ($adv->holder->user_id) {
                    Alert::send($adv->holder->user_id, 'Advance ' . $adv->number . ' is open on your account',
                        number_format($amount, 2) . ' ' . $adv->currency . ($adv->due_date ? ' · clear it by ' . $adv->due_date->format('d/m/Y') : ''),
                        route('advances.mine'), 'fa-hand-holding-dollar');
                }
            }
        }
        // 2) JAR of a settlement -> the expenses are cleared
        foreach (AdvanceSettlement::where('document_id', $doc->id)->get() as $set) {
            DB::transaction(function () use ($set) {
                $set->update(['status' => 'settled']);
                $set->expenses()->update(['status' => 'settled']);
            });
            if ($set->holder->user_id) {
                Alert::send($set->holder->user_id, 'Settlement ' . $set->number . ' is cleared', 'JAR ' . $doc->number . ' is final.',
                    route('advances.mine'), 'fa-flag-checkered');
            }
        }
    }

    /** Finance accepts a settlement: a JAR draft is prepared from its expenses (finance becomes the preparer). */
    public static function createJar(AdvanceSettlement $set, User $u, array $notes = []): Document
    {
        $code = \App\Models\FormType::whereNotNull('schema')->get()->first(fn ($t) => !empty($t->schema['settings']['settlement_target']))?->code ?? 'jar';
        $form = \App\Forms\FormRegistry::get($code);
        $adv = $set->advance;
        $set->load('expenses.attachments');
        $cur = $set->expenses->first()?->currency ?? $adv?->currency;
        $lines = [];
        foreach ($set->expenses as $e) {
            $lines[] = array_filter([
                'budget_line_id' => $e->budget_line_id, 'paid_amount' => $e->amount,
                'special_note' => $notes[$e->id] ?? $e->description, 'grn' => $e->grn ?: 'Yes', 'invoice_no' => $e->invoice_no,
                'invoice_date' => $e->invoice_date?->toDateString() ?? $e->date->toDateString(), 'supplier_id' => $e->supplier_id,
            ], fn ($v) => $v !== null && $v !== '');
        }
        $data = [
            'date' => date('Y-m-d'),
            'advance_ref' => $adv ? ($adv->document?->number ?: $adv->number) : 'Expenses without advance - ' . $set->holder->name,
            'advance_date' => $adv?->granted_at?->toDateString(),
            'currency_id' => Currency::where('code', $cur)->value('id'),
            'advance_amount' => $adv?->amount ?? $set->total(),
            'advance_rate' => 1, 'factor' => 'mul', 'invoice_rate' => 1,
            'lines' => $lines,
        ];
        return DB::transaction(function () use ($form, $data, $u, $set) {
            $doc = Workflow::save(new Document(['form_code' => $form->code]), $data, [], $u);
            foreach ($set->expenses as $e) {
                foreach ($e->attachments as $a) {
                    $a->copyTo($doc, 'invoice ' . ($e->invoice_no ?: '#' . $e->id));
                }
                $e->update(['status' => 'in_jar', 'finance_note' => null]);
            }
            $set->update(['status' => 'accepted', 'document_id' => $doc->id, 'accepted_by' => $u->id]);
            Workflow::log($doc, 'Prepared from settlement ' . $set->number . ' of ' . $set->holder->name . '.', 'note', $u);
            return $doc;
        });
    }
}
