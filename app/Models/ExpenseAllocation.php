<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseAllocation extends Model
{
    protected $guarded = ['id'];

    public function line() { return $this->belongsTo(ExpenseLine::class, 'expense_line_id'); }
    public function budgetLine() { return $this->belongsTo(BudgetLine::class); }
}
