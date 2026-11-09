<?php

namespace App\Support;

use App\Models\Document;
use App\Models\FormPrint;
use App\Models\FormType;
use App\Models\FormVersion;
use App\Models\Setting;

/**
 * Version 6: the PTR rates are typed the usual way (1 USD = 122 SYP); the QuickBooks rates (1 unit = ? USD) are calculated.
 * Called from Upgrade::run(), once per database.
 */
class V6
{
    public static function run(): void
    {
        if (Setting::get('upgrade_v6_done') || !FormType::where('code', 'ptr')->whereNotNull('schema')->exists()) {
            return;
        }
        static::ptr();
        static::documents();
        static::arabic();
        Setting::put('upgrade_v6_done', date('c'));
        FormType::flush();
    }

    protected static function ptr(): void
    {
        $ft = FormType::where('code', 'ptr')->first();
        $p = new SchemaPatch($ft->schema);
        if (!$p->has('home_rate', '') || !$p->has('pay_rate', '')) {
            return;
        }
        $usd = fn ($f) => 'OR(ISBLANK(' . $f . '),LOOKUP(' . $f . ',"code")="USD")';
        if (!$p->has('inv_per_usd', '')) {
            $p->insertAfter('home_currency', ['key' => 'inv_per_usd', 'label' => 'Invoice Currency Rate (1 USD = ?)', 'type' => 'number',
                'decimals' => 4, 'default' => 1, 'width' => 4, 'required' => true], '');
        }
        if (!$p->has('pay_per_usd', '')) {
            $p->insertAfter('home_ex_source_id', ['key' => 'pay_per_usd', 'label' => 'Payment Currency Rate (1 USD = ?)', 'type' => 'number',
                'decimals' => 4, 'default' => 1, 'width' => 4, 'required' => true], '');
        }
        $p->set('inv_per_usd', ['lock_when' => $usd('invoice_currency_id'), 'lock_value' => '1',
            'hint' => 'Units of the invoice currency for 1 US dollar. Always 1 for USD.'], '');
        $p->set('pay_per_usd', ['lock_when' => 'OR(' . $usd('payment_currency_id') . ',payment_currency_id=invoice_currency_id)',
            'lock_value' => 'IF(AND(NOT(ISBLANK(payment_currency_id)),payment_currency_id=invoice_currency_id),inv_per_usd,1)',
            'hint' => 'Example: 122 Syrian pounds for 1 US dollar. Same as the invoice rate when the currencies match.'], '');
        $calc = ['type' => 'formula', 'fmt' => 'rate', 'decimals' => 7, 'default' => null, 'required' => null, 'lock_when' => null, 'lock_value' => null];
        $p->set('home_rate', $calc + ['label' => 'QuickBooks Rate - Invoice (1 = ? USD)', 'formula' => 'IF(inv_per_usd=0,0,ROUND(1/inv_per_usd,7))',
            'hint' => 'Calculated for QuickBooks: US dollars for 1 unit of the invoice currency.'], '');
        $p->set('pay_rate', $calc + ['label' => 'QuickBooks Rate - Payment (1 = ? USD)', 'formula' => 'IF(pay_per_usd=0,0,ROUND(1/pay_per_usd,7))',
            'hint' => 'Calculated: the exchange rate written on the QuickBooks bill.'], '');
        // 10 decimals: a total typed by the treasury (197,500 SYP) comes back exactly; amounts to pay are rounded to cents
        $p->set('expected_amount', ['formula' => 'IFERROR(ROUND(invoice_amount*IF(ISBLANK(allocation),1,allocation)*exchange_rate,2),0)'], 'lines');
        $p->replaceInSettings('invoice_amount*exchange_rate', 'ROUND(invoice_amount*exchange_rate,2)');
        $p->set('exchange_rate', ['formula' => 'IF(inv_per_usd=0,0,ROUND(pay_per_usd/inv_per_usd,10))', 'decimals' => 7,
            'hint' => 'Calculated: payment currency for 1 unit of the invoice currency.'], '');
        $p->order(['home_currency', 'inv_per_usd', 'home_ex_source_id', 'pay_per_usd', 'ex_source_id', 'exchange_rate', 'home_rate', 'pay_rate'], '');
        foreach (['home_currency' => 3, 'inv_per_usd' => 4, 'home_ex_source_id' => 5, 'pay_per_usd' => 4, 'ex_source_id' => 4, 'exchange_rate' => 4, 'home_rate' => 6, 'pay_rate' => 6] as $k => $w) {
            $p->set($k, ['width' => $w], '');
        }
        if ($p->changed) {
            FormVersion::create(['form_type_id' => $ft->id, 'schema' => $ft->schema, 'note' => 'Before system update v6']);
            $ft->forceFill(['schema' => $p->schema])->save();
        }
    }

    /** Saved PTRs keep exactly their amounts: the typed rates are the inverse of the stored QuickBooks rates. */
    protected static function documents(): void
    {
        foreach (Document::where('form_code', 'ptr')->get() as $doc) {
            $d = $doc->data ?? [];
            if (array_key_exists('inv_per_usd', $d)) {
                continue;
            }
            $h = (float) ($d['home_rate'] ?? 1);
            $pr = (float) ($d['pay_rate'] ?? 1);
            $d['inv_per_usd'] = $h > 0 ? round(1 / $h, 10) : 1;
            $d['pay_per_usd'] = $pr > 0 ? round(1 / $pr, 10) : 1;
            $doc->forceFill(['data' => $d])->saveQuietly();
        }
    }

    protected static function arabic(): void
    {
        foreach (FormPrint::where('sheet', 'Arabic PTR')->get() as $ar) {
            $ar->labels = array_merge($ar->labels ?? [], [
                'inv_per_usd' => 'سعر عملة الفاتورة (1 دولار = ?)', 'pay_per_usd' => 'سعر عملة الدفع (1 دولار = ?)',
                'home_rate' => 'سعر الكويك بوكس - الفاتورة (1 = ? دولار)', 'pay_rate' => 'سعر الكويك بوكس - الدفع (1 = ? دولار)',
            ]);
            $ar->save();
        }
    }
}
