<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Setting;
use App\Models\User;

/** Who may open an attached file, and the upload rules shared by all modules. */
class Files
{
    public static function canView(Attachment $a, User $u): bool
    {
        if ($a->document_id) {
            return (bool) $a->document?->canView($u);
        }
        if ($a->expense_id) {
            $h = $a->expense?->holder;
            return ($h && $h->user_id === $u->id) || $u->hasPerm('advances.view') || $u->hasPerm('advances.manage') || $u->hasPerm('review.access');
        }
        if ($a->advance_payment_id) {
            $h = $a->advancePayment?->advance?->holder;
            return ($h && $h->user_id === $u->id) || $u->hasPerm('advances.view') || $u->hasPerm('advances.manage') || $u->hasPerm('review.access');
        }
        if ($a->receipt_id) {
            return $u->hasPerm('receipts.view') || $u->hasPerm('receipts.inform') || $u->hasPerm('receipts.assign') || $u->hasPerm('receipts.record');
        }
        return $u->isAdmin();
    }

    /** Validation rules for an upload field (PDF / PNG / JPG, size from Settings). */
    public static function rules(bool $required = false): array
    {
        $max = (int) (((float) Setting::get('max_attachment_mb')) * 1024);
        return [($required ? 'required' : 'nullable') . '|array|max:20', "file|mimetypes:application/pdf,image/png,image/jpeg|max:$max"];
    }

    public static function messages(string $field = 'files'): array
    {
        return ["$field.*.mimetypes" => 'Only PDF, PNG or JPG files can be attached.',
                "$field.*.max" => 'Each file must be at most ' . Setting::get('max_attachment_mb') . ' MB.'];
    }
}
