<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormVersion extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['schema' => 'array'];

    public function user() { return $this->belongsTo(User::class); }
}
