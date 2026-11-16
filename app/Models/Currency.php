<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Currency. in_usd: its rate is typed as US dollars for 1 unit (EUR, GBP, KWD...), otherwise units for 1 US dollar.
 * usd_rate: today's rate (US dollars for 1 unit). currency_rates keeps the dated history.
 */
class Currency extends Model
{
    protected $table = 'currencies';
    protected $guarded = ['id'];
    protected $casts = ['in_usd' => 'boolean', 'active' => 'boolean', 'usd_rate' => 'float'];

    protected static array $today = [];
    protected static ?array $history = null;

    public function rates() { return $this->hasMany(CurrencyRate::class)->orderByDesc('date'); }

    /** US dollars for 1 unit: the dated rate in force on $date (last one on or before it), else today's rate. 0 = unknown. */
    public static function usdRate(?string $code, $date = null): float
    {
        $code = strtoupper((string) $code) ?: 'USD';
        if ($code === 'USD') {
            return 1.0;
        }
        if ($date) {
            $d = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
            foreach (static::history()[$code] ?? [] as [$day, $rate]) {   // newest first
                if ($day <= $d) {
                    return $rate;
                }
            }
        }
        if (!static::$today) {
            static::$today = static::pluck('usd_rate', 'code')->mapWithKeys(fn ($v, $k) => [strtoupper($k) => (float) $v])->all();
        }
        return static::$today[$code] ?? 0.0;
    }

    protected static function history(): array
    {
        if (static::$history !== null) {
            return static::$history;
        }
        static::$history = [];
        if (!Schema::hasTable('currency_rates')) {
            return static::$history;
        }
        $codes = static::pluck('code', 'id');
        foreach (CurrencyRate::orderByDesc('date')->get(['currency_id', 'date', 'usd_rate']) as $r) {
            static::$history[strtoupper($codes[$r->currency_id] ?? '')][] = [$r->date->format('Y-m-d'), (float) $r->usd_rate];
        }
        return static::$history;
    }

    public static function flush(): void
    {
        static::$today = [];
        static::$history = null;
    }

    /** "1 EUR = 1.16 USD" or "1 USD = 122 SYP" from a USD-per-unit rate. */
    public function say(float $usdPerUnit): string
    {
        $n = fn ($x) => rtrim(rtrim(number_format($x, 4, '.', ','), '0'), '.');
        if ($this->code === 'USD' || $usdPerUnit <= 0) {
            return '';
        }
        return $this->in_usd ? '1 ' . $this->code . ' = ' . $n($usdPerUnit) . ' USD' : '1 USD = ' . $n(1 / $usdPerUnit) . ' ' . $this->code;
    }
}
