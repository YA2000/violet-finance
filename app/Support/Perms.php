<?php

namespace App\Support;

use App\Models\FormType;

/** Catalogue of permissions shown on the Roles screen. Keys may be matched with wildcards (form.*.view). */
class Perms
{
    public const FORM_ACTIONS = [
        'create' => 'Prepare',
        'view' => 'See all',
        'edit' => 'Edit others\' drafts',
        'uploader' => 'Uploader file',
    ];

    public static function groups(): array
    {
        $master = [];
        foreach (config('master') as $k => $m) {
            $master["master.$k.manage"] = $m['title'];
        }
        return [
            'Transaction numbers' => [
                'numbers.reserve' => 'Reserve numbers',
                'numbers.manage' => 'Cancel or change anyone\'s numbers',
            ],
            'Documents' => [
                'documents.manage' => 'Reset to draft, cancel or delete any document, mark uploaded',
                'review.access' => 'Internal review page: see every document, comment and return for correction',
            ],
            'Advances' => [
                'advances.hold' => 'Advance holder: own advances, expenses, settlements, payment proofs',
                'advances.view' => 'See the advances tracker',
                'advances.manage' => 'Manage advances: holders, grant, settlements to JAR, linked payments',
            ],
            'Payments' => [
                'remittance.prepare' => 'Prepare Remittance Statements from the transactions you approved (internal viewer)',
                'remittance.all' => 'See every transaction ready to pay, not only the ones you approved',
                'payments.pay' => 'Pay Remittance Statements and record each payment (treasury)',
            ],
            'Received payments' => [
                'receipts.inform' => 'Inform a new received payment (treasury)',
                'receipts.assign' => 'Choose the project of a payment (reporting)',
                'receipts.record' => 'Record a payment in the system (AR)',
                'receipts.view' => 'See all received payments',
            ],
            'Payroll' => [
                'payroll.manage' => 'Contracts, LOE of every employee, monthly payroll runs and allocation (HR)',
                'payroll.approve' => 'Approve or reopen a payroll run (finance)',
                'payroll.view' => 'See payroll runs, allocations and payroll sheets',
            ],
            'Reports' => ['reports.view' => 'Reports'],
            'Master data' => $master,
            'Administration' => [
                'forms.design' => 'Form builder, signature chains, printouts, form numbers',
                'users.manage' => 'Users',
                'roles.manage' => 'Roles & permissions',
                'settings.manage' => 'Settings',
            ],
        ];
    }

    public static function formCodes(?string $group = null): array
    {
        return FormType::query()->when($group, fn ($q) => $q->where('group', $group))->orderBy('sequence')->pluck('code')->all();
    }

    public static function matches(array $granted, string $ability): bool
    {
        foreach ($granted as $g) {
            if ($g === $ability || $g === '*' || (str_contains($g, '*') && fnmatch($g, $ability))) {
                return true;
            }
        }
        return false;
    }

    /** Built-in roles (created once; the administrator can change them afterwards). */
    /** Codes of active forms open to every user. */
    public static function openForms(): array
    {
        static $codes;
        return $codes ??= \App\Models\FormType::where('active', true)->whereNotNull('schema')->get()
            ->filter(fn ($t) => !empty($t->schema['settings']['open_to_all']))->pluck('code')->all();
    }

    public static function defaultRoles(): array
    {
        $ap = array_map(fn ($c) => "form.$c.*", static::formCodes('AP'));
        $tr = array_map(fn ($c) => "form.$c.*", static::formCodes('Treasury'));
        return [
            'Finance Manager' => ['Full access to finance work, master data and reports.', ['*']],
            'AP Accountant' => ['Prepares PTR, Reference Cover, JAR, Fatura, Invoice, Reconciliation and Advance forms from the requests of Logistics, Programs and HR; turns JAR Clearances into JARs.',
                array_merge($ap, ['numbers.reserve', 'advances.manage', 'advances.view', 'reports.view'])],
            'AR Accountant' => ['Records received payments; expense reports of every project to match QuickBooks.', ['receipts.record', 'receipts.view', 'numbers.reserve', 'reports.view']],
            'Budgeting Officer' => ['Agreements (projects), partners, budget lines, accounts, accounting and cost centers, individuals and suppliers.',
                ['payroll.view', 'master.projects.manage', 'master.partners.manage', 'master.budget_lines.manage', 'master.accounts.manage', 'master.cost_centers.manage',
                 'master.accounting_centers.manage', 'master.individuals.manage', 'master.suppliers.manage', 'reports.view']],
            'Internal Viewer' => ['Audits every transaction, comments and returns them for correction; prepares Remittance Statements.',
                ['review.access', 'form.*.view', 'form.remittance.create', 'remittance.prepare', 'receipts.view', 'advances.view', 'reports.view']],
            'Treasury' => ['Bank Control, Journal, Refund, received payments and paying Remittance Statements.',
                array_merge(array_values(array_diff($tr, ['form.remittance.*'])), ['form.remittance.view', 'payments.pay', 'receipts.inform', 'receipts.view', 'advances.view', 'numbers.reserve'])],
            'Reporting Officer' => ['Chooses the project of received payments; sees every transaction and advance without editing; project expense reports.',
                ['form.*.view', 'receipts.assign', 'receipts.view', 'advances.view', 'reports.view']],
            'Logistics' => ['Advance holder: expenses and JAR Clearances; suppliers with the Budgeting Officer.', ['advances.hold', 'master.suppliers.manage']],
            'HR' => ['Human resources: individuals, contracts, LOE of every employee and the monthly payroll.', ['master.individuals.manage', 'payroll.manage', 'payroll.view']],
            'Programs' => ['Programs department (activity requests come later).', []],
            'Employee' => ['Fills and signs forms (the access of the first version).', ['form.*.create', 'numbers.reserve']],
        ];
    }
}
