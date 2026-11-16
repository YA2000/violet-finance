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
        $docIds = $visible()->pluck('id');
        $recent = DocumentEvent::whereIn('document_id', $docIds)->with(['user', 'document'])->latest('id')->limit(9)->get();
        $free = Reservation::where('user_id', $u->id)->where('state', 'reserved')->with('type')->orderByDesc('number')->limit(8)->get();
        $kpi = [
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
        try {
            $projects = ProjectController::canSee($u) ? $this->projects() : null;
        } catch (\Throwable $e) {
            report($e);
            $projects = null;
        }
        $forms = array_values(array_filter(FormRegistry::all(), fn ($f) => $u->hasPerm('form.' . $f->code . '.create') || $u->hasPerm('form.' . $f->code . '.view')));
        return view('dashboard', [
            'tasks' => $tasks, 'creatable' => array_values(array_filter($forms, fn ($f) => $u->hasPerm('form.' . $f->code . '.create'))),
            'kpi' => $kpi, 'states' => $states, 'months' => $months, 'recent' => $recent,
            'free' => $free, 'forms' => $forms, 'byForm' => $byForm, 'monthByForm' => $monthByForm, 'projects' => $projects,
        ]);
    }

    /** Project follow-up: running, ending within 30 days, agreements of the year by currency, projects per sector, attention. */
    protected function projects(): array
    {
        \App\Models\Project::refreshStatuses();
        $all = \App\Models\Project::with(['currency', 'partner'])->get()->reject->isInternal();
        $open = $all->filter->isRunning();
        $year = (int) date('Y');
        $thisYear = $all->filter(fn ($p) => ($p->year ?? $p->start_date?->year) === $year && !in_array($p->status, ['Cancelled'], true));
        $ended = $all->where('status', 'Ended');
        $figs = $ended->isEmpty() ? [] : \App\Services\LineBudget::projectFigures($ended);
        // ended but not closed: money left with us or due from the donor (balance unknown: only the recently ended ones)
        $attention = $ended->map(function ($p) use ($figs) {
            $f = $figs[(string) $p->code] ?? null;
            $bal = $f && $f['received'] !== null && $f['spent'] !== null ? (float) $f['received'] - (float) $f['spent'] - (float) $f['overhead'] : null;
            if ($bal === null) {
                return $p->end_date && $p->end_date->gte(today()->subDays(90)) ? ['p' => $p, 'why' => 'Ended - donor balance unknown'] : null;
            }
            return ['p' => $p, 'why' => $bal > 0 ? 'Ended - ' . money($bal) . ' ' . $f['cur'] . ' not spent' : 'Ended - ' . money(-$bal) . ' ' . $f['cur'] . ' due from the donor'];
        })->filter()->merge($open->filter(fn ($p) => !$p->finance_officer_id || !$p->budget_total)->map(fn ($p) => ['p' => $p, 'why' => !$p->budget_total ? 'No agreement amount' : 'No finance focal point']))
            ->sortBy(fn ($x) => $x['p']->end_date)->values();
        return [
            'running' => $open->where('status', 'Ongoing')->count(),
            'pipeline' => $open->where('status', 'Pipeline')->count(),
            'soon' => $open->filter->endingSoon()->sortBy('end_date')->values(),
            'year' => $year,
            'agreements' => $thisYear->groupBy(fn ($p) => $p->currency?->code ?? '?')->map(fn ($g) => ['n' => $g->count(), 'sum' => $g->sum(fn ($p) => (float) $p->budget_total)])->sortByDesc('sum'),
            'sectors' => $open->groupBy(fn ($p) => $p->sector ?: 'No sector')->map->count()->sortDesc(),
            'attention' => $attention->take(8), 'attentionCount' => $attention->count(),
        ];
    }
}
