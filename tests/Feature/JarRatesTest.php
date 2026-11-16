<?php

namespace Tests\Feature;

use App\Forms\FormRegistry;
use Tests\TestCase;

/** JAR rates are typed the market way too; the QuickBooks rates (1 unit = ? USD) are worked out. */
class JarRatesTest extends TestCase
{
    public function test_market_rates_become_quickbooks_rates(): void
    {
        $f = FormRegistry::get('jar');
        $d = $f->compute(['date' => '2026-11-01', 'currency_id' => $this->cur('SYP')->id, 'adv_market' => 122, 'inv_market' => 125]);
        $this->assertEqualsWithDelta(1 / 122, $d['advance_rate'], 1e-7);
        $this->assertEqualsWithDelta(1 / 125, $d['invoice_rate'], 1e-7);

        $d = $f->compute(['date' => '2026-11-01', 'currency_id' => $this->cur('EUR')->id, 'adv_market' => 1.16, 'inv_market' => 1.18]);
        $this->assertEqualsWithDelta(1.16, $d['advance_rate'], 1e-7);
        $this->assertStringContainsString('1 EUR = 1.18 USD', $d['jar_rate_check']);
    }
}
