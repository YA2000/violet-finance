<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Budget line of one project for one expense category. */
class CostCategoryLine extends Model
{
    protected $guarded = ['id'];

    public function category() { return $this->belongsTo(CostCategory::class, 'cost_category_id'); }
    public function budgetLine() { return $this->belongsTo(BudgetLine::class); }
}
