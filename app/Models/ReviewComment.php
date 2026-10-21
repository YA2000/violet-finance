<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewComment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['fixed_at' => 'datetime', 'closed_at' => 'datetime', 'returned' => 'boolean'];

    public const STATES = ['open' => 'Open', 'fixed' => 'Fixed — to check', 'closed' => 'Closed'];

    public function document() { return $this->belongsTo(Document::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function attachment() { return $this->belongsTo(Attachment::class); }
    public function fixer() { return $this->belongsTo(User::class, 'fixed_by'); }
}
