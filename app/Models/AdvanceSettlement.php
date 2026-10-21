<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvanceSettlement extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['submitted_at' => 'datetime'];

    public const STATES = ['submitted' => 'Waiting for finance', 'accepted' => 'JAR in progress', 'returned' => 'Returned to holder', 'settled' => 'Cleared'];

    public function holder() { return $this->belongsTo(AdvanceHolder::class, 'holder_id'); }
    public function advance() { return $this->belongsTo(Advance::class); }
    public function expenses() { return $this->hasMany(AdvanceExpense::class, 'settlement_id')->orderBy('date'); }
    public function document() { return $this->belongsTo(Document::class); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }

    public function total(): float
    {
        return (float) $this->expenses->sum('amount');
    }
}
