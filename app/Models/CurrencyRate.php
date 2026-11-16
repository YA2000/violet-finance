<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rate of a currency from a date on (US dollars for 1 unit). */
class CurrencyRate extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'usd_rate' => 'float'];

    public function currency() { return $this->belongsTo(Currency::class); }
    public function user() { return $this->belongsTo(User::class); }
}
