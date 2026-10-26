<?php

namespace App\Support;

use App\Models\BudgetLine;
use App\Models\CostCategory;
use App\Models\CostCategoryLine;
use App\Models\Role;
use App\Models\Setting;

/** Expense categories of the shared-cost report, their budget lines per project (workbook "Data" sheet) and permissions. Once per database. */
class SharedCostInstall
{
    public const CATEGORIES = [
        ['2-101-1', 'Utilities-TR', 'Turkey', 'fte_office'],
        ['2-101-2', 'Supplies-TR', 'Turkey', 'fte_office'],
        ['2-101-3', 'Transportation-TR', 'Turkey', 'fte_office'],
        ['2-101-5', 'Office Rent-TR', 'Turkey', 'fte_office'],
        ['2-102-1', 'Utilities-SY', 'Syria', 'fte_office'],
        ['2-102-2', 'Supplies-SY', 'Syria', 'fte_office'],
        ['2-102-3', 'Transportation-SY', 'Syria', 'fte_office'],
        ['2-102-5', 'Office Rent-SY', 'Syria', 'fte_office'],
        ['2-102-8', 'Warehouse Rent-SY', 'Syria', 'fte_office'],
        ['2-201-9', 'Commission & PTT Fees', null, 'fte_all'],
    ];

    public static function run(): void
    {
        static::mapping();
        if (Setting::get('upgrade_shared_costs_done')) {
            return;
        }
        foreach (static::CATEGORIES as $i => [$code, $name, $office, $basis]) {
            CostCategory::firstOrCreate(['code' => $code], ['name' => $name, 'office' => $office, 'basis' => $basis, 'sequence' => $i + 1, 'active' => true]);
        }
        foreach (['Budgeting Officer' => ['costs.manage', 'costs.view'], 'Reporting Officer' => ['costs.view'], 'Internal Viewer' => ['costs.view'], 'AP Accountant' => ['costs.view']] as $name => $perms) {
            $role = Role::where('name', $name)->first();
            if ($role) {
                $role->permissions = array_values(array_unique(array_merge($role->permissions ?? [], $perms)));
                $role->save();
            }
        }
        Setting::put('upgrade_shared_costs_done', date('c'));
        static::mapping();
    }

    /** Budget lines per project and category from the workbook "Data" sheet. Waits until the budget lines exist (fresh install: seeded later). */
    public static function mapping(): void
    {
        if (Setting::get('upgrade_shared_costs_mapping') || !\Illuminate\Support\Facades\Schema::hasTable('cost_categories')
            || !CostCategory::exists() || !BudgetLine::exists()) {
            return;
        }
        $file = database_path('seeders/data/cost_category_lines.json');
        if (is_file($file)) {
            $cats = CostCategory::pluck('id', 'code');
            foreach (json_decode(file_get_contents($file), true) as $m) {
                $bl = BudgetLine::where('code', $m['bl'])->value('id');
                if ($bl && isset($cats[$m['category']])) {
                    CostCategoryLine::firstOrCreate(['cost_category_id' => $cats[$m['category']], 'project_code' => (string) $m['project']], ['budget_line_id' => $bl]);
                }
            }
        }
        Setting::put('upgrade_shared_costs_mapping', date('c'));
    }
}
