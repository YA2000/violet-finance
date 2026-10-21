<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSignature extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = ['is_preparer' => 'boolean', 'is_payer' => 'boolean', 'has_date' => 'boolean',
                        'required' => 'boolean', 'signed_at' => 'datetime', 'date' => 'date'];

    public function document() { return $this->belongsTo(Document::class); }
    public function individual() { return $this->belongsTo(Individual::class); }

    /** Odoo-like: the login of the employee of this step. */
    public function signerUser(): ?User
    {
        return $this->individual_id ? User::where('individual_id', $this->individual_id)->first() : null;
    }

    public function nameTitle(): string
    {
        if (!$this->individual) {
            return 'Name:  Title: ';
        }
        return 'Name: ' . $this->individual->name . ' Title: ' . ($this->individual->title ?? '');
    }

    public function block(bool $withDate = true, string $fmt = 'm/d/Y'): string
    {
        $i = $this->individual;
        $t = $i ? "Name: {$i->name} \nTitle: " . ($i->title ?? '') : "Name:  \nTitle: ";
        if ($withDate && $this->date) {
            $t .= "  \n" . $this->date->format($fmt);
        }
        return $t;
    }
}
