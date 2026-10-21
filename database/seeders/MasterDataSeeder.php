<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Master data of the workbook (Data / Master / Individuals / Suppliers sheets). Runs only on an empty database. */
class MasterDataSeeder extends Seeder
{
    protected function load(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . '/data/' . $name . '.json'), true);
    }

    protected function insert(string $table, array $rows): void
    {
        $now = now();
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table($table)->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    public function run(): void
    {
        if (DB::table('currencies')->exists()) {
            $this->command?->info('Master data already loaded - skipped.');
            return;
        }
        DB::transaction(function () {
            $this->insert('currencies', array_map(fn ($r) => ['code' => $r['name'], 'name' => $r['full_name'],
                'sequence' => $r['sequence'] ?? 10, 'active' => $r['active']], $this->load('currencies')));
            $cur = DB::table('currencies')->pluck('id', 'code')->all();
            $this->insert('list_items', array_map(fn ($r) => ['category' => $r['category'], 'name' => $r['name'],
                'sequence' => $r['sequence'] ?? 10, 'active' => $r['active']], $this->load('list_items')));
            $this->insert('partners', array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name']], $this->load('partners')));
            $partners = DB::table('partners')->pluck('id', 'name')->all();
            $partnerIds = DB::table('partners')->pluck('id', 'code')->all();
            $this->insert('projects', array_map(fn ($r) => ['code' => (int) $r['code'], 'name' => $r['name'],
                'qb_name' => $r['qb_name'], 'currency_id' => $cur[$r['currency_id']] ?? null, 'end_date' => $r['end_date'],
                'partner_id' => $partnerIds[$r['partner_id']] ?? ($partners[$r['partner_id']] ?? null),
                'active' => $r['active']], $this->load('projects')));
            $this->insert('accounts', array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'],
                'category' => $r['category'], 'sub_category' => $r['sub_category'],
                'english_description' => $r['english_description'], 'currency_id' => $cur[$r['currency_id']] ?? null,
                'trac' => $r['trac'], 'tr_account_name' => $r['tr_account_name'], 'active' => $r['active']], $this->load('accounts')));
            $this->insert('accounting_centers', $this->load('accounting_centers'));
            $centers = DB::table('accounting_centers')->get()->keyBy('code');
            $this->insert('cost_centers', array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'],
                'accounting_center_id' => $centers[$r['accounting_center_id']]->id ?? null,
                'currency_id' => $cur[$r['currency_id']] ?? null, 'location' => $r['location']], $this->load('cost_centers')));
            // budget lines + the derived columns of the Master sheet
            $projects = DB::table('projects')->get()->keyBy('code');
            $accounts = DB::table('accounts')->get()->keyBy('code');
            $partnerName = DB::table('partners')->pluck('name', 'id')->all();
            $rows = [];
            foreach ($this->load('budget_lines') as $r) {
                $p = $projects[(int) $r['project_code']] ?? null;
                $c = $centers[$r['accounting_center_code']] ?? null;
                $exp = $accounts[$r['expense_account_id']] ?? null;
                $parent = $r['sub_line'] ? $r['project_code'] . '-' . $r['budget_line'] : null;
                $rows[] = [
                    'code' => $r['code'], 'project_code' => $r['project_code'],
                    'accounting_center_code' => $r['accounting_center_code'], 'budget_line' => $r['budget_line'],
                    'sub_line' => $r['sub_line'], 'project_id' => $p?->id, 'accounting_center_id' => $c?->id,
                    'expense_account_id' => $exp?->id, 'income_account_id' => ($accounts[$r['income_account_id']] ?? null)?->id,
                    'description' => $r['description'], 'total_amount' => $r['total_amount'] ?: null,
                    'budget_category' => $r['budget_category'], 'remaining' => $r['remaining'], 'active' => $r['active'],
                    'project_name' => $p?->qb_name ?: '-', 'location' => $c?->location ?: 'Donation',
                    'accounting_center_name' => $c?->master_name ?: 'No Accounting Center',
                    'partner_name' => $p && $p->partner_id ? ($partnerName[$p->partner_id] ?? '') : '',
                    'turkish_account' => trim(implode(' ', array_filter([$exp?->trac, $exp?->tr_account_name]))),
                    'parent_bl' => $parent, 'qb_name' => $parent ? $parent . ':' . $r['code'] : $r['code'],
                ];
            }
            $this->insert('budget_lines', $rows);
            $this->insert('individuals', $this->load('individuals'));
            $this->insert('suppliers', $this->load('suppliers'));
            $this->insert('form_numbers', array_map(fn ($r) => ['number' => $r['name'], 'form_name' => $r['form_name'],
                'release_no' => $r['release_no'], 'release_date' => $r['release_date'], 'release_label' => $r['release_label']],
                $this->load('form_numbers')));
            $codes = ['vf.ptr' => 'ptr', 'vf.reference.cover' => 'refcover', 'vf.advance' => 'advance', 'vf.journal' => 'journal',
                'vf.jar' => 'jar', 'vf.remittance' => 'remittance', 'vf.fatura' => 'fatura', 'vf.english.invoice' => 'english_invoice',
                'vf.reconciliation' => 'recon', 'vf.bank.control' => 'bank', 'vf.refund' => 'refund'];
            $this->insert('txn_types', array_map(fn ($r) => ['name' => $r['name'], 'sequence' => $r['sequence'],
                'form_code' => $codes[$r['form_model']] ?? null, 'active' => $r['active']], $this->load('txn_types')));
        });
    }
}
