<?php

namespace App\Http\Controllers;

use App\Forms\FormRegistry;
use App\Models\Alert;
use App\Models\Document;
use App\Models\ReviewComment;
use App\Services\Workflow;
use Illuminate\Http\Request;

/** Internal reviewer: sees every document, writes notes, returns documents for correction. */
class ReviewController extends Controller
{
    public function index(Request $r)
    {
        $q = Document::query()->with(['user', 'currentSigner'])->withCount([
            'reviewComments as open_notes' => fn ($x) => $x->where('status', 'open'),
            'reviewComments as fixed_notes' => fn ($x) => $x->where('status', 'fixed'),
        ])->where('state', '!=', 'cancel');
        $view = $r->input('view', 'all');
        if ($view === 'open') {
            $q->whereHas('reviewComments', fn ($x) => $x->where('status', 'open'));
        } elseif ($view === 'fixed') {
            $q->whereHas('reviewComments', fn ($x) => $x->where('status', 'fixed'));
        } elseif ($view === 'unreviewed') {
            $q->whereDoesntHave('reviewComments')->whereIn('state', ['signing', 'to_pay', 'done']);
        }
        if ($r->filled('form')) {
            $q->where('form_code', $r->input('form'));
        }
        if ($r->filled('state')) {
            $q->where('state', $r->input('state'));
        }
        if ($r->filled('from')) {
            $q->whereDate('date', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('date', '<=', $r->input('to'));
        }
        if ($s = trim((string) $r->input('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%$s%")->orWhere('description', 'like', "%$s%"));
        }
        $docs = $q->orderByDesc('date')->orderByDesc('id')->paginate(30)->withQueryString();
        $counts = [
            'open' => ReviewComment::where('status', 'open')->distinct('document_id')->count('document_id'),
            'fixed' => ReviewComment::where('status', 'fixed')->distinct('document_id')->count('document_id'),
        ];
        return view('review.index', ['docs' => $docs, 'view' => $view, 'counts' => $counts, 'forms' => FormRegistry::all(true)]);
    }

    public function store(Request $r, Document $doc)
    {
        $u = $r->user();
        abort_unless($u->hasPerm('review.access'), 403);
        $v = $r->validate(['body' => 'required|string|max:2000', 'attachment_id' => 'nullable|integer', 'return' => 'nullable|boolean']);
        $att = $v['attachment_id'] ?? null;
        if ($att && !$doc->attachments()->whereKey($att)->exists()) {
            $att = null;
        }
        $return = $r->boolean('return') && in_array($doc->state, ['signing', 'to_pay', 'done'], true);
        $note = ReviewComment::create(['document_id' => $doc->id, 'user_id' => $u->id, 'attachment_id' => $att,
            'body' => $v['body'], 'status' => 'open', 'returned' => $return]);
        if ($return) {
            Workflow::returnToPreparer($doc, $u, 'Internal review: ' . $v['body']);
        } else {
            Workflow::log($doc, 'Review note by ' . $u->name . ': ' . $v['body'], 'review', $u);
            Alert::send($doc->user, ($doc->number ?: 'Draft') . ': review note from ' . $u->name, $v['body'],
                route('documents.show', [$doc, 'tab' => 'review']) . '#lower', 'fa-comment-dots', $u->id);
        }
        return redirect()->route('documents.show', [$doc, 'tab' => 'review'])->with('ok', $return ? 'Returned to the preparer with your note.' : 'Note added - the preparer is notified.');
    }

    public function fix(Request $r, ReviewComment $note)
    {
        $doc = $note->document;
        $u = $r->user();
        abort_unless($doc->isOwner($u) || $u->hasPerm('form.' . $doc->form_code . '.edit'), 403);
        $v = $r->validate(['reply' => 'nullable|string|max:2000']);
        $note->update(['status' => 'fixed', 'reply' => $v['reply'] ?? null, 'fixed_by' => $u->id, 'fixed_at' => now()]);
        Workflow::log($doc, 'Review note marked as fixed by ' . $u->name . ($v['reply'] ?? '' ? ': ' . $v['reply'] : '.'), 'review', $u);
        Alert::send($note->user_id, ($doc->number ?: 'Draft') . ': ' . $u->name . ' fixed your note', $v['reply'] ?? $note->body,
            route('documents.show', [$doc, 'tab' => 'review']) . '#lower', 'fa-circle-check', $u->id);
        return back()->with('ok', 'Marked as fixed - the reviewer is notified.');
    }

    public function close(Request $r, ReviewComment $note)
    {
        abort_unless($r->user()->hasPerm('review.access'), 403);
        $note->update(['status' => 'closed', 'closed_at' => now()]);
        return back()->with('ok', 'Note closed.');
    }

    public function reopen(Request $r, ReviewComment $note)
    {
        abort_unless($r->user()->hasPerm('review.access'), 403);
        $v = $r->validate(['body' => 'nullable|string|max:2000']);
        $note->update(['status' => 'open', 'closed_at' => null, 'fixed_at' => null,
            'body' => trim($note->body . (($v['body'] ?? '') ? "\n— " . $v['body'] : ''))]);
        $doc = $note->document;
        Alert::send($doc->user, ($doc->number ?: 'Draft') . ': review note reopened', $v['body'] ?? $note->body,
            route('documents.show', [$doc, 'tab' => 'review']) . '#lower', 'fa-comment-dots', $r->user()->id);
        return back()->with('ok', 'Note reopened - the preparer is notified.');
    }
}
