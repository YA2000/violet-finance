<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * The running projects of Oct-2026 (list from finance): only these stay active, with their end date, budget and donor balance.
 * Every other project is set to Ended and its budget lines are archived. Projects 1 (Violet) and 2 (allocation) always stay.
 */
class ProjectStatusInstall
{
    /** code => [currency, budget, balance, end date] */
    public const RUNNING = [
        35 => ['USD', 98390.93, 14070.68, '2026-12-31'],
        113 => ['EUR', 326110.26, 260735.27, '2026-12-31'],
        124 => ['EUR', 100771.20, 95802.23, '2026-11-15'],
        128 => ['KWD', 5500.00, 256.21, '2026-12-31'],
        133 => ['USD', 500000.00, 401848.02, '2026-11-14'],
        134 => ['EUR', 22740.00, 19248.91, '2026-12-31'],
        135 => ['KWD', 64384.99, 42810.93, '2026-12-31'],
        151 => ['USD', 262206.22, 256257.56, '2026-10-31'],
        158 => ['EUR', 6570.23, 6570.23, '2026-12-31'],
        161 => ['EUR', 130073.58, 129725.82, '2026-12-31'],
        165 => ['EUR', 18145.45, 18145.45, '2026-11-15'],
        167 => ['USD', 687129.00, 687129.00, '2026-12-31'],
        199 => ['EUR', 27126.90, 27126.90, '2026-12-31'],
        203 => ['KWD', 142223.00, 142223.00, '2026-11-30'],
        204 => ['GBP', 33750.00, 33750.00, '2026-10-31'],
        206 => ['KWD', 14256.00, 14256.00, '2026-11-10'],
        255 => ['KWD', 5739.91, 5910.83, '2026-12-31'],
        261 => ['KWD', 15689.00, 15689.00, '2026-12-15'],
        262 => ['KWD', 5451.00, 5451.00, '2026-12-15'],
        263 => ['KWD', 10578.00, 10578.00, '2026-12-15'],
        264 => ['KWD', 13196.00, 13196.00, '2026-12-15'],
        265 => ['KWD', 12448.00, 12448.00, '2026-12-15'],
        275 => ['USD', 10000.00, 10000.00, '2026-10-31'],
        282 => ['USD', 64960.00, 64960.00, '2026-12-31'],
        283 => ['GBP', 37500.00, 37500.00, '2026-11-30'],
        286 => ['KWD', 9725.00, 9725.00, '2026-12-15'],
    ];
    public const ALWAYS = [1, 2];

    public static function run(): void
    {
        if (Setting::get('upgrade_project_status_done') || !Project::exists()) {
            return;
        }
        $keep = array_merge(array_keys(static::RUNNING), static::ALWAYS);
        DB::transaction(function () use ($keep) {
            $cur = Currency::pluck('id', 'code')->mapWithKeys(fn ($v, $k) => [strtoupper($k) => $v]);
            foreach (static::RUNNING as $code => [$c, $budget, $balance, $end]) {
                DB::table('projects')->where('code', $code)->update(array_filter(['status' => 'Ongoing', 'active' => true, 'end_date' => $end,
                    'budget_total' => $budget, 'balance' => $balance, 'balance_date' => '2026-10-26', 'currency_id' => $cur[$c] ?? null], fn ($v) => $v !== null));
            }
            DB::table('projects')->whereIn('code', static::ALWAYS)->update(['status' => 'Ongoing', 'active' => true]);
            DB::table('projects')->whereNotIn('code', $keep)->update(['status' => 'Ended', 'active' => false]);
            DB::table('budget_lines')->whereNotIn('project_code', array_map('strval', $keep))->where('active', true)->update(['active' => false, 'archived_at' => now()]);
        });
        Setting::put('upgrade_project_status_done', date('c'));
    }
}
