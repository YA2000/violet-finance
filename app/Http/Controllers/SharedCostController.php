<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\CostCategory;
use App\Models\CostCategoryLine;
use App\Models\ExpenseLine;
use App\Models\ExpenseRun;
use App\Models\Project;
use App\Services\Payroll;
use App\Services\SharedCostExport;
use App\Services\SharedCosts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shared costs: upload the QuickBooks report of the month, allocate it by the FTE of the payroll. */
class SharedCostController extends Controller
{
    protected function canSee(Request $r): void
    {
        \App\Support\SharedCostInstall::mapping();
        abort_unless($r->user()->hasPerm('costs.view') || $r->user()->hasPerm('costs.manage'), 403);
    }

    public function index(Request $r)
    {
        $this->canSee($r);
        $runs = ExpenseRun::withCount('lines')->orderByDesc('period')->paginate(24);
        $sums = ExpenseLine::whereIn('expense_run_id', $runs->pluck('id'))->selectRaw('expense_run_id, SUM(amount_usd) as usd')->groupBy('expense_run_id')->pluck('usd', 'expense_run_id');
        $core = Payroll::coreCode();
        $coreSums = \App\Models\ExpenseAllocation::whereIn('expense_run_id', $runs->pluck('id'))->where('project_code', $core)
            ->selectRaw('expense_run_id, SUM(amount_usd) as usd')->groupBy('expense_run_id')->pluck('usd', 'expense_run_id');
        $next = ExpenseRun::max('period') ? Payroll::period(ExpenseRun::max('period'))->addMonth() : now()->startOfMonth();
        return view('costs.index', compact('runs', 'sums', 'coreSums', 'next'));
    }

    /** Open the month (if needed) and upload the report in one step. */
    public function store(Request $r)
    {
        $r->validate(['period' => 'required|date_format:Y-m', 'file' => 'required|file|mimes:xlsx|max:10240']);
        $run = SharedCosts::open(Payroll::period($r->input('period')), $r->user());
        if (!$run->isDraft()) {
            return back()->withErrors(['file' => $run->label() . ' is approved. Reopen it first.']);
        }
        [$n, $errors] = SharedCosts::import($run, $r->file('file')->getRealPath(), $r->file('file')->getClientOriginalName());
        if ($errors) {
            return back()->withErrors($errors);
        }
        return redirect()->route('costs.show', [$run, 'tab' => 'checks'])->with('ok', "$n lines uploaded for " . $run->label() . '.');
    }

    public function show(Request $r, ExpenseRun $run)
    {
        $this->canSee($r);
        $pr = SharedCosts::payrollRun($run);
        return view('costs.show', [
            'run' => $run, 'lines' => $run->lines()->with('allocations')->get(), 'problems' => SharedCosts::check($run), 'totals' => SharedCosts::totals($run),
            'byProject' => SharedCosts::byProject($run), 'gaps' => SharedCosts::gaps($run), 'matrix' => SharedCosts::matrix($run),
            'cats' => CostCategory::orderBy('sequence')->get()->keyBy('code'), 'payroll' => $pr, 'keys' => SharedCosts::keysFor($run),
            'tab' => $r->input('tab', 'lines'), 'canManage' => $r->user()->hasPerm('costs.manage'),
            'projects' => Project::where('active', true)->orderBy('code')->get(['code', 'name', 'qb_name']),
        ]);
    }

    public function line(Request $r, ExpenseRun $run, ExpenseLine $line)
    {
        abort_unless($line->expense_run_id === $run->id && $run->isDraft(), 422);
        $v = $r->validate(['direct_project' => 'nullable|string|max:20', 'excluded' => 'nullable|boolean', 'exclude_reason' => 'nullable|string|max:190']);
        $line->update(['direct_project' => $v['direct_project'] ?: null, 'excluded' => $r->boolean('excluded'), 'exclude_reason' => $r->boolean('excluded') ? ($v['exclude_reason'] ?? null) : null]);
        SharedCosts::dropAllocation($run);
        return back()->with('ok', 'Line ' . ($line->num ?: $line->row_no) . ' saved.');
    }

    public function update(Request $r, ExpenseRun $run)
    {
        abort_unless($run->isDraft(), 422);
        $run->update($r->validate(['ref_number' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:2000']));
        return back()->with('ok', 'Saved.');
    }

    public function allocate(Request $r, ExpenseRun $run)
    {
        try {
            $n = SharedCosts::allocate($run);
        } catch (\RuntimeException $e) {
            return redirect()->route('costs.show', [$run, 'tab' => 'checks'])->withErrors(['allocate' => $e->getMessage()]);
        }
        return redirect()->route('costs.show', [$run, 'tab' => 'projects'])->with('ok', "Allocation done: $n shares.");
    }

    public function approve(Request $r, ExpenseRun $run)
    {
        try {
            SharedCosts::approve($run, $r->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }
        return back()->with('ok', 'Shared costs of ' . $run->label() . ' approved.');
    }

    public function reopen(Request $r, ExpenseRun $run)
    {
        $run->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        return back()->with('ok', 'Reopened as a draft.');
    }

    public function destroy(Request $r, ExpenseRun $run)
    {
        abort_unless($run->isDraft(), 422);
        SharedCosts::dropAllocation($run);
        $run->lines()->delete();
        $run->delete();
        return redirect()->route('costs.index')->with('ok', 'Deleted.');
    }

    public function export(Request $r, ExpenseRun $run, string $kind)
    {
        $this->canSee($r);
        if (!$run->allocations()->exists()) {
            return back()->withErrors(['export' => 'Run the allocation first.']);
        }
        $tag = $run->period->format('Y-m');
        [$path, $name] = match ($kind) {
            'allocation' => [SharedCostExport::allocation($run), "Shared_Costs_Allocation_$tag.xlsx"],
            'uploader' => [SharedCostExport::uploader($run), "Shared_Costs_QB_Uploader_$tag.xlsx"],
            default => abort(404),
        };
        return response()->download($path, $name)->deleteFileAfterSend();
    }

    // ---------------------------------------------------------------- categories & budget lines per project
    public function categories(Request $r)
    {
        $this->canSee($r);
        $cats = CostCategory::with('lines.budgetLine')->orderBy('sequence')->get();
        $codes = $cats->flatMap(fn ($c) => $c->lines->pluck('project_code'))
            ->merge(\App\Models\PayrollAllocation::query()->distinct()->pluck('project_code'))
            ->unique()->sort(SORT_NATURAL)->values();
        $projects = Project::whereIn('code', $codes->map(fn ($c) => (int) $c))->get()->keyBy(fn ($p) => (string) $p->code);
        return view('costs.categories', ['cats' => $cats, 'codes' => $codes, 'projects' => $projects, 'canManage' => $r->user()->hasPerm('costs.manage')]);
    }

    public function saveCategory(Request $r, ?CostCategory $category = null)
    {
        $v = $r->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('cost_categories', 'code')->ignore($category?->id)],
            'name' => 'required|string|max:190', 'office' => ['nullable', Rule::in(['Syria', 'Turkey'])],
            'basis' => ['required', Rule::in(array_keys(CostCategory::BASIS))], 'active' => 'nullable|boolean',
        ]);
        $v['active'] = $r->boolean('active', true);
        $category ? $category->update($v) : CostCategory::create($v + ['sequence' => (int) CostCategory::max('sequence') + 1]);
        return back()->with('ok', 'Category ' . $v['code'] . ' saved.');
    }

    public function saveMapping(Request $r)
    {
        $v = $r->validate(['cost_category_id' => 'required|exists:cost_categories,id', 'project_code' => 'required|string|max:20', 'budget_line' => 'nullable|string|max:80']);
        $code = trim((string) ($v['budget_line'] ?? ''));
        if ($code === '') {
            CostCategoryLine::where('cost_category_id', $v['cost_category_id'])->where('project_code', $v['project_code'])->delete();
            return back()->with('ok', 'Budget line removed: the share of project ' . $v['project_code'] . ' now goes to the core fund as a gap.');
        }
        $bl = Payroll::findBudgetLine($code);
        if (!$bl) {
            return back()->withErrors(['budget_line' => "Budget line $code does not exist."]);
        }
        if ((string) $bl->project_code !== (string) $v['project_code']) {
            return back()->withErrors(['budget_line' => "Budget line $code belongs to project {$bl->project_code}, not {$v['project_code']}."]);
        }
        CostCategoryLine::updateOrCreate(['cost_category_id' => $v['cost_category_id'], 'project_code' => $v['project_code']], ['budget_line_id' => $bl->id]);
        return back()->with('ok', "Project {$v['project_code']}: $code saved.");
    }
}
