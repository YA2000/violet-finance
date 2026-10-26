<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A project budget line that pays (part of) a support employee: the funding map of the shared staff. */
class FundingLine extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function budgetLine() { return $this->belongsTo(BudgetLine::class); }
    public function individual() { return $this->belongsTo(Individual::class); }
}
