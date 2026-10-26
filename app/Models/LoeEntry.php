<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Level of effort: share of an employee's month given to one budget line. */
class LoeEntry extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['period' => 'date', 'percent' => 'float'];

    public const SOURCES = ['employee' => 'Employee', 'hr' => 'HR', 'import' => 'Excel import', 'copy' => 'Copied from last month', 'contract' => 'Contract default'];

    public function individual() { return $this->belongsTo(Individual::class); }
    public function budgetLine() { return $this->belongsTo(BudgetLine::class); }
    public function user() { return $this->belongsTo(User::class); }
}
