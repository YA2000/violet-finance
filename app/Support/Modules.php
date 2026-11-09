<?php

namespace App\Support;

use App\Models\Setting;

/** Parts of the system that can be switched off in Settings (their data stays). */
class Modules
{
    /** module => path prefixes */
    public const PATHS = [
        'advances' => ['my-advances', 'expenses', 'settlements', 'advance-payments', 'advances', 'advance-holders'],
        'payroll' => ['my-loe', 'payroll', 'allocation-lab', 'cost-recovery', 'shared-costs'],
    ];

    public static function on(string $module): bool
    {
        return Setting::get('module_' . $module, '1') !== '0';
    }

    public static function blocks(string $path): ?string
    {
        $first = explode('/', trim($path, '/'))[0] ?? '';
        foreach (static::PATHS as $m => $prefixes) {
            if (in_array($first, $prefixes, true) && !static::on($m)) {
                return $m;
            }
        }
        return null;
    }
}
