<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for what changes money or access: deletions, value-to-pay changes, unlocking saved forms, receipts
 * corrections, number changes, users and roles, two-factor. Rows are chained by hash: Audit::verify() finds a removed
 * or edited row.
 */
class Audit
{
    public static function log(string $action, ?Model $subject, string $summary, ?array $before = null, ?array $after = null): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }
        try {
            DB::transaction(function () use ($action, $subject, $summary, $before, $after) {
                $prev = AuditLog::lockForUpdate()->orderByDesc('id')->value('hash') ?? '';
                $row = new AuditLog([
                    'action' => $action, 'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(),
                    'subject_label' => $subject ? mb_substr(static::label($subject), 0, 190) : null, 'summary' => $summary,
                    'before' => $before, 'after' => $after, 'user_id' => auth()->id(), 'ip' => request()?->ip(),
                ]);
                $row->created_at = now()->startOfSecond();
                $row->hash = hash('sha256', $prev . $row->payload());
                $row->save();
            });
        } catch (\Throwable $e) {
            report($e);   // the trail never blocks the work itself
        }
    }

    protected static function label(Model $m): string
    {
        return (string) ($m->number ?? $m->full_code ?? $m->email ?? $m->name ?? $m->code ?? ('#' . $m->getKey()));
    }

    /** First broken row id, or null when the whole chain is intact. */
    public static function verify(): ?int
    {
        $prev = '';
        foreach (AuditLog::orderBy('id')->cursor() as $r) {
            if (!hash_equals((string) $r->hash, hash('sha256', $prev . $r->payload()))) {
                return $r->id;
            }
            $prev = $r->hash;
        }
        return null;
    }
}
