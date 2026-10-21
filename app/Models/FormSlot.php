<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormSlot extends Model
{
    protected $table = 'form_slots';
    protected $guarded = ['id'];
    public $timestamps = false;
}
