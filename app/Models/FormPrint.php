<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Settings of one printed page (an Excel print sheet). */
class FormPrint extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean', 'show_header' => 'boolean', 'show_logo' => 'boolean', 'in_final' => 'boolean',
                        'labels' => 'array', 'hidden_keys' => 'array'];

    public function isHidden(string $key): bool
    {
        return in_array($key, $this->hidden_keys ?? [], true);
    }

    public function label(string $key, ?string $default): string
    {
        $l = $this->labels[$key] ?? null;
        return ($l === null || $l === '') ? (string) $default : $l;
    }

    public const PLACEHOLDERS = '{form_no} {release_no} {release_date} {org} {org_tr}  — footer also [page] [pages]';

    public function formType() { return $this->belongsTo(FormType::class); }
    public function formNumber() { return $this->belongsTo(FormNumber::class); }
    public function labels() { return $this->hasMany(FormLabel::class)->orderBy('sequence'); }

    public static function forSheet(string $sheet): self
    {
        return static::where('sheet', $sheet)->first() ?? new static(['sheet' => $sheet, 'active' => true,
            'show_header' => false, 'show_logo' => true]);
    }

    public function vars(): array
    {
        $n = $this->formNumber;
        return [
            'form_no' => $n?->number ?? '',
            'release_no' => $n?->release_no ?? '',
            'release_date' => $n ? $n->releaseText() : '',
            'org' => Setting::get('org_name'),
            'org_tr' => Setting::get('org_name_tr'),
        ];
    }

    public function subst(?string $text): string
    {
        $v = $this->vars();
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => $v[$m[1]] ?? $m[0], (string) $text);
    }

    public function headerLeftText(): string { return $this->subst($this->header_left); }
    public function headerCenterText(): string { return $this->subst($this->header_center); }

    public function footerHtml(string $part): string
    {
        $html = nl2br(e($this->subst($this->{'footer_' . $part})), false);
        return str_replace(['[pages]', '[page]'], ['<span class="topage"></span>', '<span class="page"></span>'], $html);
    }

    public function logoSrc(): string
    {
        if ($this->logo) {
            return $this->logo;
        }
        return Setting::get('org_logo') ?: \App\Support\Print\Assets::logoDataUri();
    }

    /** [custom texts by cell, hidden cells] */
    public function texts(): array
    {
        $L = [];
        $H = [];
        if (!$this->exists) {
            return [$L, $H];
        }
        foreach ($this->labels as $lab) {
            if ($lab->hidden) {
                $H[] = $lab->cell;
                $L[$lab->cell] = '';
            } elseif ($lab->text !== null && $lab->text !== '' && $lab->text !== $lab->default_text) {
                $L[$lab->cell] = $lab->text;
            }
        }
        return [$L, $H];
    }
}
