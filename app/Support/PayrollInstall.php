<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Setting;

/** Payroll permissions on the existing roles. Once per database; never removes what an administrator changed. */
class PayrollInstall
{
    public static function run(): void
    {
        if (Setting::get('upgrade_payroll_done')) {
            return;
        }
        $add = [
            'HR' => ['payroll.manage', 'payroll.view'],
            'Budgeting Officer' => ['payroll.view'],
            'Reporting Officer' => ['payroll.view'],
            'Internal Viewer' => ['payroll.view'],
        ];
        foreach ($add as $name => $perms) {
            $role = Role::where('name', $name)->first();
            if ($role) {
                $role->permissions = array_values(array_unique(array_merge($role->permissions ?? [], $perms)));
                if ($name === 'HR') {
                    $role->description = 'Human resources: individuals, contracts, LOE of every employee and the monthly payroll.';
                }
                $role->save();
            }
        }
        Setting::put('upgrade_payroll_done', date('c'));
    }
}
