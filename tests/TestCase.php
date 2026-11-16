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

    /** A user with exactly these abilities (through one role), two-factor not needed. */
    protected function userWith(array $perms, string $email = 'clerk@violet.local'): User
    {
        $role = \App\Models\Role::create(['name' => 'Test ' . uniqid(), 'permissions' => $perms]);
        $u = User::create(['name' => 'Clerk', 'email' => $email, 'password' => bcrypt('Password-123'), 'role' => 'user', 'active' => true]);
        $u->roles()->attach($role);
        return $u->fresh();
    }

    /** A valid PTR: 1,622 USD invoice paid in SYP at 122 (= 197,884 SYP) on the first budget line. */
    protected function ptrPayload(array $over = []): string
    {
        $ac = \App\Models\AccountingCenter::orderBy('id')->firstOrFail();
        // the PTR needs the unit "<accounting center>-<payment currency>" in the cost centers (master data)
        \App\Models\CostCenter::firstOrCreate(['code' => $ac->code . '-SYP'], ['name' => $ac->code . ' SYP', 'accounting_center_id' => $ac->id, 'currency_id' => $this->cur('SYP')->id]);
        return json_encode(array_replace([
            'date' => '2026-11-05',
            'accounting_center_id' => $ac->id,
            'invoice_currency_id' => $this->cur('USD')->id, 'payment_currency_id' => $this->cur('SYP')->id,
            'inv_per_usd' => 1, 'pay_per_usd' => 122, 'description' => 'Test payment',
            'lines' => [['invoice_amount' => 1622, 'budget_line_id' => \App\Models\BudgetLine::value('id')]],
        ], $over));
    }

    /** Reserves a transaction number through the page, as a user does before filling a form. */
    protected function reserve(?User $u = null, string $date = '2026-11-05'): \App\Models\Reservation
    {
        $this->actingAs($u ?? $this->admin())->postJson('/numbers', ['date' => $date, 'txn_type_id' => \App\Models\TxnType::where('need_project', false)->where('need_cost_center', false)
            ->where('pr_field', '!=', 'required')->value('id'), 'description' => 'Test'])->assertOk();
        return \App\Models\Reservation::latest('id')->firstOrFail();
    }

    protected function cur(string $code): Currency
    {
        return Currency::firstOrCreate(['code' => $code], ['name' => $code, 'usd_rate' => 1, 'in_usd' => in_array($code, ['EUR', 'GBP', 'KWD'])]);
    }
}
