<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QbReconciliation extends Model
{
    protected $guarded = [];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'summary' => 'array', 'other' => 'array', 'columns' => 'array', 'tolerance' => 'float'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function results(): array
    {
        return json_decode($this->rows ?: '[]', true) ?: [];
    }
}
