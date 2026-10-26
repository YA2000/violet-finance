<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollLine extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['manual' => 'boolean'];

    public function run() { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function individual() { return $this->belongsTo(Individual::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
    public function allocations() { return $this->hasMany(PayrollAllocation::class); }

    public function isTurkey(): bool
    {
        return $this->office === 'Turkey';
    }
}
