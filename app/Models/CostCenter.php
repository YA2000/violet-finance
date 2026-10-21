<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    protected $guarded = ['id'];

    public function accountingCenter() { return $this->belongsTo(AccountingCenter::class); }
    public function currency() { return $this->belongsTo(Currency::class); }

    /** Units table lookup: "<center>-<currency>" */
    public static function find2(?string $center, ?string $currency): ?self
    {
        if (!$center || !$currency) {
            return null;
        }
        return static::whereRaw('LOWER(code) = ?', [strtolower("$center-$currency")])->first();
    }
}
