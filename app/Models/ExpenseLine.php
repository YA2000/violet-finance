<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseLine extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['txn_date' => 'date', 'excluded' => 'boolean', 'amount_usd' => 'float'];

    public function run() { return $this->belongsTo(ExpenseRun::class, 'expense_run_id'); }
    public function allocations() { return $this->hasMany(ExpenseAllocation::class); }
}
