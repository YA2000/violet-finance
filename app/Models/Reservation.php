<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transaction number reservation: T + 2-digit year of the transaction date + yearly serial of at least 5 digits (T2600001).
 * A number is never given twice, even after it is deleted. Record date = the day it was reserved (created_at).
 */
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
                $number = sprintf('T%02d%05d', $year % 100, $n);
                $n++;
            } while (static::where('number', $number)->exists() || Document::where('number', $number)->exists());
            $counter->update(['next_number' => $n]);
            return $number;
        });
    }

    /** Validation of the details of a number: the type decides whether project, cost center and PR number are required. */
    public static function check(array $in): array
    {
        $v = validator($in, [
            'date' => 'required|date', 'txn_type_id' => 'required|exists:txn_types,id',
            'project_id' => 'nullable|exists:projects,id', 'cost_center_id' => 'nullable|exists:cost_centers,id',
            'description' => 'required|string|max:250', 'pr_number' => 'nullable|string|max:60',
        ], [], ['txn_type_id' => 'transaction type', 'project_id' => 'project', 'cost_center_id' => 'cost center',
            'date' => 'transaction date', 'pr_number' => 'PR number'])->validate();
        $t = TxnType::find($v['txn_type_id']);
        $err = [];
        if ($t->need_project && empty($v['project_id'])) {
            $err['project_id'] = 'The project is required for ' . $t->name . '.';
        }
        if ($t->need_cost_center && empty($v['cost_center_id'])) {
            $err['cost_center_id'] = 'The cost center is required for ' . $t->name . '.';
        }
        if ($t->pr_field === 'required' && trim((string) ($v['pr_number'] ?? '')) === '') {
            $err['pr_number'] = 'The PR number is required for ' . $t->name . '.';
        }
        if ($err) {
            throw ValidationException::withMessages($err);
        }
        $v['pr_number'] = $t->pr_field === 'off' ? null : (trim((string) ($v['pr_number'] ?? '')) ?: null);
        $v['description'] = trim($v['description']);
        return $v;
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
                'pr_number' => $vals['pr_number'] ?? null,
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
