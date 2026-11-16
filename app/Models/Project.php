<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['agreement_rate' => 'float', 'year' => 'integer', 'year_no' => 'integer', 'end_date' => 'date', 'active' => 'boolean', 'balance_date' => 'date', 'qb_added_at' => 'datetime', 'start_date' => 'date', 'icr_rate' => 'float', 'spent_total' => 'decimal:2', 'icr_amount' => 'decimal:2', 'received' => 'decimal:2', 'donor_balance' => 'decimal:2', 'budget_total' => 'decimal:2', 'balance' => 'decimal:2'];

    /**
     * Pipeline / Ongoing / Ended / Closed follow the dates and the donor balance by themselves (status_mode auto);
     * Suspended, Cancelled - and Closed chosen by hand - stay as set (manual).
     * Closed, Suspended and Cancelled switch the project off: it and its budget lines leave the drop-downs.
     */
    public const STATUSES = ['Pipeline' => 'Pipeline', 'Ongoing' => 'Ongoing', 'Ended' => 'Ended', 'Closed' => 'Closed', 'Suspended' => 'Suspended', 'Cancelled' => 'Cancelled'];
    public const AUTO = ['Pipeline', 'Ongoing', 'Ended'];
    public const OFF = ['Closed', 'Suspended', 'Cancelled'];
    public const SOON_DAYS = 30;

    public function amendments() { return $this->hasMany(ProjectAmendment::class)->orderByDesc('date')->orderByDesc('id'); }

    /** 298-2026-084-ALRAHMA (or just the number for projects registered before the new numbering). */
    public function getFullCodeAttribute(): string
    {
        return $this->year_no ? $this->code . '-' . $this->name : (string) $this->code;
    }

    public static function partnerTag(?Partner $p): string
    {
        $t = $p?->short ?: $p?->name ?: 'NA';
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', \Illuminate\Support\Str::ascii($t))) ?: 'NA';
    }

    public static function nextYearNo(int $year): int
    {
        return ((int) static::where('year', $year)->max('year_no')) + 1;
    }

    /** Status the dates give (auto mode): before the start Pipeline, after the end Ended, otherwise Ongoing. */
    public function statusByDates(): string
    {
        if ($this->isInternal()) {
            return 'Ongoing';
        }
        if ($this->start_date && $this->start_date->gt(today())) {
            return 'Pipeline';
        }
        if ($this->end_date && $this->end_date->lt(today())) {
            return 'Ended';
        }
        return 'Ongoing';
    }

    /**
     * Daily follow-up of every automatic project: status by the dates; an ended project whose donor balance is zero
     * (received = spent + overhead) closes by itself. Runs once a day, from the dashboard / projects pages.
     */
    public static function refreshStatuses(bool $force = false): int
    {
        if (!$force && Setting::get('projects_refreshed_on') === today()->toDateString()) {
            return 0;
        }
        Setting::put('projects_refreshed_on', today()->toDateString());
        try {
            return static::doRefresh();
        } catch (\Throwable $e) {
            report($e);   // the follow-up must never break a page: logged, tried again tomorrow
            return 0;
        }
    }

    protected static function doRefresh(): int
    {
        $n = 0;
        $auto = static::with('currency')->where('status_mode', 'auto')->get();
        $ended = $auto->filter(fn ($p) => $p->statusByDates() === 'Ended');
        $figs = $ended->isEmpty() ? [] : \App\Services\LineBudget::projectFigures($ended);
        foreach ($auto as $p) {
            $s = $p->statusByDates();
            if ($s === 'Ended' && ($f = $figs[(string) $p->code] ?? null) && $f['received'] !== null && $f['spent'] !== null
                && abs((float) $f['received'] - (float) $f['spent'] - (float) $f['overhead']) < 1) {
                $s = 'Closed';
            }
            if ($p->status !== $s) {
                $p->status = $s;
                $p->save();
                $n++;
            }
        }
        return $n;
    }

    /** Ending within 30 days (and not ended yet). */
    public function endingSoon(): bool
    {
        return $this->end_date && !$this->end_date->lt(today()) && $this->end_date->lte(today()->addDays(static::SOON_DAYS));
    }

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

    /** Open projects: their lines can be used (Pipeline, Ongoing, and Ended until the donor balance closes). */
    public function scopeRunning($q)
    {
        return $q->where('active', true)->whereIn('status', static::AUTO);
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
        return $this->active && in_array($this->status ?? 'Ongoing', static::AUTO, true);
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
        // status and the Active switch go together: Closed / Suspended / Cancelled = off, the others = on
        static::saving(function (Project $p) {
            $p->status = $p->status ?: 'Ongoing';
            if ($p->isDirty('active') && !$p->isDirty('status')) {
                if (!$p->active && !in_array($p->status, static::OFF, true)) {
                    [$p->status, $p->status_mode] = ['Closed', 'manual'];
                } elseif ($p->active && in_array($p->status, static::OFF, true)) {
                    $p->status_mode = 'auto';
                    $p->status = $p->statusByDates();
                }
            } elseif ($p->isDirty('status')) {
                // chosen by hand: an automatic status hands the project back to the dates
                if (in_array($p->status, static::AUTO, true) && $p->status_mode === 'manual') {
                    $p->status_mode = 'auto';
                    $p->status = $p->statusByDates();
                }
            }
            if ($p->status_mode === 'auto' && in_array($p->status, static::AUTO, true) && $p->isDirty(['start_date', 'end_date', 'status_mode'])) {
                $p->status = $p->statusByDates();
            }
            $p->active = !in_array($p->status, static::OFF, true);
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

    /** Closed / switched off: its budget lines leave every drop-down (they stay in the history). */
    public static function archiveLines(Project $p): int
    {
        return BudgetLine::where('project_code', (string) $p->code)->where('active', true)->update(['active' => false, 'archived_at' => now()]);
    }

    /** Open again: the lines archived with the project come back (lines switched off by hand stay off). */
    public static function restoreLines(Project $p): int
    {
        return BudgetLine::where('project_code', (string) $p->code)->whereNotNull('archived_at')->update(['active' => true, 'archived_at' => null]);
    }
}
