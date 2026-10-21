<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormFieldConfig extends Model
{
    protected $table = 'form_field_configs';
    protected $guarded = ['id'];
    protected $casts = ['hidden' => 'boolean'];
}
