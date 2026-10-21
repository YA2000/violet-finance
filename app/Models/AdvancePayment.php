<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A transaction the advance holder pays out of a "payments" advance; the proof goes to the transaction's file. */
class AdvancePayment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['paid_at' => 'datetime', 'amount' => 'float'];

    public function advance() { return $this->belongsTo(Advance::class); }
    public function document() { return $this->belongsTo(Document::class); }
    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }
    public function attachments() { return $this->hasMany(Attachment::class, 'advance_payment_id'); }
}
