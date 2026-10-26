<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\Contract;
use App\Models\Individual;
use App\Models\LoeEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Payroll;
use App\Services\PayrollExport;
use App\Services\PayrollImport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Unified payroll: monthly runs, contracts, LOE (HR and each employee). */
class PayrollController extends Controller
{
    protected function canSee(Request $r): void
    {
        abort_unless($r->user()->hasPerm('payroll.view') || $r->user()->hasPerm('payroll.manage'), 403);
    }

    // ================================================================ runs
    public function index(Request $r)
    {
        $this->canSee($r);
        $runs = PayrollRun::withCount('lines')->orderByDesc('period')->paginate(24);
        $sums = \App\Models\PayrollLine::whereIn('payroll_run_id', $runs->pluck('id'))->selectRaw('payroll_run_id, SUM(total_usd) as usd')
            ->groupBy('payroll_run_id')->pluck('usd', 'payroll_run_id');
        $core = Payroll::coreCode();
        $coreSums = \App\Models\PayrollAllocation::whereIn('payroll_run_id', $runs->pluck('id'))->where('project_code', $core)
            ->selectRaw('payroll_run_id, SUM(amount_usd) as usd')->groupBy('payroll_run_id')->pluck('usd', 'payroll_run_id');
        $next = PayrollRun::max('period') ? Payroll::period(PayrollRun::max('period'))->addMonth() : now()->startOfMonth();
        $kpi = [
            'contracts' => Contract::where('active', true)->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->count(),
            'ending' => Contract::where('active', true)->whereBetween('end_date', [today(), today()->addDays(30)])->count(),
        ];
        return view('payroll.index', compact('runs', 'sums', 'coreSums', 'next', 'kpi'));
    }

    public function store(Request $r)
    {
        $r->validate(['period' => 'required|date_format:Y-m']);
        $run = Payroll::open(Payroll::period($r->input('period')), $r->user());
        return redirect()->route('payroll.show', $run)->with('ok', 'Payroll of ' . $run->label() . ' ready: ' . $run->lines()->count() . ' lines from the contracts.');
    }

    public function show(Request $r, PayrollRun $run)
    {
        $this->canSee($r);
        $lines = $run->lines()->with(['individual', 'contract'])->get()->sortBy(fn ($l) => [$l->office, mb_strtolower($l->individual?->name ?? '')])->values();
        $problems = Payroll::check($run);
        $loe = Payroll::loe($run, $lines);
        return view('payroll.show', [
            'run' => $run, 'lines' => $lines, 'problems' => $problems, 'loe' => $loe,
            'totals' => Payroll::totals($run), 'byProject' => Payroll::byProject($run), 'fte' => Payroll::fte($run),
            'budget' => Payroll::budgetUse($run), 'tab' => $r->input('tab', 'lines'),
            'canManage' => $r->user()->hasPerm('payroll.manage'), 'canApprove' => $r->user()->hasPerm('payroll.approve'),
        ]);
    }

    public function update(Request $r, PayrollRun $run)
    {
        abort_unless($run->isDraft(), 422, 'The payroll is approved. Reopen it first.');
        $v = $r->validate(['rate_try' => 'nullable|numeric|min:0', 'rate_syp' => 'nullable|numeric|min:0', 'rate_eur' => 'nullable|numeric|min:0',
            'ref_number' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:2000']);
        $run->update($v);
        Payroll::recomputeAll($run);
        return back()->with('ok', 'Saved. Amounts in US dollars recalculated.');
    }

    public function refresh(Request $r, PayrollRun $run)
    {
        $x = Payroll::refresh($run);
        return back()->with('ok', "Lines updated from the contracts: {$x['added']} added, {$x['updated']} refreshed, {$x['removed']} removed.");
    }

    public function line(Request $r, PayrollRun $run, PayrollLine $line)
    {
        abort_unless($line->payroll_run_id === $run->id, 404);
        abort_unless($run->isDraft(), 422, 'The payroll is approved. Reopen it first.');
        $v = $r->validate(['units' => 'required|numeric|min:0', 'additions' => 'nullable|numeric', 'deductions' => 'nullable|numeric|min:0',
            'tr_insurance' => 'nullable|numeric|min:0', 'tr_tax' => 'nullable|numeric|min:0', 'tr_indirim' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:500']);
        $v = array_map(fn ($x) => $x ?? 0, $v);
        $v['notes'] = $r->input('notes');
        if (!$line->isTurkey()) {
            unset($v['tr_insurance'], $v['tr_tax'], $v['tr_indirim']);
        }
        $line->fill($v);
        $line->manual = true;
        Payroll::compute($line, $run);
        $line->save();
        Payroll::dropAllocation($run);
        return back()->with('ok', ($line->individual?->name ?? 'Line') . ' saved: ' . number_format($line->total_cost, 2) . ' ' . $line->currency . '.');
    }

    public function attendance(Request $r, PayrollRun $run)
    {
        abort_unless($run->isDraft(), 422, 'The payroll is approved. Reopen it first.');
        $r->validate(['file' => 'required|file|mimes:xlsx|max:5120']);
        [$n, $errors] = PayrollImport::attendance($run, $r->file('file')->getRealPath(), $r->user());
        return $errors ? back()->withErrors($errors) : back()->with('ok', "$n employees updated from the HR file (attendance and LOE).");
    }

    public function allocate(Request $r, PayrollRun $run)
    {
        try {
            $n = Payroll::allocate($run);
        } catch (\RuntimeException $e) {
            return redirect()->route('payroll.show', [$run, 'tab' => 'checks'])->withErrors(['allocate' => $e->getMessage()]);
        }
        return redirect()->route('payroll.show', [$run, 'tab' => 'projects'])->with('ok', "Allocation done: $n lines to budget lines.");
    }

    public function approve(Request $r, PayrollRun $run)
    {
        try {
            Payroll::approve($run, $r->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }
        return back()->with('ok', 'Payroll of ' . $run->label() . ' approved. The LOE of the month is now locked.');
    }

    public function reopen(Request $r, PayrollRun $run)
    {
        Payroll::reopen($run);
        return back()->with('ok', 'Payroll reopened as a draft.');
    }

    public function destroy(Request $r, PayrollRun $run)
    {
        abort_unless($run->isDraft(), 422, 'An approved payroll cannot be deleted.');
        \App\Models\PayrollAllocation::where('payroll_run_id', $run->id)->delete();
        $run->lines()->delete();
        $run->delete();
        return redirect()->route('payroll.index')->with('ok', 'Payroll deleted. The contracts and the LOE stay.');
    }

    public function export(Request $r, PayrollRun $run, string $kind)
    {
        $this->canSee($r);
        $tag = $run->period->format('Y-m');
        [$path, $name] = match ($kind) {
            'payroll' => [PayrollExport::payroll($run), "Payroll_$tag.xlsx"],
            'allocation' => [PayrollExport::allocation($run), "Payroll_Allocation_$tag.xlsx"],
            'uploader' => [PayrollExport::uploader($run), "Payroll_QB_Uploader_$tag.xlsx"],
            'fte' => [PayrollExport::fte($run), "FTE_by_project_$tag.xlsx"],
            'attendance' => [PayrollExport::attendanceTemplate($run), "HR_monthly_file_$tag.xlsx"],
            default => abort(404),
        };
        if (in_array($kind, ['allocation', 'uploader', 'fte'], true) && !$run->allocations()->exists()) {
            @unlink($path);
            return back()->withErrors(['export' => 'Run the allocation first.']);
        }
        return response()->download($path, $name)->deleteFileAfterSend();
    }

    // ================================================================ contracts
    public function contracts(Request $r)
    {
        $this->canSee($r);
        $q = Contract::with(['individual', 'defaultBudgetLine']);
        if ($r->filled('q')) {
            $s = '%' . $r->input('q') . '%';
            $q->where(fn ($w) => $w->where('position', 'like', $s)->orWhere('number', 'like', $s)
                ->orWhereHas('individual', fn ($i) => $i->where('code', 'like', $s)->orWhere('name', 'like', $s)->orWhere('name_ar', 'like', $s)));
        }
        if ($r->filled('office')) {
            $q->where('office', $r->input('office'));
        }
        $status = $r->input('status', 'current');
        if ($status === 'current') {
            $q->where('active', true)->whereDate('start_date', '<=', today()->endOfMonth())->where(fn ($w) => $w->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
        } elseif ($status === 'ending') {
            $q->where('active', true)->whereBetween('end_date', [today(), today()->addDays(30)]);
        } elseif ($status === 'ended') {
            $q->where(fn ($w) => $w->where('active', false)->orWhereDate('end_date', '<', today()));
        }
        $contracts = $q->orderByDesc('start_date')->paginate(40)->withQueryString();
        return view('payroll.contracts', ['contracts' => $contracts, 'status' => $status, 'canManage' => $r->user()->hasPerm('payroll.manage')]);
    }

    protected function contractRules(): array
    {
        return [
            'individual_id' => 'required|integer|exists:individuals,id',
            'number' => 'nullable|string|max:60', 'position' => 'nullable|string|max:190',
            'office' => ['required', Rule::in(array_keys(Contract::OFFICES))],
            'employment' => ['required', Rule::in(array_keys(Contract::EMPLOYMENT))],
            'pay_basis' => ['required', Rule::in(array_keys(Contract::BASIS))],
            'rate' => 'required|numeric|min:0', 'currency' => 'required|string|max:10', 'fte' => 'required|numeric|min:0.01|max:1',
            'start_date' => 'required|date', 'end_date' => 'nullable|date|after_or_equal:start_date',
            'default_budget_line_id' => 'nullable|integer|exists:budget_lines,id', 'notes' => 'nullable|string|max:2000',
        ];
    }

    public function storeContract(Request $r)
    {
        $v = $r->validate($this->contractRules());
        $v['currency'] = strtoupper($v['currency']);
        $c = Contract::create($v + ['active' => true]);
        return back()->with('ok', 'Contract of ' . $c->individual->name . ' saved.');
    }

    public function updateContract(Request $r, Contract $contract)
    {
        $v = $r->validate($this->contractRules() + ['active' => 'nullable|boolean']);
        $v['currency'] = strtoupper($v['currency']);
        $v['active'] = $r->boolean('active');
        $contract->update($v);
        return back()->with('ok', 'Contract updated. Open payroll drafts take it on "Update from contracts".');
    }

    /** Position or salary change: the current contract ends the day before, a new one starts. */
    public function changeContract(Request $r, Contract $contract)
    {
        $v = $r->validate(['from' => 'required|date|after:' . $contract->start_date->toDateString(), 'position' => 'nullable|string|max:190',
            'rate' => 'required|numeric|min:0', 'fte' => 'required|numeric|min:0.01|max:1', 'change_reason' => 'required|string|max:190']);
        $from = \Illuminate\Support\Carbon::parse($v['from']);
        if ($contract->end_date && $from->gt($contract->end_date)) {
            return back()->withErrors(['from' => 'The change date is after the end of the contract.']);
        }
        $new = $contract->replicate(['created_at', 'updated_at']);
        $new->fill(['start_date' => $from->toDateString(), 'position' => $v['position'] ?: $contract->position, 'rate' => $v['rate'], 'fte' => $v['fte'],
            'previous_id' => $contract->id, 'change_reason' => $v['change_reason']]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($contract, $new, $from) {
            $contract->update(['end_date' => $from->copy()->subDay()->toDateString()]);
            $new->save();
        });
        return back()->with('ok', 'Change recorded: new contract from ' . $from->format('d M Y') . '. The month of the change is paid by days on each contract.');
    }

    public function destroyContract(Request $r, Contract $contract)
    {
        if (PayrollLine::where('contract_id', $contract->id)->exists()) {
            return back()->withErrors(['contract' => 'This contract is in a payroll. End it or mark it inactive instead.']);
        }
        $contract->delete();
        return back()->with('ok', 'Contract deleted.');
    }

    public function importContracts(Request $r)
    {
        $r->validate(['file' => 'required|file|mimes:xlsx|max:5120']);
        [$n, $errors] = PayrollImport::contracts($r->file('file')->getRealPath());
        return $errors ? back()->withErrors($errors) : back()->with('ok', "$n contracts imported.");
    }

    public function contractsTemplate()
    {
        return response()->download(PayrollExport::contractsTemplate(), 'Contracts_Template.xlsx')->deleteFileAfterSend();
    }

    // ================================================================ LOE (HR)
    public function loe(Request $r)
    {
        $this->canSee($r);
        $period = Payroll::period($r->input('period'));
        $contracts = Contract::with('individual', 'defaultBudgetLine')->inMonth($period)->get()->groupBy('individual_id');
        $entries = LoeEntry::with(['budgetLine', 'user'])->whereDate('period', $period->toDateString())->get()->groupBy('individual_id');
        $ids = $contracts->keys()->merge($entries->keys())->unique();
        $people = Individual::whereIn('id', $ids)->get()->sortBy(fn ($i) => mb_strtolower($i->name));
        $rows = [];
        $counts = ['ok' => 0, 'partial' => 0, 'missing' => 0, 'no_contract' => 0];
        foreach ($people as $i) {
            $e = $entries[$i->id] ?? collect();
            $c = $contracts[$i->id] ?? collect();
            $sum = (float) $e->sum('percent');
            $default = $c->first()?->defaultBudgetLine;
            $state = !$c->count() ? 'no_contract' : ($e->count() ? (abs($sum - 100) <= Payroll::TOLERANCE ? 'ok' : 'partial') : ($default ? 'ok' : 'missing'));
            $counts[$state]++;
            $rows[] = ['i' => $i, 'entries' => $e, 'contract' => $c->first(), 'sum' => $e->count() ? $sum : ($default ? 100.0 : 0.0), 'state' => $state, 'default' => !$e->count() && $default];
        }
        if ($r->filled('state')) {
            $rows = array_values(array_filter($rows, fn ($x) => $x['state'] === $r->input('state')));
        }
        return view('payroll.loe', ['period' => $period, 'rows' => $rows, 'counts' => $counts, 'locked' => Payroll::loeLocked($period),
            'canManage' => $r->user()->hasPerm('payroll.manage')]);
    }

    public function loeEdit(Request $r, Individual $individual)
    {
        abort_unless($r->user()->hasPerm('payroll.manage'), 403);
        $period = Payroll::period($r->input('period'));
        return view('payroll.loe_edit', $this->loeForm($individual, $period) + ['action' => route('payroll.loe.save', $individual), 'self' => false]);
    }

    public function loeSave(Request $r, Individual $individual)
    {
        abort_unless($r->user()->hasPerm('payroll.manage'), 403);
        return $this->saveLoe($r, $individual, 'hr', route('payroll.loe', ['period' => Payroll::period($r->input('period'))->format('Y-m')]));
    }

    public function loeCopy(Request $r)
    {
        $period = Payroll::period($r->input('period'));
        abort_if(Payroll::loeLocked($period), 422, 'The payroll of this month is approved.');
        $n = Payroll::copyPrevious($period, $r->user());
        return back()->with('ok', "$n LOE lines copied from " . $period->copy()->subMonth()->format('M Y') . ' (only for employees that had nothing this month).');
    }

    public function loeImport(Request $r)
    {
        $r->validate(['file' => 'required|file|mimes:xlsx|max:5120']);
        [$n, $errors] = PayrollImport::loe($r->file('file')->getRealPath(), $r->user());
        return $errors ? back()->withErrors($errors) : back()->with('ok', "LOE of $n employees imported.");
    }

    public function loeTemplate(Request $r)
    {
        $period = Payroll::period($r->input('period'));
        return response()->download(PayrollExport::loeTemplate($period), 'LOE_' . $period->format('Y-m') . '.xlsx')->deleteFileAfterSend();
    }

    // ================================================================ LOE (the employee)
    public function myLoe(Request $r)
    {
        $ind = $r->user()->individual;
        $period = Payroll::period($r->input('period'));
        if (!$ind) {
            return view('payroll.loe_edit', ['individual' => null, 'period' => $period, 'self' => true]);
        }
        return view('payroll.loe_edit', $this->loeForm($ind, $period) + ['action' => route('loe.mine.save'), 'self' => true]);
    }

    public function myLoeSave(Request $r)
    {
        $ind = $r->user()->individual;
        abort_unless($ind, 403, 'Your login is not linked to an employee code. Ask the administrator.');
        return $this->saveLoe($r, $ind, 'employee', route('loe.mine', ['period' => Payroll::period($r->input('period'))->format('Y-m')]));
    }

    protected function loeForm(Individual $ind, \Illuminate\Support\Carbon $period): array
    {
        $entries = LoeEntry::with('budgetLine')->whereDate('period', $period->toDateString())->where('individual_id', $ind->id)->get();
        $contract = Contract::with('defaultBudgetLine')->where('individual_id', $ind->id)->inMonth($period)->orderByDesc('start_date')->first();
        $source = 'this month';
        if (!$entries->count()) {
            $prev = LoeEntry::with('budgetLine')->whereDate('period', $period->copy()->subMonth()->toDateString())->where('individual_id', $ind->id)->get();
            if ($prev->count()) {
                $entries = $prev;
                $source = 'last month (not saved yet)';
            } elseif ($contract?->defaultBudgetLine) {
                $entries = collect([new LoeEntry(['budget_line_id' => $contract->default_budget_line_id, 'percent' => 100])]);
                $entries[0]->setRelation('budgetLine', $contract->defaultBudgetLine);
                $source = 'the contract (not saved yet)';
            } else {
                $source = '';
            }
        }
        $rows = $entries->map(fn ($e) => ['id' => $e->budget_line_id, 'text' => $e->budgetLine?->code, 'sub' => trim(($e->budgetLine?->description ?? '') . ' · ' . ($e->budgetLine?->project_name ?? ''), ' ·'), 'percent' => (float) $e->percent])->values()->all();
        return ['individual' => $ind, 'period' => $period, 'rows' => $rows, 'source' => $source, 'contract' => $contract,
            'locked' => Payroll::loeLocked($period), 'saved' => LoeEntry::whereDate('period', $period->toDateString())->where('individual_id', $ind->id)->max('updated_at')];
    }

    protected function saveLoe(Request $r, Individual $ind, string $source, string $back)
    {
        $period = Payroll::period($r->input('period'));
        if (Payroll::loeLocked($period)) {
            return back()->withErrors(['loe' => 'The payroll of ' . $period->format('M Y') . ' is approved; its LOE cannot change.']);
        }
        $rows = array_values(array_filter((array) $r->input('rows', []), fn ($x) => !empty($x['budget_line_id']) || (float) ($x['percent'] ?? 0) > 0));
        foreach ($rows as $n => $x) {
            if (empty($x['budget_line_id'])) {
                return back()->withInput()->withErrors(['loe' => 'Row ' . ($n + 1) . ': choose the budget line.']);
            }
            if (!BudgetLine::whereKey((int) $x['budget_line_id'])->exists()) {
                return back()->withInput()->withErrors(['loe' => 'Row ' . ($n + 1) . ': the budget line does not exist.']);
            }
            $p = (float) ($x['percent'] ?? 0);
            if ($p <= 0 || $p > 100) {
                return back()->withInput()->withErrors(['loe' => 'Row ' . ($n + 1) . ': the percent must be more than 0 and at most 100.']);
            }
        }
        $sum = array_sum(array_map(fn ($x) => (float) $x['percent'], $rows));
        if ($rows && abs($sum - 100) > Payroll::TOLERANCE) {
            return back()->withInput()->withErrors(['loe' => 'The lines add up to ' . round($sum, 3) . ' %. Your month must add up to exactly 100 %.']);
        }
        Payroll::saveLoe($period, $ind->id, $rows, $r->user(), $source);
        return redirect($back)->with('ok', $rows ? 'LOE of ' . $period->format('M Y') . ' saved (100 %).' : 'LOE of ' . $period->format('M Y') . ' cleared.');
    }
}
