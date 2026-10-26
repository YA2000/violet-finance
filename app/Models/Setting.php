<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected static array $cache = [];

    public const DEFAULTS = [
        'org_name' => 'Violet  Organization',
        'org_name_tr' => 'Meneşke Derneği',
        'org_logo' => null,
        'max_attachment_mb' => '10',
        'idle_minutes' => '30',
        'due_soon_days' => '7',
        'pay_through_remittance' => '1',
        'uploader_email_to' => 'System.Admin@violetsyria.org',
        'uploader_email_cc' => 'ibrahim.shawish@violetsyria.org',
        // payroll
        'payroll_core_project' => '1',
        'payroll_days_basis' => '30',
        'payroll_daily_full_month' => '26',
        'payroll_shift_full_month' => '22',
        'payroll_hourly_full_month' => '176',
        'payroll_lock_loe' => '1',
    ];

    public static function get(string $key, $default = null)
    {
        if (!array_key_exists($key, static::$cache)) {
            static::$cache[$key] = static::query()->where('key', $key)->value('value');
        }
        $v = static::$cache[$key];
        return $v ?? $default ?? (static::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, $value): void
    {
        static::query()->updateOrInsert(['key' => $key], ['value' => $value]);
        static::$cache[$key] = $value;
    }
}
