<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormRole extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_preparer' => 'boolean', 'is_payer' => 'boolean', 'has_date' => 'boolean',
                        'required' => 'boolean', 'active' => 'boolean'];

    public function slot() { return $this->belongsTo(FormSlot::class); }
    public function defaultIndividual() { return $this->belongsTo(Individual::class, 'default_individual_id'); }
}
