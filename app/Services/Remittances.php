<?php

namespace App\Services;

use App\Forms\FormRegistry;
use App\Models\Alert;
use App\Models\Attachment;
use App\Models\Currency;
use App\Models\Document;
use App\Models\FormType;
use App\Models\RemittanceItem;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\TxnType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payment through Remittance Statements:
 *   fully signed transactions (waiting only for the treasury) -> the internal viewer lists them on a Remittance Statement
 *   -> the statement goes through its own signatures -> the treasury pays its items, one or several at a time
 *   -> each paid transaction becomes final with its payment details and proof; the statement closes when all are paid.
 */
class Remittances
{
    public const FORM = 'remittance';

    public static function enabled(): bool
    {
        return Setting::get('pay_through_remittance') !== '0' && FormType::byCode(static::FORM);
    }

    /** Forms paid through remittances: forms with a payment step, except the statement itself. */
    public static function payableForms(): array
    {
        return collect(FormRegistry::all())->filter(fn ($f) => $f->hasPayment && $f->code !== static::FORM)->map(fn ($f) => $f->code)->values()->all();
    }

    /** The open (not cancelled) item of a transaction, if it is on a statement. */
    public static function itemOf(Document $doc): ?RemittanceItem
    {
        return RemittanceItem::where('document_id', $doc->id)
            ->whereHas('remittance', fn ($q) => $q->where('state', '!=', 'cancelled'))->with('remittance')->latest('id')->first();
    }

    /** Transactions waiting for payment and on no statement yet; an internal viewer sees the ones he signed. */
    public static function ready(User $u): Collection
    {
        $q = Document::where('state', 'to_pay')->whereIn('form_code', static::payableForms())
            ->whereDoesntHave('remittanceItems', fn ($i) => $i->whereHas('remittance', fn ($r) => $r->where('state', '!=', 'cancelled')))
            ->with(['signatures.individual', 'user'])->orderBy('number');
        if (!$u->hasPerm('remittance.all')) {
            // the ones he signed, and the ones no internal viewer signed (otherwise nobody could ever list them)
            $viewers = User::where('active', true)->get()->filter(fn ($x) => $x->hasPerm('remittance.prepare'))->pluck('id')->all();
            $q->where(fn ($w) => $w->whereHas('signatures', fn ($s) => $s->where('signed_by', $u->id)->where('is_preparer', false))
                ->orWhereDoesntHave('signatures', fn ($s) => $s->whereIn('signed_by', $viewers)->where('is_preparer', false)));
        }
        return $q->get();
    }

    /** Payment lines of a transaction for the statement: one per recipient when the form has recipients on its lines. */
    public static function linesFor(Document $doc): array
    {
        $d = $doc->data ?? [];
        $out = [];
        foreach ($d['lines'] ?? [] as $row) {
            if (!empty($row['recipient_id']) && isset($row['expected_amount'])) {
                $k = $row['recipient_id'];
                $out[$k] ??= ['bill_ref' => $doc->number, 'recipient_id' => $k, 'amount' => 0.0,
                    'project_id' => static::projectOf($row['budget_line_id'] ?? null), 'note' => $doc->description];
                $out[$k]['amount'] += (float) $row['expected_amount'];
            }
        }
        if ($out) {
            return array_values(array_map(fn ($l) => ['amount' => round($l['amount'], 2)] + $l, $out));
        }
        return [array_filter(['bill_ref' => $doc->number, 'amount' => round((float) $doc->amount, 2),
            'recipient_id' => $d['recipient_id'] ?? $d['payee_id'] ?? null, 'note' => $doc->description,
            'project_id' => $d['project_id'] ?? static::projectOf($d['budget_line_id'] ?? null)], fn ($v) => $v !== null)];
    }

    protected static function projectOf($budgetLineId): ?int
    {
        return $budgetLineId ? \App\Models\BudgetLine::whereKey($budgetLineId)->value('project_id') : null;
    }

    /** Make the Remittance Statement (a draft the internal viewer then submits) from chosen transactions. */
    public static function create(array $ids, User $u): Document
    {
        $docs = static::ready($u)->whereIn('id', array_map('intval', $ids))->values();
        if (!$docs->count()) {
            Workflow::fail('Choose transactions that are ready to pay.');
        }
        if ($docs->pluck('currency')->unique()->count() > 1) {
            Workflow::fail('One statement is paid in one currency: choose transactions of the same payment currency.');
        }
        $cur = $docs->first()->currency;
        $curId = Currency::where('code', $cur)->value('id');
        $lines = [];
        foreach ($docs as $doc) {
            $lines = array_merge($lines, static::linesFor($doc));
        }
        $data = ['date' => date('Y-m-d'), 'invoice_currency_id' => $curId, 'payment_currency_id' => $curId,
            'remittance_type' => 'Payment of ' . $docs->count() . ' transaction' . ($docs->count() === 1 ? '' : 's'), 'lines' => $lines];
        return DB::transaction(function () use ($docs, $data, $u) {
            $rem = Workflow::save(new Document(['form_code' => static::FORM]), $data, [], $u);
            Workflow::autoNumber($rem, $u);
            foreach ($docs as $doc) {
                RemittanceItem::create(['remittance_id' => $rem->id, 'document_id' => $doc->id, 'currency' => $doc->currency, 'amount' => (float) $doc->amount]);
                Workflow::log($doc, 'Listed for payment on Remittance Statement ' . $rem->number . '.', 'note', $u);
            }
            Workflow::log($rem, 'Prepared from ' . $docs->pluck('number')->implode(', ') . '.', 'note', $u);
            return $rem;
        });
    }

    /** Statements fully signed and waiting for the treasury, with their unpaid items. */
    public static function payable(): Collection
    {
        return Document::where('form_code', static::FORM)->where('state', 'to_pay')
            ->with(['remittanceLines' => fn ($q) => $q->with('document.user')->orderBy('id')])->orderBy('number')->get();
    }

    /**
     * The treasury pays items (one or many): $rows = [item id => ['amount' => paid, 'note' => ...]].
     * Each transaction gets a payment number, the proof, the treasury signature and becomes final.
     */
    public static function pay(array $rows, string $date, array $files, User $u): int
    {
        $items = RemittanceItem::whereIn('id', array_keys($rows))->where('status', 'waiting')
            ->whereHas('remittance', fn ($q) => $q->where('state', 'to_pay'))->with(['document', 'remittance'])->get();
        if (!$items->count()) {
            Workflow::fail('Choose items of a fully signed Remittance Statement that are not paid yet.');
        }
        $type = TxnType::where('name', 'Bill Payment')->first() ?? TxnType::orderBy('id')->first();
        DB::transaction(function () use ($items, $rows, $date, $files, $u, $type) {
            // the proof files: stored once on the statement, copied to every paid transaction
            $proofs = [];
            foreach ($items->pluck('remittance')->unique('id') as $rem) {
                foreach ($files as $f) {
                    $proofs[$rem->id][] = Attachment::store($f, ['document_id' => $rem->id, 'kind' => 'payment proof'], $u, (string) $rem->id);
                }
            }
            foreach ($items as $it) {
                $doc = $it->document;
                $paid = round((float) ($rows[$it->id]['amount'] ?? $it->amount), 2);
                $note = trim((string) ($rows[$it->id]['note'] ?? ''));
                $res = Reservation::reserve(['date' => $date, 'txn_type_id' => $type->id,
                    'description' => 'Payment of ' . $doc->number . ' (' . $it->remittance->number . ')'], $u);
                $res->markUsed($doc, 'Payment of ' . $doc->number);
                $adjusted = abs($paid - $it->amount) >= 0.005;
                $doc->forceFill(['paid_reservation_id' => $res->id, 'paid_ref' => $res->number, 'paid_amount' => $paid, 'paid_date' => $date])->save();
                if ($adjusted) {
                    static::adjust($doc, $it->amount, $paid, $u);
                }
                foreach ($proofs[$it->remittance_id] ?? [] as $a) {
                    $a->copyTo($doc, 'payment proof');
                }
                $it->update(['status' => 'paid', 'paid_amount' => $paid, 'paid_date' => $date, 'paid_ref' => $res->number,
                    'paid_by' => $u->id, 'paid_at' => now(), 'adjusted' => $adjusted, 'note' => $note ?: null]);
                Workflow::log($doc, 'Paid ' . money($paid) . ' ' . $it->currency . ' (payment ' . $res->number . ', ' . $it->remittance->number . ').', 'payment', $u);
                static::signPayment($doc, $u);
            }
            foreach ($items->pluck('remittance')->unique('id') as $rem) {
                $rem->refresh();
                $left = $rem->remittanceLines()->where('status', 'waiting')->count();
                if (!$left) {
                    $total = (float) $rem->remittanceLines()->sum('paid_amount');
                    $rem->forceFill(['paid_amount' => $total, 'paid_date' => $date])->save();
                    Workflow::log($rem, 'Every transaction is paid.', 'payment', $u);
                    static::signPayment($rem, $u);
                } else {
                    Workflow::log($rem, $left . ' transaction(s) still to pay.', 'payment', $u);
                }
            }
        });
        return $items->count();
    }

    /** The treasury signature of a transaction, by the person who paid it. */
    protected static function signPayment(Document $doc, User $u): void
    {
        $doc->load('signatures.individual');
        $next = $doc->nextSignature();
        if (!$next || !$next->is_payer) {
            return;
        }
        if ($u->individual_id && $next->individual_id !== $u->individual_id) {
            $next->update(['individual_id' => $u->individual_id]);
        }
        Workflow::signAs($next, $u);
        Workflow::log($doc, 'Signed by ' . $u->name . ' as ' . $next->role . '.', 'sign', $u);
        Workflow::advance($doc->refresh());
    }

    /**
     * Paid more or less than expected: the transaction follows the real amount. On a PTR paid in another currency the
     * real exchange rate replaces the planned one, so the lines and totals match what left the bank.
     */
    protected static function adjust(Document $doc, float $planned, float $paid, User $u): void
    {
        $d = $doc->data ?? [];
        $form = $doc->form();
        $msg = 'Value adjusted from ' . money($planned) . ' to ' . money($paid) . ' based on the real exchange rate.';
        if ($doc->form_code === 'ptr' && ($d['payment_currency_id'] ?? null) && ($d['payment_currency_id'] ?? null) !== ($d['invoice_currency_id'] ?? null)
            && $planned > 0 && $paid > 0) {
            // paid in USD: the real rate is the invoice currency's (USD for 1 unit); otherwise the payment currency's
            $key = Currency::whereKey($d['payment_currency_id'])->value('code') === 'USD' ? 'home_rate' : 'pay_rate';
            if ((float) ($d[$key] ?? 0) > 0) {
                $d['planned_' . $key] = $d[$key];
                $d[$key] = $key === 'home_rate' ? round((float) $d[$key] * $paid / $planned, 7) : round((float) $d[$key] * $planned / $paid, 7);
                $msg .= ' ' . ($key === 'home_rate' ? 'Invoice' : 'Payment') . ' currency rate ' . $d['planned_' . $key] . ' -> ' . $d[$key] . '.';
            }
        }
        $d['payment_note'] = $msg;
        $d = $form->compute($d, $form::meta($doc));
        $doc->forceFill(['data' => $d, 'amount' => $form->amount($d) ?? $paid])->save();
        Workflow::log($doc, $msg, 'payment', $u);
    }

    /** Status line of a transaction for its page (Payment tab). */
    public static function statusOf(Document $doc): ?array
    {
        $it = static::itemOf($doc);
        if (!$it) {
            return null;
        }
        return ['item' => $it, 'remittance' => $it->remittance];
    }

    /** Alerts when a transaction becomes ready to pay or a statement is fully signed. */
    public static function onReadyToPay(Document $doc): void
    {
        if (!static::enabled()) {
            return;
        }
        if ($doc->form_code === static::FORM) {
            Alert::send('payments.pay', 'Remittance ' . $doc->number . ' is ready to pay', $doc->description ?? '',
                route('payments.index'), 'fa-money-bill-transfer');
        } elseif (in_array($doc->form_code, static::payableForms(), true)) {
            $signers = $doc->signatures()->whereNotNull('signed_by')->where('is_preparer', false)->pluck('signed_by')->all();
            $to = User::whereIn('id', $signers)->get()->filter(fn ($x) => $x->hasPerm('remittance.prepare'))->pluck('id')->all();
            Alert::send($to ?: 'remittance.prepare', $doc->number . ' is fully signed', 'Add it to a Remittance Statement.',
                route('remittances.index'), 'fa-list-check');
        }
    }
}
