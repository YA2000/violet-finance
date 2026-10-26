<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseRun extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['period' => 'date', 'approved_at' => 'datetime', 'allocated_at' => 'datetime'];

    public function lines() { return $this->hasMany(ExpenseLine::class)->orderBy('row_no'); }
    public function allocations() { return $this->hasMany(ExpenseAllocation::class); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }

    public function label(): string { return $this->period->format('M Y'); }
    public function tag(): string { return $this->period->format('M-Y'); }
    public function isDraft(): bool { return $this->status === 'draft'; }
}
