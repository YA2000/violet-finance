<?php

namespace App\Support;

use App\Models\FormType;
use App\Models\FormVersion;
use App\Models\Setting;

/**
 * Version 10 (design): fields that do not matter for the data typed so far are hidden on the entry screen
 * (hide_when). A PTR in US dollars shows no exchange rates; a rate that is fixed to 1 is not shown at all.
 * Prints, uploads and stored data do not change. Only adds hide_when where the administrator has none.
 */
class V10
{
    public static function run(): void
    {
        if (Setting::get('upgrade_v10_done')) {
            return;
        }
        $inv = 'LOOKUP(invoice_currency_id,"code")';
        $pay = 'LOOKUP(payment_currency_id,"code")';
        $noCur = 'OR(ISBLANK(invoice_currency_id),ISBLANK(payment_currency_id))';
        static::patch('ptr', [
            'home_currency' => 'TRUE',                                                   // always USD
            'cost_center_name' => 'cost_center_name=""',
            'cost_center_location' => 'cost_center_location=""',
            'inv_per_usd' => "OR($noCur,$inv=\"USD\")",
            'home_ex_source_id' => "OR($noCur,$inv=\"USD\")",
            'pay_per_usd' => "OR($noCur,$pay=\"USD\",payment_currency_id=invoice_currency_id)",
            'ex_source_id' => "OR($noCur,$pay=\"USD\",payment_currency_id=invoice_currency_id)",
            'exchange_rate' => "OR($noCur,$inv=\"USD\",$pay=\"USD\",payment_currency_id=invoice_currency_id)",
            'rate_check' => 'rate_check=""',
            'home_rate' => "OR($noCur,$inv=\"USD\")",
            'pay_rate' => "OR($noCur,$pay=\"USD\",payment_currency_id=invoice_currency_id)",
        ]);
        $usd = 'OR(ISBLANK(currency_id),LOOKUP(currency_id,"code")="USD")';
        static::patch('jar', [
            'adv_market' => $usd, 'inv_market' => $usd, 'advance_rate' => $usd, 'invoice_rate' => $usd,
            'jar_rate_check' => 'jar_rate_check=""',
        ]);
        Setting::put('upgrade_v10_done', date('c'));
        FormType::flush();
    }

    protected static function patch(string $code, array $rules): void
    {
        $ft = FormType::where('code', $code)->first();
        if (!$ft || !$ft->schema) {
            return;
        }
        $p = new SchemaPatch($ft->schema);
        foreach ($rules as $key => $when) {
            $f = $p->get($key, '');
            if ($f && empty($f['hide_when'])) {
                $p->set($key, ['hide_when' => $when], '');
            }
        }
        if ($p->changed) {
            FormVersion::create(['form_type_id' => $ft->id, 'schema' => $ft->schema, 'note' => 'Before system update v10']);
            $ft->forceFill(['schema' => $p->schema])->save();
        }
    }
}
