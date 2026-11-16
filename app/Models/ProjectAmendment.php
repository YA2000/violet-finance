<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Extension of an agreement: new end date and / or more money, kept as history on the project. */
class ProjectAmendment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'old_end_date' => 'date', 'new_end_date' => 'date', 'old_amount' => 'float', 'extra_amount' => 'float'];

    public function project() { return $this->belongsTo(Project::class); }
    public function user() { return $this->belongsTo(User::class); }
}
