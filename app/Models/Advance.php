<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Advance extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['granted_at' => 'date', 'due_date' => 'date', 'closed_at' => 'datetime', 'amount' => 'float', 'refunded' => 'float'];

    public const KINDS = ['operating' => 'Operating advance', 'payments' => 'Advance to pay listed transactions'];
    public const STATES = ['open' => 'Open', 'closed' => 'Closed'];

    public function holder() { return $this->belongsTo(AdvanceHolder::class, 'holder_id'); }
    public function document() { return $this->belongsTo(Document::class); }
    public function expenses() { return $this->hasMany(AdvanceExpense::class)->orderBy('date')->orderBy('id'); }
    public function settlements() { return $this->hasMany(AdvanceSettlement::class)->latest('id'); }
    public function payments() { return $this->hasMany(AdvancePayment::class)->orderBy('id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public static function nextNumber(string $prefix, string $model, int $year): string
    {
        $p = sprintf('%s-%02d-', $prefix, $year % 100);
        $last = $model::where('number', 'like', $p . '%')->orderByDesc('number')->value('number');
        return $p . sprintf('%04d', $last ? ((int) substr($last, strlen($p))) + 1 : 1);
    }

    public static function make(array $vals): self
    {
        return DB::transaction(function () use ($vals) {
            $vals['number'] = static::nextNumber('ADV', static::class, (int) date('Y', strtotime($vals['granted_at'] ?? 'now')));
            return static::create($vals);
        });
    }

    /** granted, spent (all recorded expenses), cleared (in a final JAR), refunded, open (still to clear), cash (in hand). */
    public function figures(): array
    {
        $exp = $this->relationLoaded('expenses') ? $this->expenses : $this->expenses()->get();
        $spent = (float) $exp->whereNotIn('status', ['returned'])->sum('amount');
        $cleared = (float) $exp->where('status', 'settled')->sum('amount');
        $paidLinks = $this->kind === 'payments' ? (float) $this->payments->where('status', 'paid')->sum('amount') : 0.0;
        $spent += $paidLinks;
        $cleared += $paidLinks;
        return ['granted' => $this->amount, 'spent' => $spent, 'cleared' => $cleared, 'refunded' => $this->refunded,
                'open' => max(0, $this->amount - $cleared - $this->refunded), 'cash' => $this->amount - $spent - $this->refunded];
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_date && $this->due_date->lt(today());
    }

    public function isDueSoon(?int $days = null): bool
    {
        $days ??= (int) Setting::get('due_soon_days');
        return $this->status === 'open' && $this->due_date && !$this->isOverdue() && $this->due_date->lte(today()->addDays($days));
    }

    public function dueLabel(): string
    {
        if (!$this->due_date) {
            return 'No due date';
        }
        $d = (int) today()->diffInDays($this->due_date, false);
        return $d < 0 ? abs($d) . ' days overdue' : ($d === 0 ? 'Due today' : 'Due in ' . $d . ' days');
    }

    public function getLabelAttribute(): string
    {
        return $this->number . ' · ' . number_format($this->amount, 2) . ' ' . $this->currency;
    }
}
