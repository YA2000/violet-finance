<?php

namespace App\Http\Controllers;

use App\Forms\FormRegistry;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\Reservation;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $u = auth()->user();
        $visible = fn () => Document::visibleTo($u);
        $first = Carbon::today()->startOfMonth();
        $states = $visible()->where('state', '!=', 'cancel')->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->all();
        $byForm = $visible()->selectRaw('form_code, state, count(*) as n')->groupBy('form_code', 'state')->get()
            ->groupBy('form_code')->map(fn ($g) => $g->pluck('n', 'state')->all())->all();
        $monthByForm = $visible()->where('date', '>=', $first)->where('state', '!=', 'cancel')
            ->selectRaw('form_code, count(*) as n')->groupBy('form_code')->pluck('n', 'form_code')->all();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $first->copy()->subMonths($i);
            $months[] = ['label' => $m->format('M'), 'year' => $m->format('Y'),
                'value' => $visible()->where('state', '!=', 'cancel')->whereBetween('date', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])->count()];
        }
        $waiting = Document::where('current_signer_id', $u->id)->whereIn('state', ['signing', 'to_pay'])
            ->with('user')->orderBy('submitted_at')->limit(12)->get();
        $docIds = $visible()->pluck('id');
        $recent = DocumentEvent::whereIn('document_id', $docIds)->with(['user', 'document'])->latest('id')->limit(9)->get();
        $free = Reservation::where('user_id', $u->id)->where('state', 'reserved')->with('type')->orderByDesc('number')->limit(8)->get();
        $kpi = [
            'sign' => $waiting->where('state', 'signing')->count(),
            'pay' => $waiting->where('state', 'to_pay')->count(),
            'drafts' => Document::where('user_id', $u->id)->where('state', 'draft')->count(),
            'month' => array_sum($monthByForm),
            'free' => Reservation::where('user_id', $u->id)->where('state', 'reserved')->count(),
            'final' => $states['done'] ?? 0,
        ];
        // things that wait for this user in the other modules
        $tasks = [];
        $notes = \App\Models\ReviewComment::where('status', 'open')->whereHas('document', fn ($q) => $q->where('user_id', $u->id))->count();
        if ($notes) {
            $tasks[] = ['fa-comment-dots', $notes . ' review ' . \Illuminate\Support\Str::plural('note', $notes) . ' to correct', route('documents.inbox'), 'warn'];
        }
        if ($u->hasPerm('review.access') && ($fx = \App\Models\ReviewComment::where('status', 'fixed')->where('user_id', $u->id)->count())) {
            $tasks[] = ['fa-circle-check', $fx . ' corrected ' . \Illuminate\Support\Str::plural('document', $fx) . ' to check', route('review.index', ['view' => 'fixed']), ''];
        }
        if ($u->hasPerm('receipts.assign') && ($n = \App\Models\Receipt::where('status', 'project')->count())) {
            $tasks[] = ['fa-diagram-project', $n . ' received ' . \Illuminate\Support\Str::plural('payment', $n) . ' without project', route('receipts.index', ['status' => 'project']), ''];
        }
        if ($u->hasPerm('receipts.record') && ($n = \App\Models\Receipt::where('status', 'record')->count())) {
            $tasks[] = ['fa-file-signature', $n . ' received ' . \Illuminate\Support\Str::plural('payment', $n) . ' to record', route('receipts.index', ['status' => 'record']), ''];
        }
        if ($u->hasPerm('advances.manage') && ($n = \App\Models\AdvanceSettlement::where('status', 'submitted')->count())) {
            $tasks[] = ['fa-inbox', $n . ' advance ' . \Illuminate\Support\Str::plural('settlement', $n) . ' to turn into JAR', route('advances.index'), ''];
        }
        if ($h = \App\Models\AdvanceHolder::where('user_id', $u->id)->first()) {
            $open = $h->advances()->where('status', 'open')->get();
            $due = $open->filter(fn ($a) => $a->isOverdue() || $a->isDueSoon())->count();
            $pay = \App\Models\AdvancePayment::whereIn('advance_id', $open->pluck('id'))->where('status', 'pending')->count();
            if ($due) {
                $tasks[] = ['fa-hourglass-end', $due . ' ' . \Illuminate\Support\Str::plural('advance', $due) . ' to clear soon', route('advances.mine'), 'warn'];
            }
            if ($pay) {
                $tasks[] = ['fa-receipt', $pay . ' ' . \Illuminate\Support\Str::plural('payment', $pay) . ' waiting for your proof', route('advances.mine'), ''];
            }
        }
        $forms = array_values(array_filter(FormRegistry::all(), fn ($f) => $u->hasPerm('form.' . $f->code . '.create') || $u->hasPerm('form.' . $f->code . '.view')));
        return view('dashboard', [
            'tasks' => $tasks, 'creatable' => array_values(array_filter($forms, fn ($f) => $u->hasPerm('form.' . $f->code . '.create'))),
            'kpi' => $kpi, 'states' => $states, 'months' => $months, 'waiting' => $waiting, 'recent' => $recent,
            'free' => $free, 'forms' => $forms, 'byForm' => $byForm, 'monthByForm' => $monthByForm,
        ]);
    }
}
