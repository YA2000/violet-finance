<?php

namespace App\Support;

use App\Models\Document;
use App\Models\FormPrint;
use App\Models\FormType;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * Version 4: forms are filled and saved like the Excel files. No signing or payment in the system; Fatura, English Invoice and the
 * Remittance Statement are switched off (their data stays); PTR prints in English and Arabic, nothing in Turkish.
 * Called from Upgrade::run(), once per database.
 */
class V4
{
    public static function run(): void
    {
        if (Setting::get('upgrade_v4_done') || !Schema::hasColumn('form_prints', 'rtl') || !FormPrint::exists()) {
            return;
        }
        FormType::whereIn('code', ['fatura', 'english_invoice', 'remittance'])->update(['active' => false]);
        Setting::put('pay_through_remittance', '0');
        FormPrint::whereIn('sheet', ['Turkish PTR', 'JAR-Turkish'])->update(['active' => false]);
        static::arabicPtr();
        foreach (Document::whereIn('state', ['signing', 'to_pay'])->get() as $d) {
            $d->update(['state' => 'done', 'current_signer_id' => null, 'done_at' => now()]);
            \App\Services\Workflow::log($d, 'Final: signing and payment left the system.', 'done');
            \App\Services\Advances::onDocumentDone($d->refresh());
        }
        $r = Role::where('name', 'Budgeting Officer')->first();
        if ($r && !in_array('reports.view', $r->permissions ?? [], true)) {
            $r->permissions = array_values(array_merge($r->permissions ?? [], ['reports.view']));
            $r->save();
        }
        Setting::put('upgrade_v4_done', date('c'));
        FormType::flush();
    }

    /** Same layout as the English PTR printout, every printed text in Arabic. */
    protected static function arabicPtr(): void
    {
        $en = FormPrint::where('sheet', 'PTR')->first();
        if (!$en || FormPrint::where('sheet', 'Arabic PTR')->exists()) {
            return;
        }
        $ar = $en->replicate();
        $ar->fill(['sheet' => 'Arabic PTR', 'name' => 'PTR عربي', 'title' => 'طلب تحويل دفعة (PTR)', 'sequence' => (int) $en->sequence + 1, 'rtl' => true,
            'labels' => static::ARABIC, 'active' => true,
            'header_left' => "رقم النموذج: {form_no}\nرقم الإصدار: {release_no} تاريخ الإصدار: {release_date}",
            'header_center' => "{org}\nالقسم المالي",
            'footer_left' => '[page]   من   [pages]', 'footer_center' => 'عدد صفحات الوثيقة  [pages]']);
        $ar->save();
    }

    public const ARABIC = [
        '_number' => 'الرقم المرجعي للمعاملة', '_date' => 'التاريخ', '_sn' => 'م', '_approvals' => 'الموافقات', '_step' => 'المرحلة',
        '_name_title' => 'الاسم والمسمى الوظيفي', '_signature' => 'التوقيع',
        'block:s1' => 'طلب تحويل دفعة', 'block:s2' => 'سعر الصرف', 'block:s3' => 'الوصف', 'block:t1' => 'البنود', 'block:totals' => 'المجاميع',
        'block:receipts' => 'معلومات مستلمي الدفعة', 'block:approval_levels' => 'مستويات الموافقة', 'block:sig' => 'الموافقات',
        'date' => 'تاريخ الطلب', 'accounting_center_id' => 'مركز التكلفة', 'invoice_currency_id' => 'عملة الفاتورة', 'payment_currency_id' => 'عملة الدفع',
        'cost_center_name' => 'اسم مركز التكلفة', 'recipient_type' => 'نوع المستلم',
        'home_currency' => 'العملة الأساسية', 'home_rate' => 'سعر عملة الفاتورة (1 = ? دولار)', 'home_ex_source_id' => 'مصدر السعر الأساسي',
        'pay_rate' => 'سعر عملة الدفع (1 = ? دولار)', 'ex_source_id' => 'مصدر سعر الصرف', 'exchange_rate' => 'سعر الصرف للدفع (محسوب)',
        'description' => 'وصف الطلب',
        'lines.invoice_amount' => 'مبلغ الفاتورة', 'lines.allocation' => 'نسبة التوزيع %', 'lines.expected_amount' => 'الدفعة المتوقعة', 'lines.budget_line_id' => 'بند الموازنة',
        'lines.project_name' => 'المشروع', 'lines.home_amount' => 'المبلغ بالعملة الأساسية', 'lines.special_note' => 'ملاحظة خاصة', 'lines.recipient_id' => 'المستلم',
        'lines.invoice_no' => 'الفاتورة', 'lines.po_number' => 'رقم أمر الشراء',
        'total_invoice' => 'إجمالي الفاتورة', 'total_expected' => 'الإجمالي بعملة الدفع', 'total_home' => 'الإجمالي بالعملة الأساسية', 'home_rate_print' => 'السعر الأساسي',
        'receipts.0' => 'المبلغ', 'receipts.1' => 'العملة', 'receipts.2' => 'اسم المستلم', 'receipts.3' => 'رقم الحساب / IBAN', 'receipts.4' => 'البنك', 'receipts.5' => 'رقم الفاتورة',
        'text:approval_levels' => "أعدّه مسؤول محاسبة المشروع.\nيعتمده آمر الصرف للتكاليف المباشرة (1، 2، 3، 4، 6). وفي التكاليف المشتركة: يعتمد المدير التنفيذي (4) جميع مصاريف التكاليف المشتركة في تركيا، ويعتمد المدير الإداري (5) جميع التكاليف المشتركة في الميدان.\n1) من 1 إلى 5.000 دولار أمريكي أو ما يعادلها\n2) من 5.001 إلى 20.000 دولار أمريكي أو ما يعادلها\n3) من 20.001 إلى 100.000 دولار أمريكي أو ما يعادلها\n4) من 100.001 إلى 1.000.000 دولار أمريكي أو ما يعادلها؛ والتكاليف المشتركة من 1 إلى 1.000.000 دولار أمريكي\n6) أكثر من 1.000.000 دولار أمريكي أو ما يعادلها\nيراجعه مسؤول تقارير المشاريع، ويوافق عليه المراجع الداخلي، ويدفعه قسم الخزينة.",
        'role:Preparer' => 'المُعِد', 'rolelabel:Preparer' => 'أعدّه مسؤول محاسبة المشروع',
        'role:Internal Audit' => 'التدقيق الداخلي', 'rolelabel:Internal Audit' => 'وافق عليه المراجع الداخلي',
        'role:Project Manager' => 'مدير المشروع', 'rolelabel:Project Manager' => 'آمر الصرف 1) مدير المشروع (1 - 5.000 دولار)',
        'role:Budget Holder (Authorizer)' => 'آمر الصرف (المعتمِد)', 'rolelabel:Budget Holder (Authorizer)' => 'آمر الصرف 4) المدير التنفيذي / 5) المدير الإداري (100.001 - 1.000.000 دولار، تكاليف مشتركة)',
        'role:Payment (Treasury)' => 'الدفع (الخزينة)', 'rolelabel:Payment (Treasury)' => 'دفعه قسم الخزينة',
    ];
}
