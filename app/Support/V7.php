<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Document;
use App\Models\FormPrint;
use App\Models\FormType;
use App\Models\FormVersion;
use App\Models\Setting;

/**
 * Version 7: each currency is typed the way the market quotes it. Euro, pound, dinar...: US dollars for 1 unit (1 EUR = 1.16 USD);
 * the others: units for 1 US dollar (1 USD = 122 SYP). A line under the rates says it in words. Once per database.
 */
class V7
{
    public const IN_USD = ['EUR', 'GBP', 'KWD', 'CHF', 'JOD', 'BHD', 'OMR', 'KYD', 'GIP', 'FKP'];

    /** "1 unit = ? USD" from what was typed */
    public static function usd(string $f, string $cur): string
    {
        return 'IF(LOOKUP(' . $cur . ',"in_usd"),' . $f . ',1/' . $f . ')';
    }

    public static function run(): void
    {
        if (Setting::get('upgrade_v7_done') || !FormType::where('code', 'ptr')->whereNotNull('schema')->exists()) {
            return;
        }
        static::ptr();
        static::documents();
        static::arabic();
        Setting::put('upgrade_v7_done', date('c'));
        FormType::flush();
    }

    protected static function ptr(): void
    {
        $ft = FormType::where('code', 'ptr')->first();
        $p = new SchemaPatch($ft->schema);
        if (!$p->has('inv_per_usd', '') || !$p->has('pay_per_usd', '')) {
            return;
        }
        $inv = static::usd('inv_per_usd', 'invoice_currency_id');
        $pay = static::usd('pay_per_usd', 'payment_currency_id');
        $p->set('inv_per_usd', ['label' => 'Invoice Currency Rate', 'decimals' => 4,
            'hint' => 'Euro, pound, dinar: US dollars for 1 unit (1.16). Other currencies: units for 1 US dollar (122).'], '');
        $p->set('pay_per_usd', ['label' => 'Payment Currency Rate', 'decimals' => 4,
            'hint' => 'Same way. Equal to the invoice rate when the currencies match.'], '');
        $p->set('home_rate', ['formula' => 'IF(inv_per_usd=0,0,ROUND(' . $inv . ',7))'], '');
        $p->set('pay_rate', ['formula' => 'IF(pay_per_usd=0,0,ROUND(' . $pay . ',7))'], '');
        $p->set('exchange_rate', ['formula' => 'IF(OR(inv_per_usd=0,pay_per_usd=0),0,ROUND((' . $inv . ')/(' . $pay . '),10))'], '');
        $say = fn ($f, $cur) => 'IF(ISBLANK(' . $cur . '),"",IF(LOOKUP(' . $cur . ',"in_usd"),"1 "&LOOKUP(' . $cur . ',"code")&" = "&ROUND(' . $f . ',4)&" USD","1 USD = "&ROUND(' . $f . ',4)&" "&LOOKUP(' . $cur . ',"code")))';
        if (!$p->has('rate_check', '')) {
            $p->insertAfter('exchange_rate', ['key' => 'rate_check', 'label' => 'Check the rates', 'type' => 'formula', 'fmt' => 'text', 'width' => 12], '');
        }
        $isUsd = fn ($cur) => 'LOOKUP(' . $cur . ',"code")="USD"';
        $p->set('rate_check', ['formula' => 'IF(OR(ISBLANK(invoice_currency_id),ISBLANK(payment_currency_id)),"",TEXTJOIN("   ·   ",TRUE,'
            . 'IF(' . $isUsd('invoice_currency_id') . ',"",' . $say('inv_per_usd', 'invoice_currency_id') . '),'
            . 'IF(OR(' . $isUsd('payment_currency_id') . ',payment_currency_id=invoice_currency_id),"",' . $say('pay_per_usd', 'payment_currency_id') . '),'
            . 'IF(OR(' . $isUsd('invoice_currency_id') . ',' . $isUsd('payment_currency_id') . ',payment_currency_id=invoice_currency_id),"",'
            . '"1 "&LOOKUP(invoice_currency_id,"code")&" = "&ROUND(exchange_rate,4)&" "&LOOKUP(payment_currency_id,"code"))))',
            'hint' => 'Read it once before saving: a rate typed the wrong way round shows here at once.'], '');
        if ($p->changed) {
            FormVersion::create(['form_type_id' => $ft->id, 'schema' => $ft->schema, 'note' => 'Before system update v7']);
            $ft->forceFill(['schema' => $p->schema])->save();
        }
    }

    /** Saved PTRs keep their amounts: for euro, pound... the stored "per USD" figure becomes "USD per unit". */
    protected static function documents(): void
    {
        $strong = Currency::where('in_usd', true)->pluck('id')->map(fn ($x) => (int) $x)->all();
        if (!$strong) {
            return;
        }
        foreach (Document::where('form_code', 'ptr')->get() as $doc) {
            $d = $doc->data ?? [];
            $ch = false;
            foreach (['inv_per_usd' => 'invoice_currency_id', 'pay_per_usd' => 'payment_currency_id'] as $f => $cur) {
                if (isset($d[$f]) && in_array((int) ($d[$cur] ?? 0), $strong, true) && (float) $d[$f] > 0) {
                    $d[$f] = round(1 / (float) $d[$f], 10);
                    $ch = true;
                }
            }
            if ($ch) {
                $doc->forceFill(['data' => $d])->saveQuietly();
            }
        }
    }

    protected static function arabic(): void
    {
        foreach (FormPrint::where('sheet', 'Arabic PTR')->get() as $ar) {
            $ar->labels = array_merge($ar->labels ?? [], ['inv_per_usd' => 'سعر عملة الفاتورة', 'pay_per_usd' => 'سعر عملة الدفع', 'rate_check' => 'تدقيق الأسعار']);
            $ar->save();
        }
    }
}
