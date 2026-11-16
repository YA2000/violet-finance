<?php

namespace Tests\Feature;

use App\Forms\FormRegistry;
use App\Support\TreasuryRate;
use Tests\TestCase;

/**
 * PTR exchange rates, typed the way the market says them:
 *   euro, pound, dinar: US dollars for 1 unit (1 EUR = 1.16 USD)
 *   the others:         units for 1 US dollar (1 USD = 122 SYP)
 */
class PtrRatesTest extends TestCase
{
    private function ptr(array $d): array
    {
        return FormRegistry::get('ptr')->compute($d + ['date' => '2026-11-01']);
    }

    public function test_euro_invoice_paid_in_dollars(): void
    {
        $d = $this->ptr(['invoice_currency_id' => $this->cur('EUR')->id, 'payment_currency_id' => $this->cur('USD')->id,
            'inv_per_usd' => 1.16, 'pay_per_usd' => 1, 'lines' => [['invoice_amount' => 1500]]]);
        $this->assertEqualsWithDelta(1740.00, $d['lines'][0]['expected_amount'], 0.001);   // not 1,293 (the old bug)
        $this->assertEqualsWithDelta(1740.00, $d['lines'][0]['home_amount'], 0.001);
    }

    public function test_dollar_invoice_paid_in_syrian_pounds(): void
    {
        $d = $this->ptr(['invoice_currency_id' => $this->cur('USD')->id, 'payment_currency_id' => $this->cur('SYP')->id,
            'inv_per_usd' => 1, 'pay_per_usd' => 122, 'lines' => [['invoice_amount' => 1622]]]);
        $this->assertEqualsWithDelta(197884.00, $d['lines'][0]['expected_amount'], 0.001);
        $this->assertEqualsWithDelta(1622.00, $d['lines'][0]['home_amount'], 0.001);
    }

    public function test_allocation_splits_a_line(): void
    {
        $d = $this->ptr(['invoice_currency_id' => $this->cur('USD')->id, 'payment_currency_id' => $this->cur('USD')->id,
            'inv_per_usd' => 1, 'pay_per_usd' => 1, 'lines' => [['invoice_amount' => 1000, 'allocation' => 0.25], ['invoice_amount' => 1000, 'allocation' => 0.75]]]);
        $this->assertEqualsWithDelta(1000.00, $d['total_expected'], 0.001);
    }

    public function test_treasury_works_out_the_rate_from_the_amount_paid(): void
    {
        $doc = new \App\Models\Document(['form_code' => 'ptr', 'data' => ['invoice_currency_id' => $this->cur('USD')->id,
            'payment_currency_id' => $this->cur('SYP')->id, 'inv_per_usd' => 1, 'pay_per_usd' => 122, 'lines' => [['invoice_amount' => 1622]]]]);
        $t = TreasuryRate::for($doc);
        $this->assertSame('pay', $t['mode']);
        $this->assertEqualsWithDelta(125.0, TreasuryRate::rateFor($t, 1622 * 125), 0.0001);
    }

    public function test_rates_that_do_not_matter_are_hidden(): void
    {
        $f = FormRegistry::get('ptr');
        $f->compute(['date' => '2026-11-01', 'invoice_currency_id' => $this->cur('USD')->id, 'payment_currency_id' => $this->cur('USD')->id]);
        $this->assertArrayHasKey('inv_per_usd', $f->hiddenNow);
        $this->assertArrayHasKey('pay_per_usd', $f->hiddenNow);
        $this->assertArrayHasKey('exchange_rate', $f->hiddenNow);

        $f->compute(['date' => '2026-11-01', 'invoice_currency_id' => $this->cur('USD')->id, 'payment_currency_id' => $this->cur('SYP')->id, 'pay_per_usd' => 122]);
        $this->assertArrayHasKey('inv_per_usd', $f->hiddenNow);        // fixed to 1
        $this->assertArrayNotHasKey('pay_per_usd', $f->hiddenNow);     // the one rate to type
        $this->assertArrayNotHasKey('rate_check', $f->hiddenNow);
    }
}
