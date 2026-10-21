<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    protected $table = 'form_fields';
    protected $guarded = ['id'];

    protected $casts = ['required' => 'boolean', 'printed' => 'boolean', 'active' => 'boolean'];

    public function choices(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->options))));
    }
}
