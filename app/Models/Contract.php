<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Employment contract. A change of position or salary ends the contract and opens a new one (previous_id). */
class Contract extends Model
{
    protected $table = 'hr_contracts';
    protected $guarded = ['id'];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'active' => 'boolean', 'rate' => 'float', 'fte' => 'float'];

    public const OFFICES = ['Syria' => 'Syria', 'Turkey' => 'Turkey'];
    public const EMPLOYMENT = ['full_time' => 'Full time', 'part_time' => 'Part time', 'daily' => 'Daily worker', 'shift' => 'Shifts (hospital)'];
    public const COST_TYPES = ['direct' => 'Direct (works on projects: LOE from HR)', 'shared' => 'Shared support staff (LOE from the cost recovery plan)'];
    public const BASIS = ['monthly' => 'Monthly salary', 'daily' => 'Per day', 'shift' => 'Per shift', 'hourly' => 'Per hour'];

    public function individual() { return $this->belongsTo(Individual::class); }
    public function defaultBudgetLine() { return $this->belongsTo(BudgetLine::class, 'default_budget_line_id'); }
    public function previous() { return $this->belongsTo(Contract::class, 'previous_id'); }

    /** Contracts that cover at least one day of the month. */
    public function scopeInMonth($q, Carbon $period)
    {
        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth();
        return $q->where('active', true)->whereDate('start_date', '<=', $end)
            ->where(fn ($w) => $w->whereNull('end_date')->orWhereDate('end_date', '>=', $start));
    }

    public function isTurkey(): bool
    {
        return $this->office === 'Turkey';
    }

    public function basisLabel(): string
    {
        return static::BASIS[$this->pay_basis] ?? $this->pay_basis;
    }

    public function unitWord(): string
    {
        return ['monthly' => 'days', 'daily' => 'days', 'shift' => 'shifts', 'hourly' => 'hours'][$this->pay_basis] ?? 'units';
    }
}
