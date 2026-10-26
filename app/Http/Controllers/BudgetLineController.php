<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\Project;
use App\Services\LineBudget;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Budget lines of the running projects only: overview, filters, edit total / remaining of many lines of many projects at once, Excel. */
class BudgetLineController extends Controller
{
    protected function query(Request $r)
    {
        $q = BudgetLine::with('expenseAccount')->where('active', true)
            ->whereIn('project_code', Project::running()->pluck('code')->map(fn ($c) => (string) $c));
        if ($ps = array_filter((array) $r->input('p', []))) {
            $q->whereIn('project_code', $ps);
        }
        if ($r->filled('center')) {
            $q->where('accounting_center_code', $r->input('center'));
        }
        if ($r->filled('acc')) {
            $q->whereHas('expenseAccount', fn ($a) => $a->where('code', 'like', $r->input('acc') . '%'));
        }
        if ($s = trim((string) $r->input('q'))) {
            $q->where(fn ($w) => $w->where('code', 'like', "%$s%")->orWhere('description', 'like', "%$s%"));
        }
        if ($r->input('state') === 'unknown') {
            $q->whereNull('spent_before');   // no opening spent yet
        } elseif ($r->input('state') === 'over') {
            $q->whereNotNull('spent_before');   // filtered after the figures
        }
        return $q->orderByRaw('CAST(project_code AS UNSIGNED)')->orderBy('accounting_center_code')->orderBy('id');
    }

    public function index(Request $r)
    {
        abort_unless(ProjectController::canSee($r->user()), 403);
        $all = LineBudget::figures($this->query($r)->get());
        if ($r->input('state') === 'over') {
            $all = $all->filter(fn ($b) => $b->remaining_cur !== null && $b->remaining_cur < 0)->values();
        }
        $page = max(1, (int) $r->input('page', 1));
        $per = 100;
        $items = new \Illuminate\Pagination\LengthAwarePaginator($all->forPage($page, $per)->values(), $all->count(), $per, $page, ['path' => $r->url(), 'query' => $r->query()]);
        $running = Project::with('currency')->running()->orderBy('code')->get();
        return view('budget_lines.index', [
            'items' => $items, 'all' => $all, 'running' => $running,
            'centers' => BudgetLine::where('active', true)->select('accounting_center_code', 'accounting_center_name')->distinct()->orderBy('accounting_center_code')->get(),
            'edit' => $r->boolean('edit') && $r->user()->hasPerm('master.budget_lines.manage'), 'canEdit' => $r->user()->hasPerm('master.budget_lines.manage'),
            'canCreate' => $r->user()->hasPerm('master.budget_lines.manage'),
        ]);
    }

    public function save(Request $r)
    {
        abort_unless($r->user()->hasPerm('master.budget_lines.manage'), 403);
        [$n, $errors] = LineBudget::saveMany((array) $r->input('total', []), (array) $r->input('remaining', []));
        return $errors ? back()->withErrors($errors)->withInput() : back()->with('ok', "$n changes saved.");
    }

    public function export(Request $r)
    {
        abort_unless(ProjectController::canSee($r->user()), 403);
        return static::download($this->query($r)->get(), 'Budget_lines_' . now()->format('Y-m-d') . '.xlsx');
    }

    public function import(Request $r)
    {
        abort_unless($r->user()->hasPerm('master.budget_lines.manage'), 403);
        return static::upload($r, null);
    }

    // ---------------------------------------------------------------- shared with the project page
    public static function download($lines, string $name)
    {
        $path = storage_path('app/tmp/' . uniqid('lines_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Lines');
        $w->addRow(Row::fromValues(['Code', 'Project', 'Description', 'Accounting center', 'Expense account', 'Currency', 'Total', 'Spent', 'Remaining', 'Active'], (new Style())->setFontBold()));
        foreach (LineBudget::figures($lines) as $l) {
            $w->addRow(Row::fromValues([$l->code, $l->project_name, $l->description, $l->accounting_center_name, $l->expenseAccount?->code, $l->cur,
                (float) $l->total_amount, $l->spent_cur ?? '', $l->remaining_cur ?? '', $l->active ? 'Yes' : 'No']));
        }
        $w->close();
        return response()->download($path, $name)->deleteFileAfterSend();
    }

    /** Columns Code + Total and/or Remaining (project currency). Lines of any project, or of one project when $project is set. All or nothing. */
    public static function upload(Request $r, ?string $project)
    {
        $r->validate(['file' => 'required|file|mimes:xlsx|max:20480']);
        $rows = LineBudget::readSheet($r->file('file')->getRealPath(), ['code', 'remaining']);
        if ($rows === null) {
            return back()->withErrors(['file' => 'The file needs the columns "Code" and "Remaining". Download it from this page first.']);
        }
        $codes = collect($rows)->map(fn ($x) => (string) LineBudget::col($x, 'code'))->filter()->unique();
        $lines = BudgetLine::whereIn('code', $codes)->when($project, fn ($q) => $q->where('project_code', $project))->get()->keyBy('code');
        $totals = $remaining = [];
        $unknown = [];
        foreach ($rows as $x) {
            $code = (string) LineBudget::col($x, 'code');
            if ($code === '') {
                continue;
            }
            if (!isset($lines[$code])) {
                $unknown[] = $code;
                continue;
            }
            $id = $lines[$code]->id;
            $t = LineBudget::col($x, 'total');
            if ($t !== null && $t !== '') {
                $totals[$id] = $t;
            }
            $remaining[$id] = LineBudget::col($x, 'remaining');
        }
        if ($unknown) {
            return back()->withErrors(['file' => ($project ? 'Not lines of this project: ' : 'Unknown line codes: ') . implode(', ', array_slice($unknown, 0, 10)) . (count($unknown) > 10 ? '…' : '') . ' Nothing was saved.']);
        }
        [$n, $errors] = LineBudget::saveMany($totals, $remaining);
        return $errors ? back()->withErrors(array_merge(['Nothing was saved:'], array_slice($errors, 0, 15))) : back()->with('ok', "$n changes saved from the file.");
    }
}
