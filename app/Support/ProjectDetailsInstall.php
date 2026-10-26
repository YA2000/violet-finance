<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * The running projects of 26-10-2026 (finance follow-up sheet): dates, budget, ICR, spent, remaining, received, office, manager.
 * Only these (and projects 1 and 2) stay active; the lines of the others are archived, the archived lines of these come back.
 */
class ProjectDetailsInstall
{
    public const ALWAYS = [1, 2];

    public static function run(): void
    {
        $file = database_path('seeders/data/running_projects.json');
        if (Setting::get('upgrade_project_details_done') || !Project::exists() || !is_file($file)) {
            return;
        }
        $rows = collect(json_decode(file_get_contents($file), true))->keyBy('code');
        DB::transaction(function () use ($rows) {
            if (!Currency::whereRaw('UPPER(code) = ?', ['DKK'])->exists()) {
                Currency::create(['code' => 'DKK', 'name' => 'Danish krone', 'active' => true, 'sequence' => 99]);
            }
            $cur = Currency::pluck('id', 'code')->mapWithKeys(fn ($v, $k) => [strtoupper($k) => $v]);
            foreach ($rows as $code => $r) {
                DB::table('projects')->where('code', $code)->update([
                    'status' => 'Ongoing', 'active' => true, 'start_date' => $r['start'], 'end_date' => $r['end'],
                    'currency_id' => $cur[strtoupper($r['currency'])] ?? DB::raw('currency_id'),
                    'budget_total' => $r['budget'], 'icr_rate' => $r['icr'], 'spent_total' => $r['spent'], 'icr_amount' => $r['icr_amount'],
                    'balance' => $r['remaining'], 'received' => $r['received'], 'donor_balance' => $r['donor_balance'], 'balance_date' => '2026-10-26',
                    'office' => $r['office'], 'manager' => $r['manager'], 'updated_at' => now(),
                ]);
            }
            $keep = array_merge($rows->keys()->all(), static::ALWAYS);
            DB::table('projects')->whereIn('code', static::ALWAYS)->update(['status' => 'Ongoing', 'active' => true]);
            DB::table('projects')->whereNotIn('code', $keep)->where('active', true)->update(['status' => 'Ended', 'active' => false, 'updated_at' => now()]);
            $keepS = array_map('strval', $keep);
            DB::table('budget_lines')->whereIn('project_code', $keepS)->whereNotNull('archived_at')->update(['active' => true, 'archived_at' => null]);
            DB::table('budget_lines')->whereNotIn('project_code', $keepS)->where('active', true)->update(['active' => false, 'archived_at' => now()]);
        });
        Setting::put('upgrade_project_details_done', date('c'));
    }
}
