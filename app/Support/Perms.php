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
            'Received payments' => [
                'receipts.inform' => 'Inform a new received payment (treasury)',
                'receipts.assign' => 'Choose the project of a payment (reporting)',
                'receipts.record' => 'Record a payment in the system (AR)',
                'receipts.view' => 'See all received payments',
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
    public static function defaultRoles(): array
    {
        $ap = array_map(fn ($c) => "form.$c.*", static::formCodes('AP'));
        $tr = array_map(fn ($c) => "form.$c.*", static::formCodes('Treasury'));
        return [
            'Finance Manager' => ['Full access to finance work, master data and reports.', ['*']],
            'AP Accountant' => ['Prepares PTR, Reference Cover, JAR, Fatura, Invoice, Reconciliation and Advance forms; works with logistics on advances.',
                array_merge($ap, ['numbers.reserve', 'advances.manage', 'advances.view', 'reports.view'])],
            'AR Accountant' => ['Records received payments in the system.', ['receipts.record', 'receipts.view', 'numbers.reserve', 'reports.view']],
            'Budgeting' => ['Projects, budget lines, chart of accounts and cost centers.',
                ['master.projects.manage', 'master.budget_lines.manage', 'master.accounts.manage', 'master.cost_centers.manage', 'master.accounting_centers.manage', 'reports.view']],
            'Internal Reviewer' => ['Sees every transaction, comments and returns documents for correction.',
                ['review.access', 'form.*.view', 'receipts.view', 'advances.view', 'reports.view']],
            'Treasury' => ['Bank Control, Journal, Refund, Remittance, received payments and advance payments.',
                array_merge($tr, ['receipts.inform', 'receipts.view', 'advances.view', 'numbers.reserve'])],
            'Reporting Officer' => ['Sees every transaction and its status; chooses the project of received payments.',
                ['form.*.view', 'receipts.assign', 'receipts.view', 'advances.view', 'reports.view']],
            'Logistics' => ['Advance holder: records expenses and clears operating advances.', ['advances.hold']],
            'Employee' => ['Fills and signs forms (the access of the first version).', ['form.*.create', 'numbers.reserve']],
        ];
    }
}
