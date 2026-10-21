<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function getLabelAttribute(): string
    {
        return $this->code . ' - ' . $this->name;
    }
}
