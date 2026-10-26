<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\FundingLine;
use App\Services\CostRecovery;
use App\Services\Payroll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/** Cost recovery plan: shared cost pool, key, funding map, funding lines and their capacity. */
class CostRecoveryController extends Controller
{
    protected function canSee(Request $r): void
    {
        $u = $r->user();
        abort_unless($u->hasPerm('costs.view') || $u->hasPerm('costs.manage') || $u->hasPerm('payroll.view') || $u->hasPerm('payroll.manage'), 403);
        \App\Support\CostRecoveryInstall::run();
        \App\Support\SharedCostInstall::mapping();
    }

    protected function canManage(Request $r): bool
    {
        return $r->user()->hasPerm('costs.manage') || $r->user()->hasPerm('payroll.manage');
    }

    public function index(Request $r)
    {
        $this->canSee($r);
        $period = Payroll::period($r->input('period'));
        return view('recovery.index', CostRecovery::plan($period) + ['canManage' => $this->canManage($r)]);
    }

    public function funding(Request $r)
    {
        $this->canSee($r);
        $period = Payroll::period($r->input('period'));
        $running = CostRecovery::running($period);
        $q = FundingLine::with(['budgetLine', 'individual']);
        if ($r->filled('project')) {
            $q->where('project_code', $r->input('project'));
        }
        if ($r->filled('q')) {
            $s = '%' . $r->input('q') . '%';
            $q->where(fn ($w) => $w->whereHas('individual', fn ($i) => $i->where('name', 'like', $s)->orWhere('code', 'like', $s))
                ->orWhereHas('budgetLine', fn ($b) => $b->where('code', 'like', $s)->orWhere('description', 'like', $s)));
        }
        $lines = $q->get()->sortBy(fn ($f) => [(int) $f->project_code, $f->budgetLine?->code, $f->individual?->name])->values();
        $cap = CostRecovery::capacity($lines->pluck('budgetLine'), $period);
        return view('recovery.funding', ['lines' => $lines, 'cap' => $cap, 'period' => $period, 'running' => $running, 'canManage' => $this->canManage($r),
            'suggestions' => $this->canManage($r) ? CostRecovery::suggest($period) : []]);
    }

    public function acceptSuggestions(Request $r)
    {
        abort_unless($this->canManage($r), 403);
        $n = 0;
        foreach ((array) $r->input('pick', []) as $v) {
            [$bl, $ind] = array_map('intval', explode('|', (string) $v) + [0, 0]);
            $b = BudgetLine::find($bl);
            if ($b && \App\Models\Individual::whereKey($ind)->exists()) {
                FundingLine::firstOrCreate(['budget_line_id' => $bl, 'individual_id' => $ind], ['project_code' => (string) $b->project_code, 'active' => true]);
                $n++;
            }
        }
        return back()->with('ok', "$n funding lines added.");
    }

    public function storeFunding(Request $r)
    {
        abort_unless($this->canManage($r), 403);
        $v = $r->validate(['individual_id' => 'required|exists:individuals,id', 'budget_line' => 'required|string|max:80', 'notes' => 'nullable|string|max:190']);
        $bl = Payroll::findBudgetLine($v['budget_line']);
        if (!$bl) {
            return back()->withErrors(['budget_line' => "Budget line {$v['budget_line']} does not exist."]);
        }
        if ((string) $bl->project_code === CostRecovery::core()) {
            return back()->withErrors(['budget_line' => 'The core fund is never a funding line: it takes what the projects do not cover.']);
        }
        FundingLine::updateOrCreate(['budget_line_id' => $bl->id, 'individual_id' => $v['individual_id']], ['project_code' => (string) $bl->project_code, 'active' => true, 'notes' => $v['notes'] ?? null]);
        return back()->with('ok', "Funding line {$bl->code} saved.");
    }

    public function toggleFunding(Request $r, FundingLine $line)
    {
        abort_unless($this->canManage($r), 403);
        $line->update(['active' => !$line->active]);
        return back()->with('ok', 'Funding line ' . ($line->active ? 'activated' : 'stopped') . '.');
    }

    public function destroyFunding(Request $r, FundingLine $line)
    {
        abort_unless($this->canManage($r), 403);
        $line->delete();
        return back()->with('ok', 'Funding line removed.');
    }

    /** Two imports in one file format: funding lines (Employee ID + BL) and opening balances (BL + spent before + date). */
    public function import(Request $r)
    {
        abort_unless($this->canManage($r), 403);
        $r->validate(['file' => 'required|file|mimes:xlsx|max:5120', 'kind' => 'required|in:funding,opening']);
        $reader = new Reader();
        $reader->open($r->file('file')->getRealPath());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $i = 0;
            foreach ($sheet->getRowIterator() as $row) {
                if (++$i === 1) {
                    continue;
                }
                $c = $row->toArray();
                if (array_filter($c, fn ($x) => $x !== null && $x !== '')) {
                    $rows[$i] = $c;
                }
            }
            break;
        }
        $reader->close();
        $errors = [];
        $save = [];
        foreach ($rows as $n => $c) {
            if ($r->input('kind') === 'funding') {
                [$emp, $blc] = array_pad($c, 2, null);
                $ind = Payroll::findIndividual((string) $emp);
                $bl = Payroll::findBudgetLine((string) $blc);
                if (!$ind) { $errors[] = "Row $n: employee '$emp' is not in Individuals."; continue; }
                if (!$bl) { $errors[] = "Row $n: budget line '$blc' does not exist."; continue; }
                if ((string) $bl->project_code === CostRecovery::core()) { $errors[] = "Row $n: $blc is a core fund line; the core takes the remainder by itself."; continue; }
                $save[] = [$ind->id, $bl];
            } else {
                [$blc, $spent, $date] = array_pad($c, 3, null);
                $bl = Payroll::findBudgetLine((string) $blc);
                if (!$bl) { $errors[] = "Row $n: budget line '$blc' does not exist."; continue; }
                if (!is_numeric(str_replace(',', '', (string) $spent))) { $errors[] = "Row $n: spent '$spent' is not a number."; continue; }
                $d = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : ($date ? date('Y-m-d', strtotime((string) $date)) : null);
                $save[] = [$bl, (float) str_replace(',', '', (string) $spent), $d];
            }
        }
        if ($errors) {
            return back()->withErrors($errors);
        }
        DB::transaction(function () use ($save, $r) {
            foreach ($save as $s) {
                if ($r->input('kind') === 'funding') {
                    FundingLine::updateOrCreate(['budget_line_id' => $s[1]->id, 'individual_id' => $s[0]], ['project_code' => (string) $s[1]->project_code, 'active' => true]);
                } else {
                    $s[0]->spent_before = $s[1];
                    $s[0]->spent_before_date = $s[2];
                    $s[0]->saveQuietly();
                }
            }
        });
        return back()->with('ok', count($save) . ($r->input('kind') === 'funding' ? ' funding lines imported.' : ' opening balances imported.'));
    }

    public function template(Request $r, string $kind)
    {
        $path = storage_path('app/tmp/' . uniqid('tpl_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $b = (new Style())->setFontBold();
        if ($kind === 'funding') {
            $w->getCurrentSheet()->setName('Funding lines');
            $w->addRow(Row::fromValues(['Employee ID (support staff)', 'Budget line (BL QB) of the project that pays this person'], $b));
            foreach (FundingLine::with(['individual', 'budgetLine'])->get() as $f) {
                $w->addRow(Row::fromValues([$f->individual?->code, $f->budgetLine?->code]));
            }
        } else {
            $w->getCurrentSheet()->setName('Opening');
            $w->addRow(Row::fromValues(['Budget line (BL QB)', 'Spent up to the date (USD, from QuickBooks Budget vs Actuals)', 'Date (YYYY-MM-DD)'], $b));
            $ids = FundingLine::pluck('budget_line_id')->merge(\App\Models\CostCategoryLine::pluck('budget_line_id'))->unique();
            foreach (BudgetLine::whereIn('id', $ids)->orderBy('code')->get() as $bl) {
                $w->addRow(Row::fromValues([$bl->code, $bl->spent_before !== null ? (float) $bl->spent_before : '', $bl->spent_before_date]));
            }
        }
        $w->close();
        return response()->download($path, $kind === 'funding' ? 'Funding_lines.xlsx' : 'Opening_balances.xlsx')->deleteFileAfterSend();
    }
}
