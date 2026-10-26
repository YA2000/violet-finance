<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCategory extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public const BASIS = ['fte_office' => 'FTE of the office (payroll of the month)', 'fte_all' => 'FTE of all offices', 'direct' => 'Direct: each line to the project chosen on it'];

    public function lines() { return $this->hasMany(CostCategoryLine::class); }
}
