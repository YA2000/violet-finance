<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Transaction number reservation: T + 2-digit year of the transaction date + 5-digit yearly serial. */
class Reservation extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date'];

    public const STATES = ['reserved' => 'Reserved', 'used' => 'Used', 'cancel' => 'Cancelled'];

    public function type() { return $this->belongsTo(TxnType::class, 'txn_type_id'); }
    public function project() { return $this->belongsTo(Project::class); }
    public function costCenter() { return $this->belongsTo(CostCenter::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function document() { return $this->belongsTo(Document::class); }

    /** Next free number of a year (row-locked: safe with many users at once). */
    public static function takeNumber(int $year): string
    {
        return DB::transaction(function () use ($year) {
            $counter = TxnCounter::where('year', $year)->lockForUpdate()->first();
            if (!$counter) {
                $max = 0;
                $prefix = sprintf('T%02d', $year % 100);
                foreach (static::where('number', 'like', $prefix . '%')->pluck('number') as $n) {
                    $max = max($max, (int) substr($n, 3));
                }
                $counter = TxnCounter::create(['year' => $year, 'next_number' => $max + 1]);
                $counter = TxnCounter::where('id', $counter->id)->lockForUpdate()->first();
            }
            $n = $counter->next_number;
            do {
                if ($n > 99999) {
                    throw new RuntimeException("No more numbers available for $year.");
                }
                $number = sprintf('T%02d%05d', $year % 100, $n);
                $n++;
            } while (static::where('number', $number)->exists() || Document::where('number', $number)->exists());
            $counter->update(['next_number' => $n]);
            return $number;
        });
    }

    public static function reserve(array $vals, User $user): self
    {
        $date = \Illuminate\Support\Carbon::parse($vals['date']);
        return DB::transaction(function () use ($vals, $date, $user) {
            return static::create([
                'number' => static::takeNumber((int) $date->year),
                'date' => $date->toDateString(),
                'txn_type_id' => $vals['txn_type_id'],
                'project_id' => $vals['project_id'] ?? null,
                'cost_center_id' => $vals['cost_center_id'] ?? null,
                'description' => $vals['description'],
                'user_id' => $user->id,
                'state' => 'reserved',
            ]);
        });
    }

    public function markUsed(Document $doc, string $for = 'Document'): void
    {
        $this->update(['state' => 'used', 'document_id' => $doc->id, 'used_for' => $for]);
    }

    public function release(): void
    {
        $this->update(['state' => 'reserved', 'document_id' => null, 'used_for' => null]);
    }
}
