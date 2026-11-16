<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable trail: rows are only added; each row carries a hash chained to the previous one. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;
    protected $guarded = ['id'];
    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }

    protected static function booted(): void
    {
        static::updating(fn () => false);   // never changed
        static::deleting(fn () => false);   // never removed
    }

    public function payload(): string
    {
        return json_encode([$this->action, $this->subject_type, $this->subject_id, $this->subject_label, $this->summary,
            $this->before, $this->after, $this->user_id, $this->created_at?->format('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
    }
}
