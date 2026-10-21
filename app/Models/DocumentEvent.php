<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentEvent extends Model
{
    protected $table = 'document_events';
    protected $guarded = ['id'];

    public function user() { return $this->belongsTo(User::class); }
    public function document() { return $this->belongsTo(Document::class); }
}
