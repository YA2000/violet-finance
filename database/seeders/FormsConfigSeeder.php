<?php

namespace Database\Seeders;

use App\Models\FormNumber;
use App\Models\FormPrint;
use App\Models\FormRole;
use App\Models\FormSlot;
use App\Models\FormType;
use Illuminate\Database\Seeder;

/**
 * Form builder schemas, form numbers, printouts and signature chains of the workbook forms.
 * Safe to run again after an update: admin changes are kept, new items are added.
 */
class FormsConfigSeeder extends Seeder
{
    public function run(): void
    {
        $cfg = json_decode(file_get_contents(__DIR__ . '/data/forms_config.json'), true);
        \App\Support\Upgrade::installForms();
        $types = FormType::pluck('id', 'code')->all();
        $numbers = [];
        foreach ($cfg['numbers'] as $n) {
            $numbers[$n['key']] = FormNumber::firstOrCreate(['number' => $n['number'], 'form_name' => $n['form_name']],
                ['release_no' => $n['release_no'], 'release_label' => $n['release_label']])->id;
        }
        foreach ($cfg['slots'] as $s) {
            FormSlot::updateOrCreate(['code' => $s['code']], ['form_type_id' => $types[$s['form']], 'name' => $s['name'],
                'sequence' => $s['sequence']]);
        }
        $slots = FormSlot::pluck('id', 'code')->all();
        $haveRoles = FormRole::distinct()->pluck('form_type_id')->all();
        foreach ($cfg['roles'] as $r) {
            $tid = $types[$r['form']];
            if (in_array($tid, $haveRoles, true)) {
                continue;
            }
            FormRole::create(['form_type_id' => $tid, 'sequence' => $r['sequence'], 'name' => $r['name'],
                'slot_id' => $r['slot'] ? $slots[$r['slot']] : null, 'is_preparer' => $r['is_preparer'],
                'is_payer' => $r['is_payer'], 'has_date' => true, 'required' => $r['required']]);
            // (print_label is filled from the slot name by Upgrade::printLabels)
        }
        foreach ($cfg['prints'] as $p) {
            FormPrint::firstOrCreate(['sheet' => $p['sheet']], [
                'form_type_id' => $types[$p['form']], 'report' => $p['report'], 'sequence' => $p['sequence'],
                'name' => $p['name'], 'form_number_id' => $p['number'] ? ($numbers[$p['number']] ?? null) : null,
                'header_left' => $p['header_left'], 'header_center' => $p['header_center'],
                'footer_left' => $p['footer_left'], 'footer_center' => $p['footer_center'], 'footer_right' => $p['footer_right'],
            ]);
        }
        \App\Support\Upgrade::configurePrints();
    }
}
