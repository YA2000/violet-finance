<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\CurrencyRate;
use Tests\TestCase;

/** Dated rates: the newest rate on or before the date; without any, the rate of the currency. */
class CurrencyRateTest extends TestCase
{
    public function test_uses_the_rate_in_force_on_the_date(): void
    {
        $syp = $this->cur('SYP');
        $syp->update(['usd_rate' => 1 / 130]);
        CurrencyRate::create(['currency_id' => $syp->id, 'date' => '2026-01-01', 'usd_rate' => 1 / 110]);
        CurrencyRate::create(['currency_id' => $syp->id, 'date' => '2026-06-01', 'usd_rate' => 1 / 120]);
        Currency::flush();

        $this->assertEqualsWithDelta(1 / 130, Currency::usdRate('SYP', '2025-12-31'), 1e-9);
        $this->assertEqualsWithDelta(1 / 110, Currency::usdRate('SYP', '2026-03-15'), 1e-9);
        $this->assertEqualsWithDelta(1 / 120, Currency::usdRate('SYP', '2026-06-01'), 1e-9);
        $this->assertEqualsWithDelta(1 / 130, Currency::usdRate('SYP'), 1e-9);
        $this->assertSame(1.0, (float) Currency::usdRate('USD', '2026-03-15'));
    }

    public function test_rates_page_takes_a_paste_from_excel(): void
    {
        $this->cur('SYP');
        $this->cur('EUR');
        $this->actingAs($this->admin())->post('/admin/rates', ['paste' => "2026-11-01\tSYP\t122\n2026-11-01\tEUR\t1.16"])->assertSessionHasNoErrors();
        Currency::flush();
        $this->assertEqualsWithDelta(1 / 122, Currency::usdRate('SYP', '2026-11-02'), 1e-9);
        $this->assertEqualsWithDelta(1.16, Currency::usdRate('EUR', '2026-11-02'), 1e-9);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'money')->count());
    }
}
