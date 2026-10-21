<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['end_date' => 'date', 'active' => 'boolean'];

    public function currency() { return $this->belongsTo(Currency::class); }
    public function partner() { return $this->belongsTo(Partner::class); }

    public static function nextCode(): int
    {
        return ((int) static::max('code')) + 1;
    }

    public function getLabelAttribute(): string
    {
        return $this->code . ' - ' . $this->name;
    }

    protected static function booted(): void
    {
        static::saved(function (Project $p) {
            BudgetLine::where('project_code', (string) $p->code)->get()->each->refreshDerived();
        });
    }
}
