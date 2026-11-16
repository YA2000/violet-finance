<?php

namespace Tests;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Every test starts from a fresh database (SQLite in memory, see phpunit.xml) filled by the normal seeders:
 * master data, the forms (PTR, JAR, ...), the administrator, then the upgrades - exactly like a new installation.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::put('two_factor_required', '0');   // tests that need two-factor turn it back on
        \App\Models\Setting::put('projects_refreshed_on', date('Y-m-d'));
        Currency::flush();
    }

    protected function admin(): User
    {
        return User::where('is_super', true)->firstOrFail();
    }

    protected function cur(string $code): Currency
    {
        return Currency::firstOrCreate(['code' => $code], ['name' => $code, 'usd_rate' => 1, 'in_usd' => in_array($code, ['EUR', 'GBP', 'KWD'])]);
    }
}
