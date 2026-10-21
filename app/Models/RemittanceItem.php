<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One transaction listed on a Remittance Statement, and its payment by the treasury. */
class RemittanceItem extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'float', 'paid_amount' => 'float', 'paid_date' => 'date', 'paid_at' => 'datetime', 'adjusted' => 'boolean'];

    public function remittance() { return $this->belongsTo(Document::class, 'remittance_id'); }
    public function document() { return $this->belongsTo(Document::class); }
    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }
}
