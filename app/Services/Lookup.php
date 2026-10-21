<?php

namespace App\Services;

use App\Forms\BaseForm;
use App\Models\Account;
use App\Models\AccountingCenter;
use App\Models\BudgetLine;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\Individual;
use App\Models\ListItem;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\TxnType;
use Illuminate\Support\Str;

/** Searchable drop-downs (master data) used by the forms. */
class Lookup
{
    protected static array $cache = [];

    public static function flush(): void
    {
        static::$cache = [];
    }

    public const SOURCES = [
        'currencies' => [Currency::class, ['code', 'name'], 'code'],
        'projects' => [Project::class, ['code', 'name', 'qb_name'], 'code'],
        'accounts' => [Account::class, ['code', 'name'], 'code'],
        'budget_lines' => [BudgetLine::class, ['code', 'description', 'project_name'], 'code'],
        'individuals' => [Individual::class, ['code', 'name', 'name_ar'], 'code'],
        'suppliers' => [Supplier::class, ['code', 'name', 'trade_name'], 'code'],
        'cost_centers' => [CostCenter::class, ['code', 'name'], 'code'],
        'accounting_centers' => [AccountingCenter::class, ['code', 'name'], 'code'],
        'txn_types' => [TxnType::class, ['name'], 'sequence'],
        'partners' => [\App\Models\Partner::class, ['name', 'code'], 'name'],
        'advance_holders' => [\App\Models\AdvanceHolder::class, ['name'], 'name'],
        'bank_accounts' => [Account::class, ['code', 'name'], 'code', ['category' => 'Bank']],
        'users' => [\App\Models\User::class, ['name', 'email'], 'name'],
    ];

    /** Names shown in the form builder for the drop-down sources. */
    public const SOURCE_NAMES = [
        'projects' => 'Projects', 'budget_lines' => 'Budget lines', 'accounts' => 'Chart of accounts', 'bank_accounts' => 'Bank accounts',
        'cost_centers' => 'Cost centers (units)', 'accounting_centers' => 'Accounting centers', 'currencies' => 'Currencies',
        'individuals' => 'Individuals (employees)', 'suppliers' => 'Suppliers', 'payees' => 'Suppliers + individuals',
        'partners' => 'Partners / donors', 'advance_holders' => 'Advance holders', 'txn_types' => 'Transaction types', 'users' => 'Users',
    ];

    public static function sourceNames(): array
    {
        $out = static::SOURCE_NAMES;
        foreach (ListItem::CATEGORIES as $k => $l) {
            $out['list:' . $k] = 'List: ' . $l;
        }
        return $out;
    }

    public static function text(string $source, $m): string
    {
        if (!$m) {
            return '';
        }
        return match ($source) {
            'currencies' => $m->code,
            'budget_lines' => $m->code,
            'txn_types' => $m->name,
            'cost_centers', 'accounting_centers', 'bank_accounts' => $m->code . ' - ' . $m->name,
            'advance_holders', 'users' => $m->name,
            default => str_starts_with($source, 'list:') ? $m->name : (($m->code ?? '') . ' - ' . $m->name),
        };
    }

    public static function sub(string $source, $m): string
    {
        return match ($source) {
            'budget_lines' => trim(($m->description ?? '') . ' · ' . ($m->project_name ?? ''), ' ·'),
            'individuals' => (string) ($m->title ?? ''),
            'suppliers' => (string) ($m->trade_name ?? ''),
            'projects' => (string) ($m->qb_name ?? ''),
            default => '',
        };
    }

    /** Search for the drop-down: [{id, text, sub}] */
    public static function search(string $source, string $q, int $limit = 30): array
    {
        if ($source === 'payees') {
            $a = static::search('suppliers', $q, (int) ($limit / 2));
            $b = static::search('individuals', $q, (int) ($limit / 2));
            $out = [];
            foreach ($a as $r) {
                $out[] = ['id' => 'S:' . $r['id'], 'text' => $r['text'], 'sub' => 'Supplier · ' . $r['sub']];
            }
            foreach ($b as $r) {
                $out[] = ['id' => 'I:' . $r['id'], 'text' => $r['text'], 'sub' => 'Individual · ' . $r['sub']];
            }
            return $out;
        }
        if (str_starts_with($source, 'list:')) {
            $query = ListItem::where('category', substr($source, 5))->where('active', true)->orderBy('sequence');
            if ($q !== '') {
                $query->where('name', 'like', "%$q%");
            }
            return $query->limit($limit)->get()->map(fn ($m) => ['id' => $m->id, 'text' => $m->name, 'sub' => ''])->all();
        }
        if (!isset(static::SOURCES[$source])) {
            return [];
        }
        [$cls, $cols, $order] = static::SOURCES[$source];
        $query = $cls::query()->where(static::SOURCES[$source][3] ?? []);
        if (static::hasActive((new $cls)->getTable())) {
            $query->where('active', true);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($cols, $q) {
                foreach ($cols as $c) {
                    $w->orWhere($c, 'like', "%$q%");
                }
            });
            // exact / prefix code matches first
            $query->orderByRaw('CASE WHEN ' . $cols[0] . ' = ? THEN 0 WHEN ' . $cols[0] . ' LIKE ? THEN 1 ELSE 2 END', [$q, "$q%"]);
        }
        $query->orderBy($order);
        return $query->limit($limit)->get()->map(fn ($m) => [
            'id' => $m->id, 'text' => static::text($source, $m), 'sub' => static::sub($source, $m),
        ])->all();
    }

    protected static function hasActive(string $table): bool
    {
        static $c = [];
        return $c[$table] ??= \Illuminate\Support\Facades\Schema::hasColumn($table, 'active');
    }

    /** Record of a lookup value (cached per request). */
    public static function get(string $source, $id)
    {
        if ($id === null || $id === '') {
            return null;
        }
        $key = $source . '#' . $id;
        if (array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }
        if ($source === 'payees') {
            [$k, $n] = explode(':', (string) $id) + [null, null];
            $m = $k === 'S' ? Supplier::find($n) : Individual::find($n);
            if ($m) {
                $m->payee_kind = $k === 'S' ? 'supplier' : 'individual';
            }
        } elseif (str_starts_with($source, 'list:')) {
            $m = ListItem::find($id);
        } else {
            $m = static::SOURCES[$source][0]::find($id);
        }
        return static::$cache[$key] = $m;
    }

    public static function label(string $source, $id): string
    {
        return static::text($source, static::get($source, $id));
    }

    /** Labels of all lookup values of a form (header + lines), keyed "field" / "table.i.field". */
    public static function labels(BaseForm $form, array $d): array
    {
        $out = [];
        foreach ($form->fields() as $k => $f) {
            if (($f['type'] === 'lookup') && !empty($d[$k])) {
                $out[$k] = static::label($f['source'], $d[$k]);
            }
        }
        foreach ($form->tables() as $tk => $t) {
            foreach ($d[$tk] ?? [] as $i => $row) {
                foreach ($t['columns'] as $c) {
                    if ($c['type'] === 'lookup' && !empty($row[$c['key']])) {
                        $out["$tk.$i." . $c['key']] = static::label($c['source'], $row[$c['key']]);
                    }
                }
            }
        }
        return $out;
    }

    /** Details a formula can read from a source (LOOKUP / FIND). */
    public static function attributes(string $source): array
    {
        static $c = [];
        if (isset($c[$source])) {
            return $c[$source];
        }
        if ($source === 'payees') {
            return $c[$source] = array_merge(['id', 'kind'], array_keys(static::payee(null)));
        }
        if (str_starts_with($source, 'list:')) {
            return $c[$source] = ['id', 'name'];
        }
        $cls = static::SOURCES[$source][0] ?? null;
        if ($source === 'users') {
            return $c[$source] = ['id', 'name', 'email'];
        }
        if (!$cls) {
            throw new \App\Support\Formula\FormulaError('Unknown list "' . $source . '".');
        }
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing((new $cls())->getTable());
        return $c[$source] = array_values(array_diff($cols, ['created_at', 'updated_at']));
    }

    protected static function checkAttr(string $source, string $attr): void
    {
        if (!in_array($attr, static::attributes($source), true)) {
            throw new \App\Support\Formula\FormulaError('"' . $attr . '" is not a detail of ' . $source . '. Available: ' . implode(', ', static::attributes($source)));
        }
    }

    /** One detail of the record chosen in a lookup field ('' when nothing is chosen). */
    public static function attr(string $source, $id, string $attr)
    {
        static::checkAttr($source, $attr);
        if ($id === null || $id === '') {
            return '';
        }
        if ($source === 'payees') {
            if ($attr === 'id') {
                return $id;
            }
            if ($attr === 'kind') {
                return static::get('payees', $id)?->payee_kind ?? '';
            }
            return static::payee($id)[$attr] ?? '';
        }
        $m = static::get($source, $id);
        if (!$m) {
            return '';
        }
        $v = $m->getAttributes()[$attr] ?? null;
        return $v ?? '';
    }

    /** Detail of a record found by another detail (case-insensitive), e.g. cost center by code. */
    public static function findAttr(string $source, string $attr, $value, string $return)
    {
        static::checkAttr($source, $attr);
        static::checkAttr($source, $return);
        if ($value === null || $value === '' || $source === 'payees') {
            return '';
        }
        $key = 'find#' . $source . '#' . $attr . '#' . mb_strtolower((string) $value);
        if (!array_key_exists($key, static::$cache)) {
            if (str_starts_with($source, 'list:')) {
                $q = ListItem::where('category', substr($source, 5));
            } else {
                $q = static::SOURCES[$source][0]::query();
            }
            $v = is_float($value) && floor($value) == $value ? (string) (int) $value : (string) $value;
            static::$cache[$key] = $q->whereRaw('LOWER(' . $attr . ') = ?', [mb_strtolower($v)])->first();
        }
        $m = static::$cache[$key];
        return $m ? ($m->getAttributes()[$return] ?? '') : '';
    }

    /** Payee as a uniform object (Recipient table of the workbook). */
    public static function payee($id): array
    {
        $m = $id === null ? null : static::get('payees', $id);
        if (!$m) {
            return ['code' => '', 'name' => '', 'bank_account_name' => '', 'bank' => '', 'iban' => '', 'title' => '',
                    'location' => '', 'mustari_numrasi' => '', 'contract_number' => ''];
        }
        if ($m->payee_kind === 'supplier') {
            return ['code' => $m->code, 'name' => $m->name, 'bank_account_name' => $m->bank_account_name ?? '',
                    'bank' => $m->bank_name ?? '', 'iban' => $m->iban ?? '', 'title' => '',
                    'location' => $m->location ?? '', 'mustari_numrasi' => $m->mistari_numrasi ?? '',
                    'contract_number' => $m->contract_number ?? ''];
        }
        return ['code' => $m->code, 'name' => $m->name, 'bank_account_name' => $m->name, 'bank' => $m->bank_name ?? '',
                'iban' => $m->iban ?? '', 'title' => '', 'location' => $m->location ?? '',
                'mustari_numrasi' => $m->mustari_numrasi ?? '', 'contract_number' => $m->contract_number ?? ''];
    }
}
