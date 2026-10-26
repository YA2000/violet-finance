<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['end_date' => 'date', 'active' => 'boolean', 'balance_date' => 'date', 'budget_total' => 'decimal:2', 'balance' => 'decimal:2'];

    public const STATUSES = ['Ongoing' => 'Ongoing', 'Ended' => 'Ended', 'Suspended' => 'Suspended', 'Cancelled' => 'Cancelled'];

    public function budgetLines() { return $this->hasMany(BudgetLine::class, 'project_code', 'code'); }

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
