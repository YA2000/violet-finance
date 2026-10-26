<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/** Excel imports of HR: contracts, LOE, monthly attendance. All or nothing: any error and nothing is saved. */
class PayrollImport
{
    /** First sheet rows (without the header), with their Excel row number. */
    protected static function rows(string $file): array
    {
        $reader = new Reader();
        $reader->open($file);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $i = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $i++;
                if ($i === 1) {
                    continue;
                }
                $c = $row->toArray();
                if (!array_filter($c, fn ($x) => $x !== null && $x !== '')) {
                    continue;
                }
                if (array_filter($c, fn ($x) => is_string($x) && str_starts_with($x, 'Example - delete'))) {
                    continue;
                }
                $out[$i] = $c;
            }
            break;
        }
        $reader->close();
        return $out;
    }

    protected static function date($v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance($v)->startOfDay();
        }
        if (is_numeric($v)) {   // Excel serial
            return Carbon::create(1899, 12, 30)->addDays((int) $v);
        }
        $s = trim((string) $v);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'Y/m/d'] as $f) {
            try {
                $d = Carbon::createFromFormat('!' . $f, $s);
                if ($d && $d->format($f) === $s) {
                    return $d;
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    protected static function num($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace([',', ' '], ['', ''], (string) $v);
        return is_numeric($s) ? (float) $s : null;
    }

    /** @return array{0:int, 1:array} saved count, errors */
    public static function contracts(string $file): array
    {
        $errors = [];
        $save = [];
        foreach (static::rows($file) as $n => $c) {
            [$id, $pos, $office, $emp, $basis, $rate, $cur, $fte, $start, $end, $bl, $number, $notes] = array_pad($c, 13, null);
            $ind = Payroll::findIndividual((string) $id);
            if (!$ind) {
                $errors[] = "Row $n: employee '$id' is not in Individuals.";
                continue;
            }
            $office = ucfirst(strtolower(trim((string) $office))) ?: 'Syria';
            if (!isset(Contract::OFFICES[$office])) {
                $errors[] = "Row $n: office must be Syria or Turkey.";
                continue;
            }
            $emp = strtolower(trim((string) $emp)) ?: 'full_time';
            $basis = strtolower(trim((string) $basis)) ?: 'monthly';
            if (!isset(Contract::EMPLOYMENT[$emp])) {
                $errors[] = "Row $n: employment '$emp' is not one of " . implode(', ', array_keys(Contract::EMPLOYMENT)) . '.';
                continue;
            }
            if (!isset(Contract::BASIS[$basis])) {
                $errors[] = "Row $n: pay basis '$basis' is not one of " . implode(', ', array_keys(Contract::BASIS)) . '.';
                continue;
            }
            $r = static::num($rate);
            if ($r === null || $r < 0) {
                $errors[] = "Row $n: rate is not a number.";
                continue;
            }
            $s = static::date($start);
            if (!$s) {
                $errors[] = "Row $n: start date '$start' is not a date (YYYY-MM-DD).";
                continue;
            }
            $e = static::date($end);
            if ($end !== null && $end !== '' && !$e) {
                $errors[] = "Row $n: end date '$end' is not a date.";
                continue;
            }
            if ($e && $e->lt($s)) {
                $errors[] = "Row $n: the contract ends before it starts.";
                continue;
            }
            $blm = null;
            if ($bl !== null && trim((string) $bl) !== '') {
                $blm = Payroll::findBudgetLine((string) $bl);
                if (!$blm) {
                    $errors[] = "Row $n: budget line '$bl' does not exist.";
                    continue;
                }
            }
            $f = static::num($fte) ?? 1.0;
            $save[] = ['individual_id' => $ind->id, 'position' => trim((string) $pos) ?: $ind->title, 'office' => $office, 'employment' => $emp,
                'pay_basis' => $basis, 'rate' => $r, 'currency' => strtoupper(trim((string) $cur)) ?: ($office === 'Turkey' ? 'TRY' : 'USD'),
                'fte' => $f > 0 ? $f : 1, 'start_date' => $s->toDateString(), 'end_date' => $e?->toDateString(), 'default_budget_line_id' => $blm?->id,
                'number' => $number !== null ? trim((string) $number) : null, 'notes' => $notes, 'active' => true];
        }
        if ($errors) {
            return [0, $errors];
        }
        DB::transaction(function () use ($save) {
            foreach ($save as $v) {
                // same employee + same start date = update of that contract
                $c = Contract::where('individual_id', $v['individual_id'])->whereDate('start_date', $v['start_date'])->first() ?? new Contract();
                $c->fill($v)->save();
            }
        });
        return [count($save), []];
    }

    /** @return array{0:int, 1:array} employees saved, errors */
    public static function loe(string $file, ?User $user): array
    {
        $errors = [];
        $by = [];
        foreach (static::rows($file) as $n => $c) {
            [$month, $id, , $bl, $pc] = array_pad($c, 5, null);
            try {
                $period = Payroll::period($month instanceof \DateTimeInterface ? $month : (string) $month);
            } catch (\Throwable) {
                $errors[] = "Row $n: month '$month' is not valid (YYYY-MM).";
                continue;
            }
            $ind = Payroll::findIndividual((string) $id);
            if (!$ind) {
                $errors[] = "Row $n: employee '$id' is not in Individuals.";
                continue;
            }
            if ($bl === null || trim((string) $bl) === '') {
                $errors[] = "Row $n: budget line is empty.";
                continue;
            }
            $blm = Payroll::findBudgetLine((string) $bl);
            if (!$blm) {
                $errors[] = "Row $n: budget line '$bl' does not exist.";
                continue;
            }
            $p = static::num($pc);
            if ($p === null || $p <= 0 || $p > 100) {
                $errors[] = "Row $n: percent must be between 0 and 100.";
                continue;
            }
            if ($p <= 1) {     // 0.65 written as a fraction
                $p *= 100;
            }
            if (Payroll::loeLocked($period)) {
                $errors[] = "Row $n: the payroll of " . $period->format('M Y') . ' is approved; its LOE cannot change.';
                continue;
            }
            $by[$period->toDateString()][$ind->id][] = ['budget_line_id' => $blm->id, 'percent' => $p, 'row' => $n, 'code' => $ind->code];
        }
        foreach ($by as $per => $people) {
            foreach ($people as $rows) {
                $sum = array_sum(array_column($rows, 'percent'));
                if (abs($sum - 100) > Payroll::TOLERANCE) {
                    $errors[] = 'Employee ' . $rows[0]['code'] . ' (' . $per . '): rows ' . implode(', ', array_column($rows, 'row')) . ' add up to ' . round($sum, 3) . ' %, not 100 %.';
                }
            }
        }
        if ($errors) {
            return [0, $errors];
        }
        $n = 0;
        DB::transaction(function () use ($by, $user, &$n) {
            foreach ($by as $per => $people) {
                foreach ($people as $ind => $rows) {
                    Payroll::saveLoe(Carbon::parse($per), (int) $ind, $rows, $user, 'import');
                    $n++;
                }
            }
        });
        return [$n, []];
    }

    /** Worked units and amounts of a run. @return array{0:int, 1:array} */
    public static function attendance(PayrollRun $run, string $file): array
    {
        $errors = [];
        $save = [];
        $lines = $run->lines()->with('individual')->get()->groupBy('individual_id');
        foreach (static::rows($file) as $n => $c) {
            [$id, , , $units, $add, $ded, $ins, $tax, $ind, $notes] = array_pad($c, 10, null);
            $person = Payroll::findIndividual((string) $id);
            if (!$person || !isset($lines[$person->id])) {
                $errors[] = "Row $n: employee '$id' has no payroll line this month (check the contract).";
                continue;
            }
            $vals = ['units' => static::num($units), 'additions' => static::num($add) ?? 0.0, 'deductions' => static::num($ded) ?? 0.0];
            if ($vals['units'] === null || $vals['units'] < 0) {
                $errors[] = "Row $n: worked days / shifts / hours must be a number.";
                continue;
            }
            foreach (['tr_insurance' => $ins, 'tr_tax' => $tax, 'tr_indirim' => $ind] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $vals[$k] = static::num($v) ?? 0.0;
                }
            }
            $vals['notes'] = $notes;
            $save[$person->id] = $vals;
        }
        if ($errors) {
            return [0, $errors];
        }
        DB::transaction(function () use ($save, $lines, $run) {
            foreach ($save as $ind => $vals) {
                // one employee with two contracts in the month: the units go to the last contract
                $line = $lines[$ind]->sortByDesc('id')->first();
                if (!$line->isTurkey()) {
                    unset($vals['tr_insurance'], $vals['tr_tax'], $vals['tr_indirim']);
                }
                $line->fill($vals);
                $line->manual = true;
                Payroll::compute($line, $run);
                $line->save();
            }
            Payroll::dropAllocation($run);
        });
        return [count($save), []];
    }
}
