<?php

namespace App\Http\Controllers;

use App\Models\Advance;
use App\Models\AdvanceExpense;
use App\Models\AdvanceHolder;
use App\Models\AdvancePayment;
use App\Models\AdvanceSettlement;
use App\Models\Alert;
use App\Models\Attachment;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Reservation;
use App\Models\Setting;
use App\Services\Advances;
use App\Services\Files;
use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Operating advances: holder portal (expenses, settlements, payment proofs) and the finance tracker. */
class AdvanceController extends Controller
{
    protected function manage(Request $r): void
    {
        abort_unless($r->user()->hasPerm('advances.manage'), 403, 'You cannot manage advances.');
    }

    protected function canSee(Request $r): bool
    {
        return $r->user()->hasPerm('advances.manage') || $r->user()->hasPerm('advances.view');
    }

    protected function myHolder(Request $r): ?AdvanceHolder
    {
        return AdvanceHolder::where('user_id', $r->user()->id)->where('active', true)->first();
    }

    protected function ownsHolder(Request $r, AdvanceHolder $h): bool
    {
        return $h->user_id === $r->user()->id;
    }

    // ------------------------------------------------------------------ holder portal
    public function mine(Request $r)
    {
        $h = $this->myHolder($r);
        abort_unless($h || $r->user()->hasPerm('advances.hold'), 403);
        if (!$h) {
            return view('advances.mine', ['holder' => null]);
        }
        $h->load(['advances' => fn ($q) => $q->orderByRaw("status = 'open' desc")->orderByDesc('granted_at'), 'advances.expenses', 'advances.payments.attachments', 'advances.payments.document', 'advances.document', 'account']);
        $loose = AdvanceExpense::where('holder_id', $h->id)->whereNull('advance_id')->whereNotIn('status', ['settled'])->with('attachments', 'supplier', 'budgetLine')->orderByDesc('date')->get();
        $settlements = AdvanceSettlement::where('holder_id', $h->id)->with(['expenses', 'document', 'advance'])->latest('id')->limit(15)->get();
        return view('advances.mine', ['holder' => $h, 'totals' => $h->totals(), 'loose' => $loose, 'settlements' => $settlements,
            'currencies' => Currency::where('active', true)->orderBy('sequence')->pluck('code')]);
    }

    protected function expenseRules(): array
    {
        [$arr, $file] = Files::rules();
        return ['advance_id' => 'nullable|integer|exists:advances,id', 'date' => 'required|date', 'invoice_no' => 'nullable|string|max:80',
            'invoice_date' => 'nullable|date', 'supplier_id' => 'nullable|integer|exists:suppliers,id', 'supplier_text' => 'nullable|string|max:190',
            'budget_line_id' => 'nullable|integer|exists:budget_lines,id', 'description' => 'required|string|max:1000',
            'amount' => 'required|numeric|min:0.01', 'currency' => 'nullable|string|max:10', 'grn' => 'nullable|in:Yes,No',
            'files' => $arr, 'files.*' => $file];
    }

    public function storeExpense(Request $r)
    {
        $h = $this->myHolder($r);
        abort_unless($h, 403, 'You are not an advance holder - ask finance to register you.');
        $v = $r->validate($this->expenseRules(), Files::messages());
        $adv = !empty($v['advance_id']) ? Advance::where('holder_id', $h->id)->where('status', 'open')->find($v['advance_id']) : null;
        if (!empty($v['advance_id']) && !$adv) {
            return back()->withErrors(['advance_id' => 'Choose one of your open advances.'])->withInput();
        }
        DB::transaction(function () use ($r, $v, $h, $adv) {
            $e = AdvanceExpense::create(collect($v)->except('files')->all() + ['holder_id' => $h->id, 'advance_id' => $adv?->id,
                'currency' => $v['currency'] ?? $adv?->currency, 'grn' => $v['grn'] ?? 'Yes', 'status' => 'draft', 'created_by' => $r->user()->id]);
            foreach ($r->file('files', []) as $f) {
                Attachment::store($f, ['expense_id' => $e->id, 'kind' => 'invoice'], $r->user(), 'expenses/' . $e->id);
            }
        });
        return back()->with('ok', 'Expense recorded' . ($adv ? ' on ' . $adv->number : ' (without advance)') . '.');
    }

    public function updateExpense(Request $r, AdvanceExpense $expense)
    {
        abort_unless($this->ownsHolder($r, $expense->holder) && $expense->editable(), 403);
        $v = $r->validate($this->expenseRules(), Files::messages());
        $adv = !empty($v['advance_id']) ? Advance::where('holder_id', $expense->holder_id)->where('status', 'open')->find($v['advance_id']) : null;
        DB::transaction(function () use ($r, $v, $expense, $adv) {
            $expense->update(collect($v)->except('files')->all() + ['advance_id' => $adv?->id, 'grn' => $v['grn'] ?? 'Yes',
                'status' => 'draft', 'settlement_id' => $expense->status === 'returned' ? null : $expense->settlement_id]);
            foreach ($r->file('files', []) as $f) {
                Attachment::store($f, ['expense_id' => $expense->id, 'kind' => 'invoice'], $r->user(), 'expenses/' . $expense->id);
            }
        });
        return back()->with('ok', 'Expense saved.');
    }

    public function destroyExpense(Request $r, AdvanceExpense $expense)
    {
        abort_unless($this->ownsHolder($r, $expense->holder) && $expense->editable(), 403);
        foreach ($expense->attachments as $a) {
            Storage::disk('local')->delete($a->path);
        }
        $expense->delete();
        return back()->with('ok', 'Expense deleted.');
    }

    /** Holder sends a batch of expenses to finance (one advance, or expenses without advance). */
    public function submitSettlement(Request $r)
    {
        $h = $this->myHolder($r);
        abort_unless($h, 403);
        $v = $r->validate(['expenses' => 'required|array|min:1', 'expenses.*' => 'integer', 'note' => 'nullable|string|max:1000']);
        $exp = AdvanceExpense::where('holder_id', $h->id)->whereIn('id', $v['expenses'])->whereIn('status', ['draft', 'returned'])->get();
        if (!$exp->count()) {
            return back()->withErrors(['expenses' => 'Choose expenses that are not sent yet.']);
        }
        if ($exp->pluck('advance_id')->unique()->count() > 1) {
            return back()->withErrors(['expenses' => 'Send the expenses of one advance at a time (expenses without advance separately).']);
        }
        if ($exp->contains(fn ($e) => !$e->budget_line_id)) {
            return back()->withErrors(['expenses' => 'Every expense needs its budget line before it is sent.']);
        }
        $set = DB::transaction(function () use ($r, $h, $exp, $v) {
            $set = AdvanceSettlement::create(['number' => Advance::nextNumber('SET', AdvanceSettlement::class, (int) date('Y')), 'holder_id' => $h->id,
                'advance_id' => $exp->first()->advance_id, 'status' => 'submitted', 'note' => $v['note'] ?? null,
                'submitted_by' => $r->user()->id, 'submitted_at' => now()]);
            AdvanceExpense::whereIn('id', $exp->pluck('id'))->update(['status' => 'submitted', 'settlement_id' => $set->id]);
            return $set;
        });
        Alert::send('advances.manage', 'Settlement ' . $set->number . ' from ' . $h->name, number_format($exp->sum('amount'), 2) . ' · ' . $exp->count() . ' invoice(s)',
            route('settlements.show', $set), 'fa-file-invoice-dollar', $r->user()->id);
        return back()->with('ok', 'Settlement ' . $set->number . ' sent to finance.');
    }

    /** Holder attaches the payment proof of a linked transaction (also copied to the transaction's file). */
    public function proof(Request $r, AdvancePayment $payment)
    {
        $adv = $payment->advance;
        abort_unless($this->ownsHolder($r, $adv->holder) || $r->user()->hasPerm('advances.manage'), 403);
        [$arr, $file] = Files::rules(true);
        $v = $r->validate(['files' => $arr, 'files.*' => $file, 'amount' => 'nullable|numeric', 'note' => 'nullable|string|max:500'], Files::messages());
        DB::transaction(function () use ($r, $payment, $v) {
            foreach ($r->file('files', []) as $f) {
                $a = Attachment::store($f, ['advance_payment_id' => $payment->id, 'kind' => 'payment proof'], $r->user(), 'payments/' . $payment->id);
                if ($payment->document) {
                    $a->copyTo($payment->document, 'payment proof ' . $payment->advance->number);
                    Workflow::log($payment->document, 'Payment proof added by ' . $r->user()->name . ' (advance ' . $payment->advance->number . ').', 'attach', $r->user());
                }
            }
            $payment->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $r->user()->id,
                'amount' => $v['amount'] ?? $payment->amount, 'note' => $v['note'] ?? $payment->note]);
        });
        Alert::send('advances.manage', $payment->reference . ' paid by ' . $r->user()->name, 'Advance ' . $adv->number . ' · proof attached',
            route('advances.show', $adv), 'fa-receipt', $r->user()->id);
        return back()->with('ok', 'Proof attached - ' . $payment->reference . ' marked as paid.');
    }

    // ------------------------------------------------------------------ finance tracker
    public function index(Request $r)
    {
        abort_unless($this->canSee($r), 403);
        $holders = AdvanceHolder::with(['advances.expenses', 'advances.payments', 'account', 'user'])->orderByDesc('active')->orderBy('name')->get();
        $q = Advance::with(['holder', 'document', 'expenses', 'payments'])->orderByRaw("status = 'open' desc")->orderBy('due_date');
        if ($r->filled('holder')) {
            $q->where('holder_id', $r->input('holder'));
        }
        $status = $r->input('status', 'open');
        if ($status === 'overdue') {
            $q->where('status', 'open')->whereDate('due_date', '<', today());
        } elseif ($status !== 'all') {
            $q->where('status', $status);
        }
        $advances = $q->paginate(30)->withQueryString();
        $settlements = AdvanceSettlement::with(['holder', 'expenses', 'advance'])->whereIn('status', ['submitted', 'accepted'])->latest('id')->get();
        $sum = AdvanceHolder::sumAdvances($holders->flatMap->advances);
        $byAccount = $holders->groupBy(fn ($h) => $h->account ? $h->account->code . ' - ' . $h->account->name : 'No account');
        return view('advances.index', compact('holders', 'advances', 'settlements', 'sum', 'status', 'byAccount') + [
            'currencies' => Currency::where('active', true)->orderBy('sequence')->pluck('code')]);
    }

    public function show(Request $r, Advance $advance)
    {
        abort_unless($this->canSee($r) || $this->ownsHolder($r, $advance->holder), 403);
        $advance->load(['holder.account', 'document', 'expenses.attachments', 'expenses.budgetLine', 'expenses.supplier', 'settlements.document', 'payments.attachments', 'payments.document', 'creator']);
        return view('advances.show', ['a' => $advance, 'f' => $advance->figures()]);
    }

    public function store(Request $r)
    {
        $this->manage($r);
        $v = $r->validate(['holder_id' => 'required|exists:advance_holders,id', 'kind' => 'required|in:operating,payments',
            'amount' => 'required|numeric|min:0.01', 'currency' => 'required|string|max:10', 'granted_at' => 'required|date',
            'due_date' => 'nullable|date', 'description' => 'nullable|string|max:1000', 'document_number' => 'nullable|string|max:20',
            'references' => 'nullable|string|max:2000']);
        $doc = !empty($v['document_number']) ? Document::where('number', trim($v['document_number']))->first() : null;
        if (!empty($v['document_number']) && !$doc) {
            return back()->withErrors(['document_number' => 'No document with the number ' . $v['document_number'] . '.'])->withInput();
        }
        $adv = Advance::make(collect($v)->except(['document_number', 'references'])->all() + ['document_id' => $doc?->id, 'created_by' => $r->user()->id]);
        if ($v['kind'] === 'payments' && !empty($v['references'])) {
            $this->linkReferences($adv, $v['references']);
        }
        if ($adv->holder->user_id) {
            Alert::send($adv->holder->user_id, 'Advance ' . $adv->number . ' is open on your account', number_format($adv->amount, 2) . ' ' . $adv->currency,
                route('advances.mine'), 'fa-hand-holding-dollar');
        }
        return redirect()->route('advances.show', $adv)->with('ok', 'Advance ' . $adv->number . ' opened.');
    }

    protected function linkReferences(Advance $adv, string $refs): int
    {
        $n = 0;
        foreach (preg_split('/[\s,;]+/', strtoupper($refs)) as $ref) {
            $ref = trim($ref);
            if ($ref === '' || $adv->payments()->where('reference', $ref)->exists()) {
                continue;
            }
            $doc = Document::where('number', $ref)->first();
            $res = $doc ? null : Reservation::where('number', $ref)->first();
            $adv->payments()->create(['reference' => $ref, 'document_id' => $doc?->id, 'amount' => $doc?->amount,
                'description' => $doc?->description ?? $res?->description, 'status' => 'pending']);
            $n++;
        }
        return $n;
    }

    public function addPayments(Request $r, Advance $advance)
    {
        $this->manage($r);
        $v = $r->validate(['references' => 'required|string|max:2000']);
        $n = $this->linkReferences($advance, $v['references']);
        if ($advance->holder->user_id && $n) {
            Alert::send($advance->holder->user_id, $n . ' transaction(s) to pay from ' . $advance->number, 'Attach the payment proof of each one.', route('advances.mine'), 'fa-list-check');
        }
        return back()->with('ok', $n . ' transaction(s) linked.');
    }

    public function removePayment(Request $r, AdvancePayment $payment)
    {
        $this->manage($r);
        abort_if($payment->status === 'paid', 422, 'This transaction is already paid.');
        $payment->delete();
        return back()->with('ok', 'Transaction removed from the advance.');
    }

    public function update(Request $r, Advance $advance)
    {
        $this->manage($r);
        $v = $r->validate(['due_date' => 'nullable|date', 'refunded' => 'nullable|numeric|min:0', 'description' => 'nullable|string|max:1000', 'close' => 'nullable|boolean', 'reopen' => 'nullable|boolean']);
        $vals = ['due_date' => $v['due_date'] ?? null, 'refunded' => (float) ($v['refunded'] ?? 0), 'description' => $v['description'] ?? $advance->description];
        if ($r->boolean('close')) {
            $vals += ['status' => 'closed', 'closed_at' => now()];
        } elseif ($r->boolean('reopen')) {
            $vals += ['status' => 'open', 'closed_at' => null];
        }
        $advance->update($vals);
        return back()->with('ok', $r->boolean('close') ? 'Advance closed.' : 'Advance saved.');
    }

    public function settlement(Request $r, AdvanceSettlement $settlement)
    {
        abort_unless($this->canSee($r) || $this->ownsHolder($r, $settlement->holder), 403);
        $settlement->load(['holder', 'advance.document', 'expenses.attachments', 'expenses.budgetLine', 'expenses.supplier', 'document', 'submitter']);
        return view('advances.settlement', ['s' => $settlement]);
    }

    public function accept(Request $r, AdvanceSettlement $settlement)
    {
        $this->manage($r);
        abort_unless($settlement->status === 'submitted', 422, 'This settlement was already handled.');
        $notes = array_map(fn ($x) => trim((string) $x), (array) $r->input('note', []));
        foreach ($settlement->expenses as $e) {
            if (!empty($notes[$e->id])) {
                $e->update(['description' => $notes[$e->id]]);
            }
        }
        $doc = Advances::createJar($settlement->fresh(), $r->user(), $notes);
        if ($settlement->holder->user_id) {
            Alert::send($settlement->holder->user_id, 'Settlement ' . $settlement->number . ' accepted', 'Finance is preparing the JAR.', route('advances.mine'), 'fa-circle-check');
        }
        return redirect()->route('documents.show', $doc)->with('ok', 'JAR prepared from ' . $settlement->number . ' - reserve its number, check and submit.');
    }

    public function returnSettlement(Request $r, AdvanceSettlement $settlement)
    {
        $this->manage($r);
        $v = $r->validate(['reason' => 'required|string|max:1000']);
        abort_unless($settlement->status === 'submitted', 422);
        DB::transaction(function () use ($settlement, $v) {
            $settlement->update(['status' => 'returned', 'return_reason' => $v['reason']]);
            $settlement->expenses()->update(['status' => 'returned', 'finance_note' => $v['reason']]);
        });
        if ($settlement->holder->user_id) {
            Alert::send($settlement->holder->user_id, 'Settlement ' . $settlement->number . ' returned', $v['reason'], route('advances.mine'), 'fa-rotate-left');
        }
        return redirect()->route('advances.index')->with('ok', 'Returned to ' . $settlement->holder->name . '.');
    }

    // ------------------------------------------------------------------ holders
    public function storeHolder(Request $r)
    {
        $this->manage($r);
        $v = $r->validate($this->holderRules());
        $v['active'] = true;
        $h = AdvanceHolder::create($v);
        if ($h->user && !$h->user->hasPerm('advances.hold')) {
            $role = \App\Models\Role::where('name', 'Logistics')->first();
            if ($role) {
                $h->user->roles()->syncWithoutDetaching([$role->id]);
            }
        }
        return back()->with('ok', 'Holder ' . $h->name . ' added.');
    }

    public function updateHolder(Request $r, AdvanceHolder $holder)
    {
        $this->manage($r);
        $v = $r->validate($this->holderRules($holder));
        $v['active'] = $r->boolean('active');
        $holder->update($v);
        return back()->with('ok', 'Holder saved.');
    }

    protected function holderRules(?AdvanceHolder $h = null): array
    {
        return ['name' => 'required|string|max:120', 'kind' => 'required|in:person,cashbox',
            'user_id' => ['nullable', 'integer', 'exists:users,id', \Illuminate\Validation\Rule::unique('advance_holders', 'user_id')->ignore($h?->id)],
            'individual_id' => 'nullable|integer|exists:individuals,id', 'account_id' => 'nullable|integer|exists:accounts,id',
            'currency_id' => 'nullable|integer|exists:currencies,id', 'notes' => 'nullable|string|max:500'];
    }
}
