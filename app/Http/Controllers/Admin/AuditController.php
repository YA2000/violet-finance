<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Read-only view of the audit trail. */
class AuditController extends Controller
{
    public function index(Request $r)
    {
        $q = AuditLog::with('user')->orderByDesc('id');
        foreach (['action', 'subject_type'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->input($f));
            }
        }
        if ($s = trim((string) $r->input('q'))) {
            $q->where(fn ($w) => $w->where('subject_label', 'like', "%$s%")->orWhere('summary', 'like', "%$s%"));
        }
        return view('admin.audit.index', ['rows' => $q->paginate(50)->withQueryString(),
            'actions' => AuditLog::distinct()->orderBy('action')->pluck('action'), 'types' => AuditLog::distinct()->whereNotNull('subject_type')->orderBy('subject_type')->pluck('subject_type'),
            'broken' => $r->boolean('verify') ? (Audit::verify() ?? 0) : null]);
    }
}
