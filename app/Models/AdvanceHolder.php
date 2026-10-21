<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A person or sub-cashbox that receives operating advances (linked to an advances account of the chart of accounts). */
class AdvanceHolder extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public const KINDS = ['person' => 'Person', 'cashbox' => 'Sub-cashbox'];

    public function user() { return $this->belongsTo(User::class); }
    public function individual() { return $this->belongsTo(Individual::class); }
    public function account() { return $this->belongsTo(Account::class); }
    public function currency() { return $this->belongsTo(Currency::class); }
    public function advances() { return $this->hasMany(Advance::class, 'holder_id'); }
    public function expenses() { return $this->hasMany(AdvanceExpense::class, 'holder_id'); }
    public function settlements() { return $this->hasMany(AdvanceSettlement::class, 'holder_id'); }

    public function getLabelAttribute(): string
    {
        return $this->name . ($this->kind === 'cashbox' ? ' (cashbox)' : '');
    }

    /**
     * Totals over all advances. Money figures are per currency: ['granted' => ['USD' => 500.0], ...];
     * overdue / due_soon are counts.
     */
    public function totals(): array
    {
        return static::sumAdvances($this->advances);
    }

    public static function sumAdvances($advances): array
    {
        $t = ['granted' => [], 'spent' => [], 'cleared' => [], 'refunded' => [], 'open' => [], 'cash' => [], 'overdue' => 0, 'due_soon' => 0];
        foreach ($advances as $a) {
            $x = $a->figures();
            $c = $a->currency ?: '?';
            foreach (['granted', 'spent', 'cleared', 'refunded', 'open', 'cash'] as $k) {
                $t[$k][$c] = ($t[$k][$c] ?? 0) + $x[$k];
            }
            $t['overdue'] += $a->isOverdue() ? 1 : 0;
            $t['due_soon'] += $a->isDueSoon() ? 1 : 0;
        }
        return $t;
    }
}
