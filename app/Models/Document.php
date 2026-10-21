<?php

namespace App\Models;

use App\Forms\DynamicForm;
use App\Forms\FormRegistry;
use Illuminate\Database\Eloquent\Model;

/** One finance form (PTR, JAR, Journal ...). Header values and line tables are stored in `data` (JSON). */
class Document extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'data' => 'array', 'extra' => 'array', 'date' => 'date', 'paid_date' => 'date', 'uploaded' => 'boolean',
        'uploaded_at' => 'datetime', 'submitted_at' => 'datetime', 'done_at' => 'datetime',
        'amount' => 'float', 'paid_amount' => 'float', 'paid_rate' => 'float',
    ];

    public const STATES = [
        'draft' => 'Draft',
        'signing' => 'In Signature',
        'to_pay' => 'Complete to Accruals',
        'done' => 'Final',
        'cancel' => 'Cancelled',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function signatures() { return $this->hasMany(DocumentSignature::class)->orderBy('sequence')->orderBy('id'); }
    public function attachments() { return $this->hasMany(Attachment::class)->orderBy('id'); }
    public function events() { return $this->hasMany(DocumentEvent::class)->latest('id'); }
    public function reservation() { return $this->belongsTo(Reservation::class); }
    public function paidReservation() { return $this->belongsTo(Reservation::class, 'paid_reservation_id'); }
    public function currentSigner() { return $this->belongsTo(User::class, 'current_signer_id'); }

    public function form(): DynamicForm
    {
        return FormRegistry::get($this->form_code);
    }

    public function formType(): ?FormType
    {
        return FormType::byCode($this->form_code);
    }

    public function getTitleAttribute(): string
    {
        return $this->number ?: 'New ' . $this->form()->short;
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    public function v(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    // ---------------------------------------------------------------- signatures
    public function sig($index): ?DocumentSignature
    {
        $sigs = $this->signatures;
        if (is_string($index)) {
            return $sigs->firstWhere('slot_code', $index);
        }
        return $sigs->values()->get($index - 1);
    }

    public function sigText($index): string
    {
        $s = $this->sig($index);
        return $s ? $s->nameTitle() : 'Name:  Title: ';
    }

    public function sigBlock($index, bool $withDate = true): string
    {
        $s = $this->sig($index);
        return $s ? $s->block($withDate) : "Name:  \nTitle: ";
    }

    public function extraSigs()
    {
        return $this->signatures->filter(fn ($s) => $s->individual_id && !$s->slot_code)->values();
    }

    public function activeSignatures()
    {
        return $this->signatures->filter(fn ($s) => $s->individual_id)->values();
    }

    public function nextSignature(): ?DocumentSignature
    {
        return $this->activeSignatures()->first(fn ($s) => $s->state !== 'signed');
    }

    // ---------------------------------------------------------------- access
    public function isOwner(User $u): bool
    {
        return $this->user_id === $u->id;
    }

    public function canView(User $u): bool
    {
        $code = $this->form_code;
        if ($this->isOwner($u) || $u->hasPerm("form.$code.view") || $u->hasPerm("form.$code.edit") || $u->hasPerm('review.access')) {
            return true;
        }
        if ($this->state === 'draft') {
            return false;
        }
        return $u->individual_id && $this->signatures->contains(fn ($s) => $s->individual_id === $u->individual_id);
    }

    public function canEdit(User $u): bool
    {
        return $this->state === 'draft' && ($this->isOwner($u) || $u->hasPerm('form.' . $this->form_code . '.edit'));
    }

    public function canManage(User $u): bool
    {
        return $u->hasPerm('documents.manage');
    }

    public function canSign(User $u): bool
    {
        return in_array($this->state, ['signing', 'to_pay'], true) && $this->current_signer_id === $u->id;
    }

    public function isPayStep(User $u): bool
    {
        $n = $this->nextSignature();
        return $this->state === 'to_pay' && $n && $n->is_payer && ($this->current_signer_id === $u->id || $u->hasPerm('documents.manage'));
    }

    public function reviewComments() { return $this->hasMany(ReviewComment::class)->latest('id'); }

    /** Documents a user may open: own, signer, or forms he can see / edit (role permissions). */
    public static function visibleTo(User $u)
    {
        $q = static::query();
        if ($u->hasPerm('review.access')) {
            return $q;
        }
        $codes = [];
        foreach (\App\Forms\FormRegistry::all(true) as $f) {
            if ($u->hasPerm('form.' . $f->code . '.view') || $u->hasPerm('form.' . $f->code . '.edit')) {
                $codes[] = $f->code;
            }
        }
        return $q->where(function ($w) use ($u, $codes) {
            $w->where('user_id', $u->id);
            if ($codes) {
                $w->orWhereIn('form_code', $codes);
            }
            if ($u->individual_id) {
                $w->orWhere(function ($x) use ($u) {
                    $x->where('state', '!=', 'draft')->whereIn('id', DocumentSignature::select('document_id')
                        ->where('individual_id', $u->individual_id));
                });
            }
        });
    }
}
