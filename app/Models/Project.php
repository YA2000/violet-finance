<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['end_date' => 'date', 'active' => 'boolean', 'balance_date' => 'date', 'qb_added_at' => 'datetime', 'start_date' => 'date', 'icr_rate' => 'float', 'spent_total' => 'decimal:2', 'icr_amount' => 'decimal:2', 'received' => 'decimal:2', 'donor_balance' => 'decimal:2', 'budget_total' => 'decimal:2', 'balance' => 'decimal:2'];

    public const STATUSES = ['Ongoing' => 'Ongoing', 'Ended' => 'Ended', 'Suspended' => 'Suspended', 'Cancelled' => 'Cancelled'];

    public function financeOfficer() { return $this->belongsTo(Individual::class, 'finance_officer_id'); }
    public function mealOfficer() { return $this->belongsTo(Individual::class, 'meal_officer_id'); }
    public function programOfficer() { return $this->belongsTo(Individual::class, 'program_officer_id'); }
    public function receipts() { return $this->hasMany(Receipt::class); }

    public function inQuickBooks(): bool
    {
        return $this->qb_added_at !== null;
    }

    public function budgetLines() { return $this->hasMany(BudgetLine::class, 'project_code', 'code'); }

    public const OFFICES = ['Turkey' => 'Turkey', 'Syria' => 'Syria'];
    public const INTERNAL = [1, 2];   // Violet (core) and the allocation project: always active, not donor projects

    public function scopeRunning($q)
    {
        return $q->where('active', true)->where('status', 'Ongoing');
    }

    public function isInternal(): bool
    {
        return in_array((int) $this->code, static::INTERNAL, true);
    }

    /** Months left including the current one; 0 when the end date has passed. */
    public function monthsLeft(): ?float
    {
        if (!$this->end_date) {
            return null;
        }
        return $this->end_date->lt(today()) ? 0.0 : round(today()->floatDiffInMonths($this->end_date), 1);
    }

    public function endsSoon(int $months = 2): bool
    {
        return $this->end_date && !$this->end_date->lt(today()) && $this->end_date->lte(today()->addMonths($months));
    }

    public function isRunning(): bool
    {
        return $this->active && ($this->status ?? 'Ongoing') === 'Ongoing';
    }

    public function currency() { return $this->belongsTo(Currency::class); }
    public function partner() { return $this->belongsTo(Partner::class); }

    public static function nextCode(): int
    {
        return ((int) static::max('code')) + 1;
    }

    public function getLabelAttribute(): string
    {
        return $this->code . ' - ' . $this->name;
    }

    protected static function booted(): void
    {
        // status and the Active switch go together: a project that is not Ongoing is not active
        static::saving(function (Project $p) {
            $p->status = $p->status ?: 'Ongoing';
            if ($p->isDirty('status') && $p->status !== 'Ongoing') {
                $p->active = false;
            } elseif ($p->isDirty('active') && $p->active && $p->status !== 'Ongoing') {
                $p->status = 'Ongoing';
            } elseif ($p->isDirty('active') && !$p->active && $p->status === 'Ongoing') {
                $p->status = 'Ended';
            } elseif ($p->isDirty('status') && $p->status === 'Ongoing') {
                $p->active = true;
            }
        });
        static::saved(function (Project $p) {
            if ($p->wasChanged('active') || $p->wasRecentlyCreated) {
                $p->active ? static::restoreLines($p) : static::archiveLines($p);
            }
            if ($p->wasChanged(['code', 'qb_name', 'name', 'partner_id'])) {
                BudgetLine::where('project_code', (string) $p->code)->get()->each->refreshDerived();
            }
        });
    }

    /** Ended / switched off: its budget lines leave every drop-down (they stay in the history). */
    public static function archiveLines(Project $p): int
    {
        return BudgetLine::where('project_code', (string) $p->code)->where('active', true)->update(['active' => false, 'archived_at' => now()]);
    }

    /** Back to Ongoing: the lines archived with the project come back (lines switched off by hand stay off). */
    public static function restoreLines(Project $p): int
    {
        return BudgetLine::where('project_code', (string) $p->code)->whereNotNull('archived_at')->update(['active' => true, 'archived_at' => null]);
    }
}
