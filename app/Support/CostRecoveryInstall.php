<?php

namespace App\Support;

use App\Models\BudgetLine;
use App\Models\FundingLine;
use App\Models\Individual;
use App\Models\Setting;

/** Funding lines (which project budget line pays which support employee) from the Sep-2026 staff allocation sheet. Waits for the master data. */
class CostRecoveryInstall
{
    public static function run(): void
    {
        if (Setting::get('upgrade_cost_recovery_done') || !BudgetLine::exists() || !Individual::exists()) {
            return;
        }
        $file = database_path('seeders/data/funding_lines.json');
        if (is_file($file)) {
            $core = (string) Setting::get('payroll_core_project');
            foreach (json_decode(file_get_contents($file), true) as $m) {
                $bl = BudgetLine::where('code', $m['bl'])->first();
                $ind = Individual::whereRaw('LOWER(code) = ?', [mb_strtolower($m['employee'])])->first();
                if ($bl && $ind && (string) $bl->project_code !== $core) {
                    FundingLine::firstOrCreate(['budget_line_id' => $bl->id, 'individual_id' => $ind->id], ['project_code' => (string) $bl->project_code, 'active' => true]);
                }
            }
        }
        Setting::put('upgrade_cost_recovery_done', date('c'));
    }
}
