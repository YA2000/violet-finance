<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Individuals Data sheet (employees). */
class Individual extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function user() { return $this->hasOne(User::class); }
    public function contracts() { return $this->hasMany(Contract::class)->orderByDesc('start_date'); }

    public function getLabelAttribute(): string
    {
        return $this->code . ' - ' . $this->name;
    }
}
