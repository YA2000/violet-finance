<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function currency() { return $this->belongsTo(Currency::class); }

    public function getLabelAttribute(): string
    {
        return $this->code . ' - ' . $this->name;
    }
}
