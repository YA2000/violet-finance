<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\Currency;
use App\Models\FundingLine;
use App\Models\Project;
use App\Services\CostRecovery;
use App\Services\LineBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Projects: overview of the running projects (with bulk edit) and one page per project with its budget lines. */
class ProjectController extends Controller
{
    /** Project fields editable in bulk (overview and Excel). */
    public const EDIT = [
        'start_date' => 'Start', 'end_date' => 'End', 'status' => 'Status', 'office' => 'Office', 'manager' => 'Manager',
        'budget_total' => 'Budget', 'icr_rate' => 'ICR %', 'spent_total' => 'Spent', 'icr_amount' => 'ICR amount',
        'balance' => 'Remaining', 'received' => 'Received', 'donor_balance' => 'Due from donor',
    ];

    public static function canSee($u): bool
    {
        return collect(['master.projects.manage', 'master.budget_lines.manage', 'costs.view', 'costs.manage', 'payroll.view', 'reports.view'])->contains(fn ($p) => $u->hasPerm($p));
    }

    protected function see(Request $r): void
    {
        abort_unless(static::canSee($r->user()), 403);
    }

    protected function canEditLines(Request $r): bool
    {
        return $r->user()->hasPerm('master.budget_lines.manage');
    }

    // ================================================================= overview
    public function index(Request $r)
    {
        $this->see($r);
        $ended = $r->input('show') === 'ended';
        $all = Project::with(['currency', 'partner'])->when($ended, fn ($q) => $q->where('active', false), fn ($q) => $q->running())->orderBy('end_date')->orderBy('code')->get();
        $donor = $all->reject->isInternal();
        $lineStats = BudgetLine::where('active', true)->selectRaw('project_code, COUNT(*) n, SUM(spent_before IS NULL) unknown')->groupBy('project_code')->get()->keyBy('project_code');
        $list = $donor;
        if ($s = trim((string) $r->input('q'))) {
            $list = $list->filter(fn ($p) => str_contains(mb_strtolower($p->code . ' ' . $p->name . ' ' . $p->qb_name . ' ' . $p->manager), mb_strtolower($s)));
        }
        foreach (['office', 'manager'] as $f) {
            if ($r->filled($f)) {
                $list = $list->where($f, $r->input($f));
            }
        }
        if ($r->filled('cur')) {
            $list = $list->filter(fn ($p) => $p->currency?->code === $r->input('cur'));
        }
        if ($r->input('soon')) {
            $list = $list->filter->endsSoon(2);
        }
        $usd = fn ($p, $f) => $p->$f !== null && ($rate = LineBudget::rateOf($p)) > 0 ? (float) $p->$f * $rate : null;
        return view('projects.index', [
            'list' => $list->values(), 'donor' => $donor, 'internal' => $all->filter->isInternal(), 'ended' => $ended, 'lineStats' => $lineStats, 'usd' => $usd,
            'soon' => $donor->filter->endsSoon(2)->values(), 'endedCount' => Project::where('active', false)->count(),
            'offices' => $donor->pluck('office')->filter()->unique()->sort()->values(), 'managers' => $donor->pluck('manager')->filter()->unique()->sort()->values(),
            'currencies' => $donor->map(fn ($p) => $p->currency?->code)->filter()->unique()->sort()->values(),
            'noRate' => $donor->filter(fn ($p) => LineBudget::rateOf($p) <= 0)->map(fn ($p) => $p->currency?->code ?? '?')->unique()->values(),
            'edit' => $r->boolean('edit') && $r->user()->hasPerm('master.projects.manage'), 'canEdit' => $r->user()->hasPerm('master.projects.manage'),
        ]);
    }

    protected function cleanRow(array $v): array
    {
        $out = [];
        foreach (static::EDIT as $f => $label) {
            if (!array_key_exists($f, $v)) {
                continue;
            }
            $x = is_string($v[$f]) ? trim($v[$f]) : $v[$f];
            if (in_array($f, ['start_date', 'end_date'])) {
                $out[$f] = $x ? \Illuminate\Support\Carbon::parse($x)->toDateString() : null;
            } elseif ($f === 'icr_rate') {
                $out[$f] = $x === null || $x === '' ? null : ((float) $x > 1 ? (float) $x / 100 : (float) $x);   // 5 or 0.05
            } elseif (in_array($f, ['status', 'office', 'manager'])) {
                $out[$f] = $x === '' ? null : $x;
            } else {
                $out[$f] = $x === null || $x === '' ? null : (float) str_replace(',', '', (string) $x);
            }
        }
        if (array_key_exists('status', $out) && !$out['status']) {
            unset($out['status']);
        }
        return $out;
    }

    protected function checkRow(array $v, string $who): array
    {
        $e = [];
        if (isset($v['status']) && !isset(Project::STATUSES[$v['status']])) {
            $e[] = "$who: unknown status \"{$v['status']}\" (Ongoing, Ended, Suspended, Cancelled).";
        }
        if (!empty($v['office']) && !isset(Project::OFFICES[$v['office']])) {
            $e[] = "$who: the office is Turkey or Syria.";
        }
        if (!empty($v['start_date']) && !empty($v['end_date']) && $v['end_date'] < $v['start_date']) {
            $e[] = "$who: the end is before the start.";
        }
        return $e;
    }

    /** Bulk edit from the overview: rows[id][field]. All or nothing. */
    public function bulk(Request $r)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $rows = (array) $r->input('rows', []);
        $projects = Project::whereIn('id', array_keys($rows))->get()->keyBy('id');
        $clean = $errors = [];
        foreach ($rows as $id => $v) {
            if (!isset($projects[$id])) {
                continue;
            }
            try {
                $clean[$id] = $this->cleanRow((array) $v);
            } catch (\Throwable $e) {
                $errors[] = $projects[$id]->code . ': a date is not valid.';
                continue;
            }
            $errors = array_merge($errors, $this->checkRow($clean[$id], (string) $projects[$id]->code));
        }
        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }
        $n = $this->apply($projects, $clean);
        return redirect()->route('projects.index', array_filter($r->only(['q', 'office', 'manager', 'cur', 'soon']) + ['edit' => 1]))->with('ok', "$n projects saved.");
    }

    protected function apply($projects, array $clean): int
    {
        $n = 0;
        DB::transaction(function () use ($projects, $clean, &$n) {
            foreach ($clean as $id => $v) {
                $p = $projects[$id];
                $p->fill($v);
                if ($p->isDirty()) {
                    $p->save();   // a status other than Ongoing switches the project off and archives its lines
                    $n++;
                }
            }
        });
        return $n;
    }

    public function exportList(Request $r)
    {
        $this->see($r);
        $path = storage_path('app/tmp/' . uniqid('projects_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Projects');
        $w->addRow(Row::fromValues(array_merge(['Code', 'Project name', 'Currency'], array_values(static::EDIT), ['Months left', 'Lines']), (new Style())->setFontBold()));
        $lines = BudgetLine::where('active', true)->selectRaw('project_code, COUNT(*) n')->groupBy('project_code')->pluck('n', 'project_code');
        foreach (Project::with('currency')->running()->orderBy('code')->get()->reject->isInternal() as $p) {
            $vals = [];
            foreach (array_keys(static::EDIT) as $f) {
                $v = $p->$f;
                $vals[] = $v instanceof \Carbon\CarbonInterface ? $v->format('Y-m-d') : ($f === 'icr_rate' && $v !== null ? round($v * 100, 2) : ($v === null ? '' : (is_numeric($v) ? (float) $v : $v)));
            }
            $w->addRow(Row::fromValues(array_merge([$p->code, $p->qb_name ?: $p->name, $p->currency?->code], $vals, [$p->monthsLeft() ?? '', (int) ($lines[(string) $p->code] ?? 0)])));
        }
        $w->close();
        return response()->download($path, 'Running_projects_' . now()->format('Y-m-d') . '.xlsx')->deleteFileAfterSend();
    }

    /** Upload the projects file back: updates every column that is in the file, by Code. All or nothing. */
    public function importList(Request $r)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $r->validate(['file' => 'required|file|mimes:xlsx|max:10240']);
        $rows = LineBudget::readSheet($r->file('file')->getRealPath(), ['code']);
        if ($rows === null) {
            return back()->withErrors(['file' => 'The file needs a "Code" column. Download it from this page first.']);
        }
        $byLabel = collect(static::EDIT)->mapWithKeys(fn ($l, $f) => [mb_strtolower($l) => $f]);
        $codes = collect($rows)->map(fn ($x) => (int) LineBudget::col($x, 'code'))->filter()->all();
        $projects = Project::whereIn('code', $codes)->get()->keyBy('code');
        $clean = $errors = $unknown = [];
        foreach ($rows as $x) {
            $code = (int) LineBudget::col($x, 'code');
            if (!$code) {
                continue;
            }
            if (!isset($projects[$code])) {
                $unknown[] = $code;
                continue;
            }
            $v = [];
            foreach ($x as $h => $val) {
                if (isset($byLabel[$h])) {
                    $v[$byLabel[$h]] = $val;
                }
            }
            try {
                $c = $this->cleanRow($v);
            } catch (\Throwable $e) {
                $errors[] = "$code: a date is not valid.";
                continue;
            }
            $errors = array_merge($errors, $this->checkRow($c, (string) $code));
            $clean[$projects[$code]->id] = $c;
        }
        if ($unknown) {
            $errors[] = 'Unknown project codes: ' . implode(', ', array_slice($unknown, 0, 10)) . '.';
        }
        if ($errors) {
            return back()->withErrors($errors);
        }
        $n = $this->apply($projects->keyBy('id'), $clean);
        return back()->with('ok', "$n projects updated from the file.");
    }

    // ================================================================= one project
    public function show(Request $r, int $id)
    {
        $this->see($r);
        $p = Project::with(['currency', 'partner'])->findOrFail($id);
        $archived = $r->input('show') === 'archived';
        $lines = LineBudget::figures(BudgetLine::with('expenseAccount')->where('project_code', (string) $p->code)->where('active', !$archived)
            ->orderBy('accounting_center_code')->orderBy('id')->get(), [$p]);
        $charged = CostRecovery::chargedBefore($lines->pluck('id')->all(), now()->addMonth()->startOfMonth());
        $lines->each(fn ($b) => $b->charged_usd = (float) ($charged[$b->id] ?? 0));
        $counts = BudgetLine::where('project_code', (string) $p->code)->selectRaw('active, COUNT(*) n')->groupBy('active')->pluck('n', 'active');
        return view('projects.show', [
            'p' => $p, 'lines' => $lines, 'groups' => $lines->groupBy(fn ($b) => $b->accounting_center_code . ' · ' . $b->accounting_center_name),
            'archived' => $archived, 'counts' => $counts, 'rate' => LineBudget::rateOf($p), 'cur' => strtoupper($p->currency?->code ?? 'USD'),
            'funding' => FundingLine::with(['individual', 'budgetLine'])->where('project_code', (string) $p->code)->where('active', true)->get(),
            'canEdit' => $this->canEditLines($r), 'canEditProject' => $r->user()->hasPerm('master.projects.manage'),
        ]);
    }

    public function saveLines(Request $r, int $id)
    {
        abort_unless($this->canEditLines($r), 403);
        Project::findOrFail($id);
        [$n, $errors] = LineBudget::saveMany((array) $r->input('total', []), (array) $r->input('remaining', []));
        return $errors ? back()->withErrors($errors)->withInput() : back()->with('ok', "$n changes saved.");
    }

    public function export(Request $r, int $id)
    {
        $this->see($r);
        $p = Project::findOrFail($id);
        return BudgetLineController::download(BudgetLine::where('project_code', (string) $p->code)->orderBy('active', 'desc')->orderBy('accounting_center_code')->orderBy('id')->get(), 'Project_' . $p->code . '_lines.xlsx');
    }

    public function import(Request $r, int $id)
    {
        abort_unless($this->canEditLines($r), 403);
        $p = Project::findOrFail($id);
        return BudgetLineController::upload($r, (string) $p->code);
    }
}
