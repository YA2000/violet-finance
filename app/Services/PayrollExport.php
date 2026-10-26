<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\PayrollAllocation;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Setting;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Excel files of a payroll run and the templates HR fills. */
class PayrollExport
{
    protected static function writer(string $prefix): array
    {
        $path = storage_path('app/tmp/' . uniqid($prefix) . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        return [$w, $path];
    }

    protected static function bold(): Style
    {
        return (new Style())->setFontBold();
    }

    protected static function sheetName(string $name, array &$used): string
    {
        $n = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name), 0, 31);
        $base = $n;
        $i = 2;
        while (isset($used[mb_strtolower($n)])) {
            $n = mb_substr($base, 0, 27) . ' (' . $i++ . ')';
        }
        $used[mb_strtolower($n)] = true;
        return $n;
    }

    // ---------------------------------------------------------------- payroll of the month
    public static function payroll(PayrollRun $run): string
    {
        [$w, $path] = static::writer('pay_');
        $w->getCurrentSheet()->setName('Payroll ' . $run->tag());
        $w->addRow(Row::fromValues([Setting::get('org_name') . ' - Payroll ' . $run->label()], static::bold()));
        $w->addRow(Row::fromValues(['ID', 'Employee', 'Arabic name', 'Position', 'Office', 'Employment', 'Pay basis', 'Contract start', 'Contract end',
            'Rate', 'Currency', 'Days due', 'Worked', 'Basic', 'Additions', 'Deductions', 'Net', 'Insurance (TR)', 'Tax (TR)', 'Indirim (TR)',
            'Total cost', 'USD rate', 'Total USD', 'FTE', 'Notes'], static::bold()));
        $lines = $run->lines()->with(['individual', 'contract'])->get()->sortBy(fn ($l) => [$l->office, $l->individual?->name]);
        foreach ($lines as $l) {
            $c = $l->contract;
            $w->addRow(Row::fromValues([$l->individual?->code, $l->individual?->name, $l->individual?->name_ar, $c?->position, $l->office,
                Contract::EMPLOYMENT[$c?->employment] ?? $c?->employment, Contract::BASIS[$l->pay_basis] ?? $l->pay_basis,
                $c?->start_date?->format('Y-m-d'), $c?->end_date?->format('Y-m-d'), (float) $l->rate, $l->currency, (float) $l->units_due, (float) $l->units,
                (float) $l->basic, (float) $l->additions, (float) $l->deductions, (float) $l->net, (float) $l->tr_insurance, (float) $l->tr_tax, (float) $l->tr_indirim,
                (float) $l->total_cost, (float) $l->usd_rate, (float) $l->total_usd, (float) $l->fte_month, $l->notes]));
        }
        $w->addRow(Row::fromValues(['TOTAL', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', (float) $lines->sum('total_usd')], static::bold()));
        $w->close();
        return $path;
    }

    // ---------------------------------------------------------------- allocation sheets (all + one per project)
    public static function allocation(PayrollRun $run): string
    {
        [$w, $path] = static::writer('alloc_');
        $allocs = PayrollAllocation::with(['line.individual', 'line.contract', 'budgetLine'])->where('payroll_run_id', $run->id)->get();
        $projects = Project::whereIn('code', $allocs->pluck('project_code')->unique()->map(fn ($c) => (int) $c))->get()->keyBy(fn ($p) => (string) $p->code);
        $rows = static::allocationRows($run, $allocs, $projects);
        $used = [];

        // summary
        $w->getCurrentSheet()->setName(static::sheetName('Summary', $used));
        $w->addRow(Row::fromValues(['Payroll allocation ' . $run->label() . ($run->isDraft() ? ' (draft)' : '')], static::bold()));
        $w->addRow(Row::fromValues(['Project', 'Project name', 'People', 'FTE', 'Total USD', 'Share'], static::bold()));
        foreach (Payroll::byProject($run) as $p) {
            $w->addRow(Row::fromValues([$p['code'], $p['name'] . ($p['core'] ? ' (core fund)' : ''), $p['people'], round($p['fte'], 2), round($p['usd'], 2), round($p['share'], 4)]));
        }
        $w->addRow(Row::fromValues(['TOTAL', '', '', '', round($allocs->sum('amount_usd'), 2), 1], static::bold()));

        // every row, then one sheet per project (the sheet sent to each partner)
        $header = static::allocationHeader($run);
        $w->addNewSheetAndMakeItCurrent()->setName(static::sheetName('All projects', $used));
        $w->addRow(Row::fromValues($header, static::bold()));
        foreach ($rows as $r) {
            $w->addRow(Row::fromValues($r['cells']));
        }
        $w->addRow(Row::fromValues(static::totalRow($rows, count($header)), static::bold()));
        foreach (collect($rows)->groupBy('project') as $code => $g) {
            $p = $projects[(string) $code] ?? null;
            $w->addNewSheetAndMakeItCurrent()->setName(static::sheetName($code . ' ' . ($p?->name ?? ''), $used));
            $w->addRow(Row::fromValues([($p?->qb_name ?: $code) . ' - Personnel costs ' . $run->label()], static::bold()));
            $w->addRow(Row::fromValues($header, static::bold()));
            foreach ($g as $r) {
                $w->addRow(Row::fromValues($r['cells']));
            }
            $w->addRow(Row::fromValues(static::totalRow($g->all(), count($header)), static::bold()));
            $w->addRow(Row::fromValues([]));
            $w->addRow(Row::fromValues(['Prepared by:', '', '', '', 'Reviewed by:', '', '', '', 'Approved by:']));
            $w->addRow(Row::fromValues(['Date:', '', '', '', 'Date:', '', '', '', 'Date:']));
            $w->addRow(Row::fromValues(['Signature:', '', '', '', 'Signature:', '', '', '', 'Signature:']));
        }
        $w->close();
        return $path;
    }

    protected static function allocationHeader(PayrollRun $run): array
    {
        return ['Donor', 'Employee Name', 'Position', 'ID', 'Emp. Location', 'Component', 'Currency', 'Amount of the month', 'USD rate', 'Total Salary USD',
            'B.L', 'BL QB', '% ' . $run->tag(), 'Allocated ' . $run->tag(), 'Allocated USD ' . $run->tag(), 'Description'];
    }

    protected static function allocationRows(PayrollRun $run, $allocs, $projects): array
    {
        $out = [];
        foreach ($allocs as $a) {
            $l = $a->line;
            $comps = Payroll::components($l);
            $full = (float) ($comps[$a->component] ?? 0);
            $p = $projects[(string) $a->project_code] ?? null;
            $out[] = ['project' => (string) $a->project_code, 'usd' => (float) $a->amount_usd, 'cells' => [
                $p?->qb_name ?: $a->project_code, $l->individual?->name, $l->contract?->position ?? $l->individual?->title, $l->individual?->code, $l->office,
                ucfirst($a->component), $l->currency, $full, (float) $l->usd_rate, round($full * $l->usd_rate, 2),
                $a->budgetLine?->budget_line, $a->budgetLine?->code, round((float) $a->percent / 100, 5), (float) $a->amount, (float) $a->amount_usd,
                Payroll::description($run, $l, $a->component)]];
        }
        usort($out, fn ($x, $y) => [$x['project'], $x['cells'][1]] <=> [$y['project'], $y['cells'][1]]);
        return $out;
    }

    protected static function totalRow(array $rows, int $n): array
    {
        $r = array_fill(0, $n, '');
        $r[0] = 'TOTAL';
        $r[14] = round(array_sum(array_column($rows, 'usd')), 2);
        return $r;
    }

    // ---------------------------------------------------------------- QuickBooks (same columns as the PTR "Transactions" uploader)
    public static function uploader(PayrollRun $run): string
    {
        [$w, $path] = static::writer('qbpay_');
        $w->getCurrentSheet()->setName('Transactions');
        $w->addRow(Row::fromValues(['Vendor', 'Transaction Date', 'RefNumber', 'Memo', 'Items Item', 'Items Qty', 'Items Description', 'Items Cost',
            'Items Customer', 'AP Account', 'Currency', 'Exchange Rate'], static::bold()));
        $allocs = PayrollAllocation::with(['line.individual', 'line.contract', 'budgetLine'])->where('payroll_run_id', $run->id)->get()
            ->sortBy(fn ($a) => [$a->line->currency, $a->budgetLine?->accounting_center_code, $a->project_code, $a->line->individual?->name, $a->component]);
        $date = $run->period->copy()->endOfMonth()->format('Y-m-d');
        $memo = 'Salaries ' . $run->tag();
        foreach ($allocs as $a) {
            $l = $a->line;
            $bl = $a->budgetLine;
            $full = (float) (Payroll::components($l)[$a->component] ?? 0);
            $vendor = CostCenter::find2($bl?->accounting_center_code, $l->currency)?->name ?? '';
            $w->addRow(Row::fromValues([$vendor, $date, $run->ref_number ?: '', $memo, $bl?->qb_name ?: $bl?->code, round((float) $a->percent / 100, 5),
                Payroll::description($run, $l, $a->component), $full, $bl?->project_name, 'Accounts Payable-' . $l->currency, $l->currency,
                round((float) $l->usd_rate, 7)]));
        }
        $w->close();
        return $path;
    }

    // ---------------------------------------------------------------- FTE key for the running costs
    public static function fte(PayrollRun $run): string
    {
        [$w, $path] = static::writer('fte_');
        $w->getCurrentSheet()->setName('FTE ' . $run->tag());
        $w->addRow(Row::fromValues(['Office', 'Project', 'Project name', 'FTE', 'Share of the office'], static::bold()));
        $names = Project::pluck('qb_name', 'code')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();
        foreach (Payroll::fte($run) as $office => $rows) {
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues([$office, $r['code'], $names[$r['code']] ?? '', round($r['fte'], 4), round($r['share'], 6)]));
            }
        }
        $w->close();
        return $path;
    }

    // ---------------------------------------------------------------- templates
    public static function contractsTemplate(): string
    {
        [$w, $path] = static::writer('tpl_');
        $w->getCurrentSheet()->setName('Contracts');
        $w->addRow(Row::fromValues(['Employee ID', 'Position', 'Office (Syria/Turkey)', 'Employment (full_time/part_time/daily/shift)',
            'Pay basis (monthly/daily/shift/hourly)', 'Rate', 'Currency', 'FTE (1 = full time)', 'Start date', 'End date', 'Default budget line (optional)', 'Contract number', 'Notes',
            'Cost type (direct/shared)'], static::bold()));
        $w->addRow(Row::fromValues(['VI-HR-00229', 'Treasury Officer', 'Syria', 'full_time', 'monthly', 1100, 'USD', 1, '2026-01-01', '2026-12-31', '', '', 'Example - delete this row', 'shared']));
        $w->addRow(Row::fromValues(['VI-SR-04066', 'Nurse (Hospital)', 'Syria', 'shift', 'shift', 25, 'USD', 1, '2026-01-01', '2026-12-31', '209-202-1.3.1', '', 'Example - delete this row', 'direct']));
        static::listsSheet($w);
        $w->close();
        return $path;
    }

    public static function loeTemplate(\Illuminate\Support\Carbon $period): string
    {
        [$w, $path] = static::writer('tpl_');
        $w->getCurrentSheet()->setName('LOE ' . $period->format('M-Y'));
        $w->addRow(Row::fromValues(['Month (YYYY-MM)', 'Employee ID', 'Employee name (for reading only)', 'Budget line (BL QB code)', 'Percent'], static::bold()));
        $contracts = Contract::with('individual')->inMonth($period)->get()->unique('individual_id')->sortBy(fn ($c) => $c->individual?->name);
        $have = \App\Models\LoeEntry::with(['individual', 'budgetLine'])->whereDate('period', $period->toDateString())->get()->groupBy('individual_id');
        foreach ($contracts as $c) {
            $rows = $have[$c->individual_id] ?? collect();
            if ($rows->count()) {
                foreach ($rows as $e) {
                    $w->addRow(Row::fromValues([$period->format('Y-m'), $c->individual?->code, $c->individual?->name, $e->budgetLine?->code, (float) $e->percent]));
                }
            } else {
                $w->addRow(Row::fromValues([$period->format('Y-m'), $c->individual?->code, $c->individual?->name, $c->defaultBudgetLine?->code ?? '', $c->defaultBudgetLine ? 100 : '']));
            }
        }
        $w->close();
        return $path;
    }

    /**
     * HR monthly file: one block per employee. The first row of a block carries the attendance
     * (worked units, additions, deductions, Turkey bordro); every row carries one budget line and its percent.
     */
    public static function attendanceTemplate(PayrollRun $run): string
    {
        [$w, $path] = static::writer('tpl_');
        $w->getCurrentSheet()->setName('HR ' . $run->tag());
        $w->addRow(Row::fromValues(['Employee ID', 'Employee name (reading only)', 'Pay basis (reading only)', 'Worked days / shifts / hours', 'Additions', 'Deductions',
            'Insurance TRY (Turkey)', 'Tax TRY (Turkey)', 'Indirim TRY (Turkey)', 'Budget line (BL QB code)', 'Percent', 'Notes'], static::bold()));
        $lines = $run->lines()->with(['individual', 'contract.defaultBudgetLine'])->get()->sortBy(fn ($l) => mb_strtolower($l->individual?->name ?? ''));
        $loe = Payroll::loe($run, $lines);
        // shared support staff: no LOE to fill
        // nothing this month yet: last month's LOE as a starting point
        $prev = \App\Models\LoeEntry::with('budgetLine')->whereDate('period', $run->period->copy()->subMonth()->toDateString())->get()->groupBy('individual_id');
        $done = [];
        foreach ($lines as $l) {
            if (isset($done[$l->individual_id])) {
                continue;     // two contracts in the month: one block
            }
            $done[$l->individual_id] = true;
            $person = $lines->where('individual_id', $l->individual_id);
            $shared = $person->contains(fn ($x) => $x->contract?->cost_type === 'shared');
            $rows = $shared ? [] : ($loe[$l->individual_id] ?? []);
            if (!$shared && !$rows && isset($prev[$l->individual_id])) {
                $rows = $prev[$l->individual_id]->map(fn ($e) => ['bl' => $e->budgetLine, 'percent' => (float) $e->percent])->all();
            }
            $rows = $rows ?: [['bl' => null, 'percent' => null]];
            foreach (array_values($rows) as $i => $r) {
                $first = $i === 0;
                $w->addRow(Row::fromValues([$l->individual?->code, $first ? $l->individual?->name : '', $first ? $l->pay_basis : '',
                    $first ? (float) $person->sum('units') : '', $first ? (float) $person->sum('additions') : '', $first ? (float) $person->sum('deductions') : '',
                    $first && $l->isTurkey() ? (float) $l->tr_insurance : '', $first && $l->isTurkey() ? (float) $l->tr_tax : '', $first && $l->isTurkey() ? (float) $l->tr_indirim : '',
                    $shared ? 'PLAN' : ($r['bl']?->code ?? ''), $r['percent'] !== null ? round((float) $r['percent'], 3) : '', $first ? ($shared ? trim('Shared staff: LOE from the cost recovery plan. ' . $l->notes) : $l->notes) : '']));
            }
        }
        $w->close();
        return $path;
    }

    protected static function listsSheet(Writer $w): void
    {
        $w->addNewSheetAndMakeItCurrent()->setName('Lists');
        $w->addRow(Row::fromValues(['Office', 'Employment', 'Pay basis', 'Currency'], static::bold()));
        $o = array_keys(Contract::OFFICES);
        $e = array_keys(Contract::EMPLOYMENT);
        $b = array_keys(Contract::BASIS);
        $c = ['USD', 'TRY', 'SYP', 'EUR'];
        for ($i = 0; $i < 4; $i++) {
            $w->addRow(Row::fromValues([$o[$i] ?? '', $e[$i] ?? '', $b[$i] ?? '', $c[$i] ?? '']));
        }
    }
}
