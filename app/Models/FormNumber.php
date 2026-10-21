<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormNumber extends Model
{
    protected $table = 'form_numbers';
    protected $guarded = ['id'];

    protected $casts = ['release_date' => 'date'];

    public function releaseText(): string
    {
        if ($this->release_label) {
            return $this->release_label;
        }
        return $this->release_date ? $this->release_date->format('d.m.Y') : '';
    }
}
