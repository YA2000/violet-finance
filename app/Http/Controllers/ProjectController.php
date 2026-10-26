<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\FundingLine;
use App\Models\Project;
use App\Services\CostRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Project page: everything about one project — status, budget, donor balance, its budget lines with total / spent / remaining. */
class ProjectController extends Controller
{
    protected function see(Request $r): void
    {
        $u = $r->user();
        abort_unless(collect(['master.projects.manage', 'master.budget_lines.manage', 'costs.view', 'costs.manage', 'payroll.view', 'reports.view'])->contains(fn ($p) => $u->hasPerm($p)), 403);
    }

    protected function canEditLines(Request $r): bool
    {
        return $r->user()->hasPerm('master.budget_lines.manage');
    }

    /** Lines of the project with total / spent / remaining in the project currency (spent is kept in USD, as the allocation uses it). */
    protected function lines(Project $p, bool $archived)
    {
        $rate = CostRecovery::usdRate($p->currency?->code);
        $lines = BudgetLine::with('expenseAccount')->where('project_code', (string) $p->code)->where('active', !$archived)
            ->orderBy('accounting_center_code')->orderBy('id')->get();
        $charged = CostRecovery::chargedBefore($lines->pluck('id')->all(), now()->addMonth()->startOfMonth());
        return $lines->map(function ($b) use ($rate, $charged) {
            $total = (float) $b->total_amount;
            $spent = $b->spent_before !== null && $rate > 0 ? round((float) $b->spent_before / $rate, 2) : null;
            $b->spent_cur = $spent;
            $b->remaining_cur = $spent !== null ? round($total - $spent, 2) : null;
            $b->used = $total > 0 && $spent !== null ? $spent / $total : null;
            $b->charged_usd = (float) ($charged[$b->id] ?? 0);
            return $b;
        });
    }

    public function show(Request $r, int $id)
    {
        $this->see($r);
        $p = Project::with(['currency', 'partner'])->findOrFail($id);
        $archived = $r->input('show') === 'archived';
        $lines = $this->lines($p, $archived);
        $rate = CostRecovery::usdRate($p->currency?->code);
        $counts = BudgetLine::where('project_code', (string) $p->code)->selectRaw('active, COUNT(*) n, SUM(COALESCE(total_amount,0)) t')->groupBy('active')->get()->keyBy(fn ($x) => (int) $x->active);
        $funding = FundingLine::with(['individual', 'budgetLine'])->where('project_code', (string) $p->code)->where('active', true)->get();
        return view('projects.show', [
            'p' => $p, 'lines' => $lines, 'groups' => $lines->groupBy(fn ($b) => $b->accounting_center_code . ' · ' . $b->accounting_center_name),
            'archived' => $archived, 'counts' => $counts, 'rate' => $rate, 'cur' => strtoupper($p->currency?->code ?? 'USD'),
            'months' => $p->end_date && $p->end_date->lt(now()->startOfMonth()) ? 0 : CostRecovery::monthsLeft($p, now()->startOfMonth()),
            'funding' => $funding, 'canEdit' => $this->canEditLines($r), 'canEditProject' => $r->user()->hasPerm('master.projects.manage'),
        ]);
    }

    /** Remaining per line, typed in the project currency → stored as spent before (USD). Empty = unknown. */
    protected function setRemaining(BudgetLine $b, $value, float $rate): bool
    {
        if ($value === null || $value === '') {
            if ($b->spent_before === null) {
                return false;
            }
            $b->spent_before = null;
            $b->spent_before_date = null;
        } else {
            $spentUsd = round(((float) $b->total_amount - (float) $value) * $rate, 2);   // negative remaining = overspent
            if ($b->spent_before !== null && abs((float) $b->spent_before - $spentUsd) < 0.01) {
                return false;
            }
            $b->spent_before = $spentUsd;
            $b->spent_before_date = now()->toDateString();
        }
        $b->saveQuietly();
        return true;
    }

    public function saveLines(Request $r, int $id)
    {
        abort_unless($this->canEditLines($r), 403);
        $p = Project::with('currency')->findOrFail($id);
        $rate = CostRecovery::usdRate($p->currency?->code);
        if ($rate <= 0) {
            return back()->withErrors(['remaining' => 'Set "US dollars for 1 unit" of ' . ($p->currency?->code ?? 'the currency') . ' in Master data → Currencies first.']);
        }
        $r->validate(['remaining' => 'array', 'remaining.*' => 'nullable|numeric', 'total' => 'array', 'total.*' => 'nullable|numeric|min:0']);
        $bad = [];
        foreach (BudgetLine::where('project_code', (string) $p->code)->whereIn('id', array_keys($r->input('remaining', [])))->get() as $b) {
            $t = $r->filled("total.$b->id") ? (float) $r->input("total.$b->id") : (float) $b->total_amount;
            if ($r->filled("remaining.$b->id") && (float) $r->input("remaining.$b->id") > $t + 0.005) {
                $bad[] = $b->code;
            }
        }
        if ($bad) {
            return back()->withErrors(['remaining' => 'The remaining cannot be more than the total: ' . implode(', ', $bad) . '. Nothing was saved.'])->withInput();
        }
        $n = 0;
        DB::transaction(function () use ($r, $p, $rate, &$n) {
            foreach (BudgetLine::where('project_code', (string) $p->code)->whereIn('id', array_keys($r->input('remaining', []) + $r->input('total', [])))->get() as $b) {
                $t = $r->input("total.$b->id");
                if ($t !== null && $t !== '' && abs((float) $t - (float) $b->total_amount) >= 0.005) {
                    $b->total_amount = (float) $t;
                    $b->saveQuietly();
                    $n++;
                }
                if ($r->has("remaining.$b->id") && $this->setRemaining($b, $r->input("remaining.$b->id"), $rate)) {
                    $n++;
                }
            }
        });
        return back()->with('ok', "$n changes saved.");
    }

    public function export(Request $r, int $id)
    {
        $this->see($r);
        $p = Project::with('currency')->findOrFail($id);
        $cur = strtoupper($p->currency?->code ?? 'USD');
        $path = storage_path('app/tmp/' . uniqid('project_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $b = (new Style())->setFontBold();
        $w->getCurrentSheet()->setName('Lines');
        $w->addRow(Row::fromValues(['Code', 'Description', 'Accounting center', 'Expense account', "Total $cur", "Spent $cur", "Remaining $cur", 'Active'], $b));
        foreach ([false, true] as $arch) {
            foreach ($this->lines($p, $arch) as $l) {
                $w->addRow(Row::fromValues([$l->code, $l->description, $l->accounting_center_name, $l->expenseAccount?->code, (float) $l->total_amount,
                    $l->spent_cur ?? '', $l->remaining_cur ?? '', $arch ? 'No' : 'Yes']));
            }
        }
        $w->close();
        return response()->download($path, 'Project_' . $p->code . '_lines.xlsx')->deleteFileAfterSend();
    }

    /** Upload the lines file back: the column "Remaining" updates each line (by Code). */
    public function import(Request $r, int $id)
    {
        abort_unless($this->canEditLines($r), 403);
        $r->validate(['file' => 'required|file|mimes:xlsx|max:10240']);
        $p = Project::with('currency')->findOrFail($id);
        $rate = CostRecovery::usdRate($p->currency?->code);
        if ($rate <= 0) {
            return back()->withErrors(['file' => 'Set "US dollars for 1 unit" of the project currency first (Master data → Currencies).']);
        }
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($r->file('file')->getRealPath());
        $map = null;
        $vals = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $c = $row->toArray();
                if ($map === null) {
                    $low = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $c);
                    $code = array_search('code', $low, true);
                    $rem = collect($low)->search(fn ($x) => str_starts_with($x, 'remaining'));
                    if ($code !== false && $rem !== false) {
                        $map = [$code, $rem];
                    }
                    continue;
                }
                $k = trim((string) ($c[$map[0]] ?? ''));
                if ($k !== '') {
                    $vals[$k] = $c[$map[1]] ?? null;
                }
            }
            break;
        }
        $reader->close();
        if ($map === null) {
            return back()->withErrors(['file' => 'The file needs the columns "Code" and "Remaining". Download it from this page first.']);
        }
        $n = 0;
        $unknown = $skipped = [];
        DB::transaction(function () use ($vals, $p, $rate, &$n, &$unknown, &$skipped) {
            $lines = BudgetLine::where('project_code', (string) $p->code)->whereIn('code', array_keys($vals))->get()->keyBy('code');
            foreach ($vals as $code => $v) {
                if (!isset($lines[$code])) {
                    $unknown[] = $code;
                    continue;
                }
                if ($v !== null && $v !== '' && (!is_numeric($v) || (float) $v > (float) $lines[$code]->total_amount + 0.005)) {
                    $skipped[] = $code;
                    continue;
                }
                $n += $this->setRemaining($lines[$code], $v, $rate) ? 1 : 0;
            }
        });
        return back()->with('ok', "$n lines updated." . ($skipped ? ' Skipped (not a number, or more than the total): ' . implode(', ', array_slice($skipped, 0, 8)) . '.' : '') . ($unknown ? ' Not lines of this project: ' . implode(', ', array_slice($unknown, 0, 8)) . (count($unknown) > 8 ? '…' : '') : ''));
    }
}
