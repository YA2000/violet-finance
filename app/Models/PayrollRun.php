<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['period' => 'date', 'approved_at' => 'datetime', 'allocated_at' => 'datetime',
        'rate_try' => 'float', 'rate_syp' => 'float', 'rate_eur' => 'float'];

    public const STATES = ['draft' => 'Draft', 'approved' => 'Approved'];

    public function lines() { return $this->hasMany(PayrollLine::class); }
    public function allocations() { return $this->hasMany(PayrollAllocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }

    public function label(): string
    {
        return $this->period->format('M Y');
    }

    /** "Sep-2026", as in the descriptions of the payroll sheets. */
    public function tag(): string
    {
        return $this->period->format('M-Y');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** US dollars for 1 unit of a currency, for this month. */
    public function usdRate(string $currency): float
    {
        return match (strtoupper($currency)) {
            'USD' => 1.0,
            'TRY' => (float) $this->rate_try,
            'SYP' => (float) $this->rate_syp,
            'EUR' => (float) $this->rate_eur,
            default => 0.0,
        };
    }
}
