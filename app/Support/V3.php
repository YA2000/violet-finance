<?php

namespace App\Support;

use App\Models\Document;
use App\Models\FormType;
use App\Models\FormVersion;
use App\Models\Role;

/**
 * Version 3 changes to the stored forms and documents. Called from Upgrade::run(), safe to run many times.
 */
class V3
{
    /** "1 unit of this currency = ? USD" (QuickBooks), 7 decimals, always 1 for US dollars. */
    public const USD_HINT = 'QuickBooks rate: US dollars for 1 unit. Always 1 for USD.';

    /** JAR Clearance declaration of the advance holder (workbook: JAR Clearance A110:F120). "English || Arabic" per line. */
    public const DECLARATION = "By signing this document, I clarify and confirm the following: || بتوقيعي على الوثيقة أدناه، فإني أوضح وأقر بما يلي:
I took budget holder approval for these expenses. || لقد حصلت على موافقة آمر الصرف قبل تقديم هذه المصاريف.
The budget line code is correct and reviewed. || لقد أسندت كل فاتورة إلى كود الموازنة الصحيح.
I have submitted all original documents to the responsible accounting officer. || قمت بتسليم كافة المستندات الأصلية للمحاسب المسؤول عن المشروع.
All available GRNs are attached to the original invoices. || كل مستندات تسليم البضائع المتوفرة قد تم إرفاقها مع فواتيرها الأصلية.
All invoice dates are related to the current accounting period. || كل الفواتير المسلّمة تعود تواريخها إلى نفس الفترة المحاسبية الحالية.
I have submitted this document with a reviewed table that summarizes the submitted invoices. || لقد قمت بتلخيص الفواتير المسلّمة على ملف إكسل وسلّمته إلكترونياً للمحاسب.
I reported the exchange rate correctly. || لقد قمت بوضع سعر الصرف بشكل صحيح.
I referred this clearance to the correct original advance reference number. || لقد قمت بكتابة مرجع السلفة التي أنفقت منها بشكل صحيح.
The invoice numbers and dates are accurately added to the table. || كافة أرقام الفواتير وتواريخها صحيحة.
I do not have any more invoices dated before the submission date. || ليس لدي أي فاتورة غير مسلّمة قبل تاريخ التسليم هذا.";

    /** Confirmation of the accounting officer who receives the originals (workbook: JAR Clearance A123:F124). */
    public const RECEIVED = "By signing this document, the accounting officer clarifies and confirms the following: || بتوقيع المحاسب/ة المسؤول/ة عن المشروع، فإنه يقر بما يلي:
I received all original invoices and GRNs according to the table above. || لقد قمت باستلام كافة الوثائق الأصلية (فواتير - محاضر تسليم بضائع) وفق الجدول الموضح أعلاه.";

    /** PTR approval levels (workbook: PTR A2020:A2031). */
    public const PTR_LEVELS = "Prepared by the project's accounting responsible.
Authorized by the budget holder for direct cost (1, 2, 3, 4, 6). For shared cost: in Turkey the CEO (4) authorises all shared cost expenses; in the field the Administration Manager (5) authorises all shared cost in the field.
1) From 1 to 5.000 USD or equivalent
2) From 5.001 to 20.000 USD or equivalent
3) From 20.001 to 100.000 USD or equivalent
4) From 100.001 to 1.000.000 USD or equivalent; shared cost from 1 to 1.000.000 USD
6) Above 1.000.000 USD or equivalent
Reviewed by the projects reporting responsible, approved by the internal reviewer, paid by the treasury department.";

    public static function usd(string $field): string
    {
        return 'OR(ISBLANK(' . $field . '),LOOKUP(' . $field . ',"code")="USD")';
    }

    public static function run(): void
    {
        // once per database: what an administrator changes afterwards in the builder is never touched again
        // printouts: on a fresh install they are created later (seeder), so this step waits for them
        if (!\App\Models\Setting::get('upgrade_v3_prints') && \App\Models\FormPrint::exists()) {
            static::prints();
            \App\Models\Setting::put('upgrade_v3_prints', date('c'));
        }
        if (\App\Models\Setting::get('upgrade_v3_done') || !FormType::whereNotNull('schema')->exists()) {
            return;
        }
        foreach (FormType::whereNotNull('schema')->get() as $ft) {
            $p = new SchemaPatch($ft->schema);
            $method = 'form_' . $ft->code;
            if (method_exists(static::class, $method)) {
                static::$method($p);
            }
            if ($p->changed) {
                FormVersion::create(['form_type_id' => $ft->id, 'schema' => $ft->schema, 'note' => 'Before system update v3']);
                $ft->schema = $p->schema;
                $ft->save();
            }
        }
        static::convertDocuments();
        static::roles();
        \App\Models\Setting::put('upgrade_v3_done', date('c'));
        FormType::flush();
    }

    /** Roles as described for version 3: rename, add the new permissions, add the department roles. Never removes what an admin added. */
    protected static function roles(): void
    {
        $defaults = Perms::defaultRoles();
        foreach (['Internal Reviewer' => 'Internal Viewer', 'Budgeting' => 'Budgeting Officer'] as $old => $new) {
            if (!Role::where('name', $new)->exists()) {
                Role::where('name', $old)->update(['name' => $new]);
            }
        }
        foreach ($defaults as $name => [$desc, $perms]) {
            $role = Role::where('name', $name)->first();
            if (!$role) {
                Role::create(['name' => $name, 'description' => $desc, 'permissions' => $perms]);
                continue;
            }
            $have = $role->permissions ?? [];
            if ($name === 'Treasury') {          // the remittance statement is prepared by the internal viewer now
                $have = array_values(array_diff($have, ['form.remittance.*']));
            }
            $role->permissions = array_values(array_unique(array_merge($have, $perms)));
            $role->description = $desc;
            $role->save();
        }
    }

    /** JAR declaration blocks belong to the JAR Clearance printout only; PTR Turkish print hides the new USD rate. */
    protected static function prints(): void
    {
        foreach (\App\Models\FormPrint::with('formType')->get() as $pr) {
            $code = $pr->formType?->code;
            $hidden = $pr->hidden_keys ?? [];
            $add = [];
            if ($code === 'jar' && stripos((string) $pr->title, 'clearance') === false) {
                $add = ['text:declaration', 'text:received'];
            }
            if ($code === 'ptr' && in_array('home_rate', $hidden, true)) {
                $add = ['pay_rate', 'text:approval_levels'];
            }
            $new = array_values(array_unique(array_merge($hidden, $add)));
            if ($new !== $hidden) {
                $pr->hidden_keys = $new;
                $pr->save();
            }
        }
    }

    /* ----------------------------------------------------------------- forms */

    protected static function form_ptr(SchemaPatch $p): void
    {
        if (!$p->has('pay_rate', '')) {
            $p->insertAfter('home_rate', ['key' => 'pay_rate', 'label' => 'Payment Currency Rate (1 = ? USD)', 'type' => 'number',
                'decimals' => 7, 'default' => 1, 'width' => 4], '');
        }
        $p->set('home_currency', ['lock_when' => '1=1', 'lock_value' => '"USD"'], '');
        $p->set('home_rate', ['decimals' => 7, 'lock_when' => static::usd('invoice_currency_id'), 'lock_value' => '1', 'hint' => static::USD_HINT], '');
        if (($p->get('home_rate', '')['label'] ?? '') === 'Home Currency Exchange Rate') {
            $p->set('home_rate', ['label' => 'Invoice Currency Rate (1 = ? USD)'], '');
        }
        $p->set('pay_rate', ['decimals' => 7, 'hint' => 'Same rule. Equal to the invoice rate when the currencies match.',
            'lock_when' => 'OR(' . static::usd('payment_currency_id') . ',payment_currency_id=invoice_currency_id)',
            'lock_value' => 'IF(AND(NOT(ISBLANK(payment_currency_id)),payment_currency_id=invoice_currency_id),home_rate,1)'], '');
        $p->set('exchange_rate', ['type' => 'formula', 'fmt' => 'rate', 'decimals' => 7, 'default' => null,
            'formula' => 'IF(pay_rate=0,0,ROUND(home_rate/pay_rate,7))',
            'hint' => 'Calculated: payment currency for 1 unit of the invoice currency.'], '');
        if (($p->get('exchange_rate', '')['label'] ?? '') === 'Exchange Rate to pay') {
            $p->set('exchange_rate', ['label' => 'Exchange Rate to pay (calculated)'], '');
        }
        $p->remove('factor', '')->remove('home_factor', '');
        $p->order(['home_currency', 'home_rate', 'home_ex_source_id', 'pay_rate', 'ex_source_id', 'exchange_rate'], '');
        foreach (['home_currency' => 3, 'home_rate' => 4, 'home_ex_source_id' => 5, 'pay_rate' => 4, 'ex_source_id' => 4, 'exchange_rate' => 4] as $k => $w) {
            $p->set($k, ['width' => $w], '');
        }
        $p->set('expected_amount', ['formula' => 'IFERROR(invoice_amount*IF(ISBLANK(allocation),1,allocation)*exchange_rate,0)'], 'lines');
        $p->set('home_amount', ['formula' => 'IFERROR(invoice_amount*IF(ISBLANK(allocation),1,allocation)*home_rate,0)'], 'lines');
        $p->set('home_rate_print', ['formula' => 'pay_rate', 'fmt' => 'rate', 'decimals' => 7], '');
        $p->replaceInSettings('IF(LOOKUP(payment_currency_id,"code")="USD",1,IF(invoice_currency_id=payment_currency_id,IF(home_rate=0,0,1/home_rate),IF(exchange_rate=0,0,1/exchange_rate)))',
            'IF(LOOKUP(payment_currency_id,"code")="USD",1,pay_rate)');
        $p->addBlockBefore('signatures', ['type' => 'text', 'id' => 'approval_levels', 'title' => 'Approval levels', 'style' => 'note',
            'entry' => true, 'text' => static::PTR_LEVELS]);
    }

    protected static function form_jar(SchemaPatch $p): void
    {
        $p->set('advance_rate', ['decimals' => 7, 'lock_when' => static::usd('currency_id'), 'lock_value' => '1', 'hint' => static::USD_HINT], '');
        $p->set('invoice_rate', ['decimals' => 7, 'lock_when' => static::usd('currency_id'), 'lock_value' => '1', 'hint' => static::USD_HINT], '');
        foreach (['advance_rate' => ['Original Advance Ex.Rate', 'Original Advance Rate (1 = ? USD)'], 'invoice_rate' => ['Invoices Exchange Rate', 'Invoices Rate (1 = ? USD)']] as $k => [$old, $new]) {
            if (($p->get($k, '')['label'] ?? '') === $old) {
                $p->set($k, ['label' => $new], '');
            }
        }
        $p->remove('factor', '');
        $p->set('home_amount', ['formula' => 'IFERROR(paid_amount*invoice_rate,0)'], 'lines');
        $p->replaceInSettings('IF(advance_rate=0,0,1/advance_rate)', 'advance_rate');
        // the holder's declaration and the accountant's confirmation: printed on the JAR Clearance only
        $p->addBlockBefore('signatures', ['type' => 'text', 'id' => 'declaration', 'title' => 'Declaration of the advance holder',
            'style' => 'bilingual', 'entry' => false, 'text' => static::DECLARATION]);
        $p->addBlockBefore('signatures', ['type' => 'text', 'id' => 'received', 'title' => 'Received by the accounting officer',
            'style' => 'bilingual', 'entry' => false, 'text' => static::RECEIVED]);
    }

    protected static function form_advance(SchemaPatch $p): void
    {
        // whoever needs an advance applies for it themselves
        if (empty($p->schema['settings']['open_to_all'])) {
            $p->schema['settings']['open_to_all'] = true;
            $p->schema['settings']['number_type'] ??= 'Payment in Advance (AA)';
            $p->changed = true;
        }
        $p->set('exchange_rate', ['decimals' => 7, 'lock_when' => static::usd('currency_id'), 'lock_value' => '1', 'hint' => static::USD_HINT], '');
        if (($p->get('exchange_rate', '')['label'] ?? '') === 'Exchange Rate') {
            $p->set('exchange_rate', ['label' => 'Exchange Rate (1 = ? USD)'], '');
        }
    }

    protected static function form_remittance(SchemaPatch $p): void
    {
        if (empty($p->schema['settings']['number_type'])) {
            $p->schema['settings']['number_type'] = 'Remittance';
            $p->changed = true;
        }
    }

    protected static function form_recon(SchemaPatch $p): void
    {
        $p->set('exchange_rate', ['decimals' => 7, 'lock_when' => static::usd('currency_id'), 'lock_value' => '1', 'hint' => static::USD_HINT], '');
    }

    protected static function form_journal(SchemaPatch $p): void
    {
        $p->set('exchange_rate', ['decimals' => 7], '');
    }

    /* ------------------------------------------------------------- documents */

    protected static function r7($v): float
    {
        return round((float) $v, 7);
    }

    /** Old documents stored "units per USD" plus a multiply/divide factor: turn them into "1 unit = ? USD". */
    protected static function convertDocuments(): void
    {
        foreach (Document::whereIn('form_code', ['ptr', 'jar'])->get() as $doc) {
            $d = $doc->data ?? [];
            if (!array_key_exists('factor', $d) && !array_key_exists('home_factor', $d)) {
                continue;
            }
            if ($doc->form_code === 'ptr') {
                $home = (float) ($d['home_rate'] ?? 1) ?: 1;
                if (($d['home_factor'] ?? 'div') === 'div') {
                    $home = 1 / $home;
                }
                $x = (float) ($d['exchange_rate'] ?? 1) ?: 1;       // payment units for 1 invoice unit
                if (($d['factor'] ?? 'mul') === 'div') {
                    $x = 1 / $x;
                }
                $d['home_rate'] = static::r7($home);
                $d['pay_rate'] = static::r7($x ? $home / $x : $home);
                $d['exchange_rate'] = static::r7($x);
            } else {
                $adv = (float) ($d['advance_rate'] ?? 1) ?: 1;
                $inv = (float) ($d['invoice_rate'] ?? 1) ?: 1;
                $d['advance_rate'] = static::r7(1 / $adv);
                $d['invoice_rate'] = ($d['factor'] ?? 'mul') === 'div' ? static::r7(1 / $inv) : static::r7($inv);
            }
            unset($d['factor'], $d['home_factor']);
            $doc->forceFill(['data' => $d])->saveQuietly();
        }
    }
}
