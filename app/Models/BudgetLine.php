<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master sheet: budget lines / QuickBooks items. */
class BudgetLine extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function project() { return $this->belongsTo(Project::class); }
    public function accountingCenter() { return $this->belongsTo(AccountingCenter::class); }
    public function expenseAccount() { return $this->belongsTo(Account::class, 'expense_account_id'); }
    public function incomeAccount() { return $this->belongsTo(Account::class, 'income_account_id'); }

    public static function makeCode(array $v): string
    {
        $code = implode('-', [$v['project_code'] ?? '', $v['accounting_center_code'] ?? '', $v['budget_line'] ?? '']);
        if (!empty($v['sub_line'])) {
            $code .= '-(' . $v['sub_line'] . ')';
        }
        return $code;
    }

    /** Same formulas as the Master sheet columns. */
    public function fillDerived(): void
    {
        if (!$this->code) {
            $this->code = static::makeCode($this->attributesToArray());
        }
        $project = Project::where('code', (int) $this->project_code)->first();
        $center = AccountingCenter::where('code', $this->accounting_center_code)->first();
        $acc = $this->expense_account_id ? Account::find($this->expense_account_id) : null;
        $this->project_id = $project?->id;
        $this->accounting_center_id = $center?->id;
        $this->project_name = $project?->qb_name ?: '-';
        $this->location = $center?->location ?: 'Donation';
        $this->accounting_center_name = $center?->master_name ?: 'No Accounting Center';
        $this->partner_name = $project?->partner?->name ?? '';
        $this->turkish_account = trim(implode(' ', array_filter([$acc?->trac, $acc?->tr_account_name])));
        $this->parent_bl = $this->sub_line ? $this->project_code . '-' . $this->budget_line : null;
        $this->qb_name = $this->parent_bl ? $this->parent_bl . ':' . $this->code : $this->code;
    }

    public function refreshDerived(): void
    {
        $this->fillDerived();
        $this->saveQuietly();
    }

    protected static function booted(): void
    {
        static::saving(fn (BudgetLine $b) => $b->fillDerived());
    }
}
