<?php

namespace App\Support;

use App\Models\FormType;
use App\Models\Setting;
use App\Services\Expenses;
use Illuminate\Support\Facades\Schema;

/**
 * Version 9: expense lines written from every saved form; the JAR rates typed the market way like the PTR
 * (1 EUR = 1.16 USD, 1 USD = 122 SYP) with the QuickBooks rates calculated. Once per database.
 */
class V9
{
    public static function run(): void
    {
        if (Setting::get('upgrade_v9_done') || !Schema::hasTable('spend_lines')) {
            return;
        }
        static::jar();
        Expenses::rebuild();
        Setting::put('upgrade_v9_done', date('c'));
        FormType::flush();
    }

    /** advance_rate / invoice_rate stay (QuickBooks: 1 unit = ? USD) but are calculated from rates typed the market way. */
    protected static function jar(): void
    {
        $ft = FormType::where('code', 'jar')->first();
        if (!$ft || !$ft->schema) {
            return;
        }
        $p = new SchemaPatch($ft->schema);
        if (!$p->has('advance_rate', '') || !$p->has('invoice_rate', '') || $p->has('inv_market', '')) {
            return;
        }
        $usd = 'OR(ISBLANK(currency_id),LOOKUP(currency_id,"code")="USD")';
        $toQb = fn ($f) => 'IF(' . $f . '=0,0,ROUND(IF(LOOKUP(currency_id,"in_usd"),' . $f . ',1/' . $f . '),7))';
        $say = fn ($f) => 'IF(' . $usd . ',"",IF(LOOKUP(currency_id,"in_usd"),"1 "&LOOKUP(currency_id,"code")&" = "&ROUND(' . $f . ',4)&" USD","1 USD = "&ROUND(' . $f . ',4)&" "&LOOKUP(currency_id,"code")))';
        $p->insertAfter('currency_id', ['key' => 'adv_market', 'label' => 'Advance Rate', 'type' => 'number', 'decimals' => 4, 'default' => 1, 'width' => 3,
            'required' => true, 'lock_when' => $usd, 'lock_value' => '1',
            'hint' => 'The way the market says it: euro, pound, dinar = US dollars for 1 unit (1.16); others = units for 1 US dollar (122).'], '');
        $p->insertAfter('adv_market', ['key' => 'inv_market', 'label' => 'Invoices Rate', 'type' => 'number', 'decimals' => 4, 'default' => 1, 'width' => 3,
            'required' => true, 'lock_when' => $usd, 'lock_value' => '1', 'hint' => 'Same way, at the date of the invoices.'], '');
        $calc = ['type' => 'formula', 'fmt' => 'rate', 'decimals' => 7, 'default' => null, 'required' => null, 'lock_when' => null, 'lock_value' => null];
        $p->set('advance_rate', $calc + ['label' => 'QuickBooks Advance Rate (1 = ? USD)', 'formula' => $toQb('adv_market')], '');
        $p->set('invoice_rate', $calc + ['label' => 'QuickBooks Invoices Rate (1 = ? USD)', 'formula' => $toQb('inv_market')], '');
        $p->insertAfter('invoice_rate', ['key' => 'jar_rate_check', 'label' => 'Check the rates', 'type' => 'formula', 'fmt' => 'text', 'width' => 12,
            'formula' => 'TEXTJOIN("   ·   ",TRUE,' . $say('adv_market') . ',' . $say('inv_market') . ')'], '');
        if ($p->changed) {
            \App\Models\FormVersion::create(['form_type_id' => $ft->id, 'schema' => $ft->schema, 'note' => 'Before system update v9']);
            $ft->forceFill(['schema' => $p->schema])->save();
        }
        // saved JARs keep their amounts: the typed rates are rebuilt from the stored QuickBooks rates
        $strong = \App\Models\Currency::where('in_usd', true)->pluck('id')->map(fn ($x) => (int) $x)->all();
        foreach (\App\Models\Document::where('form_code', 'jar')->get() as $d) {
            $x = $d->data ?? [];
            if (array_key_exists('inv_market', $x)) {
                continue;
            }
            $s = in_array((int) ($x['currency_id'] ?? 0), $strong, true);
            foreach (['adv_market' => 'advance_rate', 'inv_market' => 'invoice_rate'] as $m => $q) {
                $v = (float) ($x[$q] ?? 1) ?: 1;
                $x[$m] = $s ? $v : round(1 / $v, 10);
            }
            $d->forceFill(['data' => $x])->saveQuietly();
        }
    }
}
