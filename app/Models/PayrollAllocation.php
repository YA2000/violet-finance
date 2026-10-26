<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollAllocation extends Model
{
    protected $guarded = ['id'];

    public function run() { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function line() { return $this->belongsTo(PayrollLine::class, 'payroll_line_id'); }
    public function individual() { return $this->belongsTo(Individual::class); }
    public function budgetLine() { return $this->belongsTo(BudgetLine::class); }
}
