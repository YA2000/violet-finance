<?php

use App\Models;

/*
 | Master data screens (Admin → Master data). One entry per table.
 | list: columns shown in the table (key => label; "rel.attr" allowed)
 | fields: edit form  [key, label, type, extra...]
 |   types: text, textarea, int, number, date, switch, select (options), lookup (source, rel)
 */
return [
    'projects' => [
        'model' => Models\Project::class, 'title' => 'Projects', 'one' => 'project', 'icon' => 'fa-diagram-project',
        'group' => 'Budget', 'search' => ['code', 'name', 'qb_name'], 'order' => ['code', 'desc'],
        'list' => ['code' => 'Code', 'name' => 'Project name', 'qb_name' => 'QuickBooks name', 'currency.code' => 'Currency', 'partner.name' => 'Partner', 'end_date' => 'End date', 'active' => 'Active'],
        'fields' => [
            ['code', 'Project code', 'int', 'required' => true, 'unique' => true, 'col' => 3, 'help' => 'Suggested: the next free number. A code that already exists is refused.'],
            ['name', 'Project name', 'text', 'required' => true, 'col' => 9],
            ['qb_name', 'QuickBooks name', 'text', 'col' => 6],
            ['currency_id', 'Currency', 'lookup', 'source' => 'currencies', 'rel' => 'currency', 'col' => 3],
            ['end_date', 'End date', 'date', 'col' => 3],
            ['partner_id', 'Partner', 'select', 'options' => 'partners', 'col' => 6],
            ['active', 'Active', 'switch', 'col' => 6],
        ],
    ],
    'budget_lines' => [
        'model' => Models\BudgetLine::class, 'title' => 'Budget lines', 'one' => 'budget line', 'icon' => 'fa-layer-group',
        'group' => 'Budget', 'search' => ['code', 'description', 'project_name', 'project_code'], 'order' => ['id', 'desc'],
        'list' => ['code' => 'Code', 'description' => 'Description', 'project_name' => 'Project', 'accounting_center_name' => 'Accounting center', 'expenseAccount.code' => 'Expense account', 'total_amount' => 'Total', 'active' => 'Active'],
        'fields' => [
            ['project_code', 'Project code', 'text', 'required' => true, 'col' => 3],
            ['accounting_center_code', 'Accounting center code', 'text', 'required' => true, 'col' => 3],
            ['budget_line', 'Budget line', 'text', 'required' => true, 'col' => 3],
            ['sub_line', 'Sub line', 'text', 'col' => 3],
            ['code', 'Code', 'text', 'unique' => true, 'col' => 6, 'help' => 'Leave empty to build it from project-center-line(-(sub)).'],
            ['description', 'Description', 'text', 'long' => true, 'col' => 6],
            ['expense_account_id', 'Expense account', 'lookup', 'source' => 'accounts', 'rel' => 'expenseAccount', 'col' => 6],
            ['income_account_id', 'Income account', 'lookup', 'source' => 'accounts', 'rel' => 'incomeAccount', 'col' => 6],
            ['total_amount', 'Total amount', 'number', 'col' => 4],
            ['spent_before', 'Spent before the system (USD)', 'number', 'col' => 4, 'help' => 'From QuickBooks (budget vs actual) up to the date beside it. Used for the remaining capacity of the line.'],
            ['spent_before_date', 'Spent up to', 'date', 'col' => 4],
            ['budget_category', 'Budget category', 'text', 'long' => true, 'col' => 4],
            ['remaining', 'Remaining', 'text', 'col' => 4],
            ['active', 'Active', 'switch', 'col' => 12],
        ],
        'readonly' => ['project_name' => 'Project name', 'location' => 'Location', 'accounting_center_name' => 'Accounting center', 'partner_name' => 'Partner', 'turkish_account' => 'Turkish account', 'parent_bl' => 'Parent BL', 'qb_name' => 'QuickBooks name'],
    ],
    'accounts' => [
        'model' => Models\Account::class, 'title' => 'Accounts', 'one' => 'account', 'icon' => 'fa-book',
        'group' => 'Budget', 'search' => ['code', 'name', 'english_description', 'tr_account_name'], 'order' => ['code', 'asc'],
        'list' => ['code' => 'Code', 'name' => 'Account name', 'category' => 'Category', 'sub_category' => 'Sub category', 'currency.code' => 'Currency', 'trac' => 'TR code', 'active' => 'Active'],
        'fields' => [
            ['code', 'Account code', 'text', 'required' => true, 'unique' => true, 'col' => 3],
            ['name', 'Account name', 'text', 'required' => true, 'col' => 9],
            ['category', 'Category', 'select', 'options' => 'list:account_category', 'col' => 4],
            ['sub_category', 'Sub category', 'select', 'options' => 'list:account_subcategory', 'col' => 4],
            ['currency_id', 'Currency', 'lookup', 'source' => 'currencies', 'rel' => 'currency', 'col' => 4],
            ['english_description', 'English description', 'text', 'long' => true, 'col' => 12],
            ['trac', 'Turkish account code', 'text', 'col' => 4],
            ['tr_account_name', 'Turkish account name', 'text', 'col' => 8],
            ['active', 'Active', 'switch', 'col' => 12],
        ],
    ],
    'accounting_centers' => [
        'model' => Models\AccountingCenter::class, 'title' => 'Accounting centers', 'one' => 'accounting center', 'icon' => 'fa-building-columns',
        'group' => 'Budget', 'search' => ['code', 'name', 'master_name'], 'order' => ['code', 'asc'],
        'list' => ['code' => 'Code', 'name' => 'Name', 'master_name' => 'Master name', 'related_project' => 'Related project', 'location' => 'Location'],
        'fields' => [
            ['code', 'Code', 'text', 'required' => true, 'unique' => true, 'col' => 3],
            ['name', 'Name', 'text', 'required' => true, 'col' => 9],
            ['master_name', 'Master name', 'text', 'col' => 6],
            ['location', 'Location', 'select', 'options' => 'list:location', 'col' => 6],
            ['related_project', 'Related project code', 'text', 'col' => 4],
            ['project_name', 'Project name', 'text', 'col' => 8],
        ],
    ],
    'cost_centers' => [
        'model' => Models\CostCenter::class, 'title' => 'Cost centers', 'one' => 'cost center', 'icon' => 'fa-coins',
        'group' => 'Budget', 'search' => ['code', 'name', 'location'], 'order' => ['code', 'asc'],
        'list' => ['code' => 'Code', 'name' => 'Name', 'accountingCenter.code' => 'Accounting center', 'currency.code' => 'Currency', 'location' => 'Location'],
        'fields' => [
            ['code', 'Code', 'text', 'required' => true, 'unique' => true, 'col' => 4, 'help' => 'Format: <center>-<currency>, e.g. TR-USD'],
            ['name', 'Name', 'text', 'required' => true, 'col' => 8],
            ['accounting_center_id', 'Accounting center', 'lookup', 'source' => 'accounting_centers', 'rel' => 'accountingCenter', 'col' => 4],
            ['currency_id', 'Currency', 'lookup', 'source' => 'currencies', 'rel' => 'currency', 'col' => 4],
            ['location', 'Location', 'select', 'options' => 'list:location', 'col' => 4],
        ],
    ],
    'individuals' => [
        'model' => Models\Individual::class, 'title' => 'Individuals', 'one' => 'individual', 'icon' => 'fa-id-badge',
        'group' => 'People', 'search' => ['code', 'name', 'name_ar', 'title', 'turkish_name'], 'order' => ['id', 'desc'],
        'list' => ['code' => 'Code', 'name' => 'Name', 'name_ar' => 'Arabic name', 'title' => 'Title', 'location' => 'Location', 'user.email' => 'Login', 'active' => 'Active'],
        'fields' => [
            ['code', 'Code', 'text', 'required' => true, 'unique' => true, 'col' => 3],
            ['name', 'Name', 'text', 'required' => true, 'col' => 5],
            ['name_ar', 'Arabic name', 'text', 'col' => 4, 'dir' => 'rtl'],
            ['title', 'Job title', 'text', 'col' => 6],
            ['turkish_name', 'Turkish name', 'text', 'col' => 6],
            ['bank_name', 'Bank', 'text', 'col' => 4],
            ['iban', 'IBAN', 'text', 'col' => 8],
            ['contract_number', 'Contract number', 'text', 'col' => 4],
            ['mustari_numrasi', 'Müşteri numarası', 'text', 'col' => 4],
            ['location', 'Location', 'select', 'options' => 'list:location', 'col' => 4],
            ['cc_code', 'Cost center code', 'text', 'col' => 4],
            ['cc_name', 'Cost center name', 'text', 'col' => 8],
            ['active', 'Active', 'switch', 'col' => 12],
        ],
    ],
    'suppliers' => [
        'model' => Models\Supplier::class, 'title' => 'Suppliers', 'one' => 'supplier', 'icon' => 'fa-truck-field',
        'group' => 'People', 'search' => ['code', 'name', 'name_ar', 'trade_name'], 'order' => ['id', 'desc'],
        'list' => ['code' => 'Code', 'name' => 'Name', 'trade_name' => 'Trade name', 'bank_name' => 'Bank', 'currency' => 'Currency', 'location' => 'Location', 'active' => 'Active'],
        'fields' => [
            ['code', 'Code', 'text', 'required' => true, 'unique' => true, 'col' => 3],
            ['name', 'Name', 'text', 'required' => true, 'col' => 5],
            ['name_ar', 'Arabic name', 'text', 'col' => 4, 'dir' => 'rtl'],
            ['trade_name', 'Trade name', 'text', 'col' => 12],
            ['bank_account_name', 'Bank account name', 'text', 'col' => 6],
            ['bank_name', 'Bank', 'text', 'col' => 6],
            ['iban', 'IBAN', 'text', 'col' => 6],
            ['account_no', 'Account number', 'text', 'col' => 3],
            ['currency', 'Currency', 'text', 'col' => 3],
            ['contract_number', 'Contract number', 'text', 'col' => 4],
            ['mistari_numrasi', 'Müşteri numarası', 'text', 'col' => 4],
            ['location', 'Location', 'select', 'options' => 'list:location', 'col' => 4],
            ['active', 'Active', 'switch', 'col' => 12],
        ],
    ],
    'partners' => [
        'model' => Models\Partner::class, 'title' => 'Partners', 'one' => 'partner', 'icon' => 'fa-handshake',
        'group' => 'People', 'search' => ['code', 'name'], 'order' => ['name', 'asc'],
        'list' => ['code' => 'Code', 'name' => 'Name'],
        'fields' => [
            ['code', 'Code', 'text', 'col' => 4],
            ['name', 'Name', 'text', 'required' => true, 'col' => 8],
        ],
    ],
    'currencies' => [
        'model' => Models\Currency::class, 'title' => 'Currencies', 'one' => 'currency', 'icon' => 'fa-money-bill-1',
        'group' => 'Lists', 'search' => ['code', 'name'], 'order' => ['sequence', 'asc'],
        'list' => ['code' => 'Code', 'name' => 'Name', 'usd_rate' => 'USD for 1', 'sequence' => 'Order', 'active' => 'Active'],
        'fields' => [
            ['code', 'Code', 'text', 'required' => true, 'unique' => true, 'col' => 3],
            ['name', 'Name', 'text', 'col' => 6],
            ['sequence', 'Order', 'int', 'col' => 3],
            ['usd_rate', 'US dollars for 1 unit', 'number', 'col' => 6, 'help' => 'QuickBooks rate, used to compare project budgets in this currency (capacity and key of the cost recovery plan).'],
            ['active', 'Active', 'switch', 'col' => 12],
        ],
    ],
    'list_items' => [
        'model' => Models\ListItem::class, 'title' => 'Drop-down lists', 'one' => 'list value', 'icon' => 'fa-list-ul',
        'group' => 'Lists', 'search' => ['name'], 'order' => ['category', 'asc'], 'filter' => ['category', Models\ListItem::CATEGORIES],
        'list' => ['category' => 'List', 'name' => 'Value', 'sequence' => 'Order', 'active' => 'Active'],
        'fields' => [
            ['category', 'List', 'select', 'options' => Models\ListItem::CATEGORIES, 'required' => true, 'col' => 4],
            ['name', 'Value', 'text', 'required' => true, 'col' => 5],
            ['sequence', 'Order', 'int', 'col' => 3],
            ['active', 'Active', 'switch', 'col' => 12],
        ],
    ],
    'txn_types' => [
        'model' => Models\TxnType::class, 'title' => 'Transaction types', 'one' => 'transaction type', 'icon' => 'fa-tags',
        'group' => 'Lists', 'search' => ['name'], 'order' => ['sequence', 'asc'],
        'list' => ['name' => 'Type', 'form_code' => 'Opens form', 'sequence' => 'Order', 'active' => 'Active'],
        'fields' => [
            ['name', 'Type', 'text', 'required' => true, 'col' => 6],
            ['form_code', 'Opens form', 'select', 'options' => 'forms', 'col' => 3],
            ['sequence', 'Order', 'int', 'col' => 3],
            ['active', 'Active', 'switch', 'col' => 12],
        ],
    ],
];
