<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A payment received on a bank account or in a cash box (Inform a new Receive Payment). */
class Receipt extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'float', 'project_amount' => 'float', 'bank_ref_auto' => 'boolean',
                        'assigned_at' => 'datetime', 'recorded_at' => 'datetime'];

    public const STATES = [
        'project' => 'Pending to project determination',
        'record' => 'Pending to Record',
        'recorded' => 'Recorded to the System',
    ];

    public function bankAccount() { return $this->belongsTo(Account::class, 'bank_account_id'); }
    public function project() { return $this->belongsTo(Project::class); }
    public function reservation() { return $this->belongsTo(Reservation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function assigner() { return $this->belongsTo(User::class, 'assigned_by'); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
    public function attachments() { return $this->hasMany(Attachment::class, 'receipt_id'); }
    public function events() { return $this->hasMany(ReceiptEvent::class)->latest('id'); }

    public static function make(array $vals): self
    {
        return DB::transaction(function () use ($vals) {
            $vals['number'] = Advance::nextNumber('RP', static::class, (int) date('Y', strtotime($vals['date'])));
            if (empty($vals['bank_ref'])) {
                $vals['bank_ref'] = $vals['number'];
                $vals['bank_ref_auto'] = true;
            }
            return static::create($vals);
        });
    }

    public function log(string $type, string $msg, ?User $u = null): void
    {
        $this->events()->create(['type' => $type, 'message' => $msg, 'user_id' => $u?->id]);
    }

    public function needsRequest(): bool
    {
        $pc = $this->project?->currency?->code;
        return $pc && strtoupper($pc) !== strtoupper($this->currency);
    }
}
