<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $guarded = ['id'];

    public const ALLOWED = ['application/pdf', 'image/png', 'image/jpeg'];

    public function document() { return $this->belongsTo(Document::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function expense() { return $this->belongsTo(AdvanceExpense::class); }
    public function advancePayment() { return $this->belongsTo(AdvancePayment::class); }
    public function receipt() { return $this->belongsTo(Receipt::class); }

    /** Store an uploaded file (private disk) and create the record. */
    public static function store(\Illuminate\Http\UploadedFile $f, array $owner, ?User $u, string $folder): self
    {
        $path = $f->store('attachments/' . $folder, 'local');
        return static::create($owner + ['name' => $f->getClientOriginalName(), 'path' => $path, 'mime' => $f->getMimeType(),
            'size' => $f->getSize(), 'user_id' => $u?->id]);
    }

    /** Copy this file into a document's attachments (so it is archived in the final file). */
    public function copyTo(Document $doc, ?string $kind = null): self
    {
        $new = 'attachments/' . $doc->id . '/' . uniqid() . '-' . basename($this->path);
        @mkdir(dirname(storage_path('app/private/' . $new)), 0775, true);
        @copy($this->fullPath(), storage_path('app/private/' . $new));
        return static::create(['document_id' => $doc->id, 'name' => $this->name, 'path' => $new, 'mime' => $this->mime,
            'size' => $this->size, 'user_id' => $this->user_id, 'kind' => $kind]);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function fullPath(): string
    {
        return storage_path('app/private/' . $this->path);
    }

    public function sizeLabel(): string
    {
        $s = $this->size;
        return $s > 1048576 ? round($s / 1048576, 1) . ' MB' : max(1, round($s / 1024)) . ' KB';
    }
}
