<?php

namespace App\Services;

use App\Models\CostCategory;
use App\Models\CostCenter;
use App\Models\ExpenseAllocation;
use App\Models\ExpenseRun;
use App\Models\Project;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class SharedCostExport
{
    protected static function open(string $prefix): array
    {
        $path = storage_path('app/tmp/' . uniqid($prefix) . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        return [$w, $path, (new Style())->setFontBold()];
    }

    public static function allocation(ExpenseRun $run): string
    {
        [$w, $path, $b] = static::open('shared_');
        $cats = CostCategory::pluck('name', 'code');
        $names = Project::pluck('qb_name', 'code')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();

        $w->getCurrentSheet()->setName('Summary');
        $w->addRow(Row::fromValues(['Shared costs allocation ' . $run->label() . ($run->isDraft() ? ' (draft)' : '')], $b));
        $w->addRow(Row::fromValues(['Project', 'Project name', 'By key (FTE)', 'Direct', 'Gaps carried (core)', 'Total USD', 'Share'], $b));
        foreach (SharedCosts::byProject($run) as $p) {
            $w->addRow(Row::fromValues([$p['code'], $p['name'] . ($p['core'] ? ' (core fund)' : ''), round($p['usd'] - $p['direct'] - $p['gap'], 2), round($p['direct'], 2), round($p['gap'], 2), round($p['usd'], 2), round($p['share'], 4)]));
        }

        $m = SharedCosts::matrix($run);
        $projects = collect($m)->flatMap(fn ($r) => array_keys($r))->unique()->sort(SORT_NATURAL)->values()->all();
        $w->addNewSheetAndMakeItCurrent()->setName('By category');
        $w->addRow(Row::fromValues(array_merge(['Category', 'Name'], $projects, ['Total']), $b));
        foreach ($m as $cat => $r) {
            $w->addRow(Row::fromValues(array_merge([$cat, $cats[$cat] ?? ''], array_map(fn ($p) => round($r[$p] ?? 0, 2), $projects), [round(array_sum($r), 2)])));
        }

        $w->addNewSheetAndMakeItCurrent()->setName('Gaps');
        $w->addRow(Row::fromValues(['Project without a budget line', 'Project name', 'Category', 'Category name', 'Carried by the core fund (USD)'], $b));
        foreach (SharedCosts::gaps($run) as $g) {
            $w->addRow(Row::fromValues([$g['project'], $g['name'], $g['category'], $g['category_name'], round($g['usd'], 2)]));
        }

        $w->addNewSheetAndMakeItCurrent()->setName('Detail');
        $w->addRow(Row::fromValues(['Date', 'Num', 'Description', 'Category', 'Line amount USD', 'Project', 'Project name', 'BL QB', 'Share', 'Allocated USD', 'How', 'Share of project'], $b));
        $allocs = ExpenseAllocation::with(['line', 'budgetLine'])->where('expense_run_id', $run->id)->get()->sortBy(fn ($a) => [$a->line->row_no, $a->project_code]);
        foreach ($allocs as $a) {
            $l = $a->line;
            $w->addRow(Row::fromValues([$l->txn_date?->format('Y-m-d'), $l->num, $l->description, $l->category_code, (float) $l->amount_usd, $a->project_code,
                $names[$a->project_code] ?? '', $a->budgetLine?->code, round((float) $a->share, 6), (float) $a->amount_usd,
                ['key' => 'FTE key', 'direct' => 'Direct', 'gap' => 'Gap → core'][$a->kind] ?? $a->kind, $a->gap_from]));
        }
        $w->close();
        return $path;
    }

    /** Same columns as the PTR "Transactions" uploader, one line per project share. */
    public static function uploader(ExpenseRun $run): string
    {
        [$w, $path, $b] = static::open('qbshared_');
        $w->getCurrentSheet()->setName('Transactions');
        $w->addRow(Row::fromValues(['Vendor', 'Transaction Date', 'RefNumber', 'Memo', 'Items Item', 'Items Qty', 'Items Description', 'Items Cost',
            'Items Customer', 'AP Account', 'Currency', 'Exchange Rate'], $b));
        $date = $run->period->copy()->endOfMonth()->format('Y-m-d');
        $allocs = ExpenseAllocation::with(['line', 'budgetLine'])->where('expense_run_id', $run->id)->get()
            ->sortBy(fn ($a) => [$a->budgetLine?->accounting_center_code, $a->project_code, $a->line->row_no]);
        foreach ($allocs as $a) {
            $bl = $a->budgetLine;
            $l = $a->line;
            $w->addRow(Row::fromValues([CostCenter::find2($bl?->accounting_center_code, 'USD')?->name ?? '', $date, $run->ref_number ?: '', 'Shared costs ' . $run->tag(),
                $bl?->qb_name ?: $bl?->code ?: '(no budget line)', 1, trim(($l->num ? $l->num . ' - ' : '') . $l->description),
                (float) $a->amount_usd, $bl?->project_name, 'Accounts Payable-USD', 'USD', 1]));
        }
        $w->close();
        return $path;
    }
}
