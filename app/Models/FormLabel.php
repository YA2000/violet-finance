<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormLabel extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['hidden' => 'boolean'];

    public function print() { return $this->belongsTo(FormPrint::class, 'form_print_id'); }

    public function isChanged(): bool
    {
        return $this->hidden || ($this->text !== null && $this->text !== '' && $this->text !== $this->default_text);
    }
}
