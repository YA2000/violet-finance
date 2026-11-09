<?php

namespace App\Support;

use App\Models\FormType;
use App\Models\Setting;
use App\Models\TxnType;
use Illuminate\Support\Facades\Schema;

/**
 * Version 5: transaction numbers with rules per type (project, cost center, PR number); PTR without Recipient Type;
 * the Advance form is a plain form (no link to the advances module); Payroll & allocation and Advances leave the menu.
 * Called from Upgrade::run(), once per database.
 */
class V5
{
    public static function run(): void
    {
        if (Setting::get('upgrade_v5_done') || !Schema::hasColumn('txn_types', 'pr_field') || !FormType::exists()) {
            return;
        }
        static::typeRules();
        static::ptr();
        static::advance();
        Setting::put('module_advances', '0');
        Setting::put('module_payroll', '0');
        Setting::put('upgrade_v5_done', date('c'));
        FormType::flush();
    }

    /** Project required for PTR, JAR, Receive Payment and Invoice; PR number offered on the PTR. */
    protected static function typeRules(): void
    {
        foreach (TxnType::all() as $t) {
            $n = mb_strtolower($t->name);
            $ptr = $t->form_code === 'ptr' || str_contains($n, 'ptr') || str_contains($n, 'payment request');
            $jar = $t->form_code === 'jar' || str_contains($n, 'jar');
            $rp = str_contains($n, 'receive') || str_contains($n, 'received');
            $inv = str_contains($n, 'invoice');
            $t->forceFill(['need_project' => $ptr || $jar || $rp || $inv, 'pr_field' => $ptr ? 'optional' : 'off'])->save();
        }
    }

    /** No Recipient Type: amounts of the same recipient are always summed on the printout. */
    public static function ptr(): void
    {
        $ft = FormType::where('code', 'ptr')->first();
        if (!$ft || !$ft->schema) {
            return;
        }
        $s = $ft->schema;
        foreach ($s['blocks'] as &$b) {
            if ($b['type'] === 'section') {
                $b['fields'] = array_values(array_filter($b['fields'] ?? [], fn ($f) => ($f['key'] ?? '') !== 'recipient_type'));
            }
            if ($b['type'] === 'summary' && str_contains((string) ($b['group'] ?? ''), 'recipient_type')) {
                $b['group'] = 'IF(NOT(ISBLANK(recipient_id)),recipient_id,"l"&ROWNUM())';
            }
        }
        unset($b);
        $ft->forceFill(['schema' => $s])->save();
    }

    /** The Advance form only records the advance and prints it: no holder account, no advance opened anywhere. */
    public static function advance(): void
    {
        $ft = FormType::where('code', 'advance')->first();
        if (!$ft || !$ft->schema) {
            return;
        }
        $s = $ft->schema;
        unset($s['settings']['advance']);
        $s['blocks'] = array_values(array_filter($s['blocks'], fn ($b) => ($b['id'] ?? '') !== 'adv'));
        $s['settings']['rules'] = array_values(array_filter($s['settings']['rules'] ?? [],
            fn ($r) => !str_contains((string) ($r['formula'] ?? ''), 'holder_id')));
        $ft->forceFill(['schema' => $s])->save();
    }
}
