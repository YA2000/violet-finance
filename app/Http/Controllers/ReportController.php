<?php

namespace App\Http\Controllers;

use App\Forms\FormRegistry;
use App\Models\BudgetLine;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\Receipt;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Reports: every report is a table (+ a few figures) that can be exported to Excel. */
class ReportController extends Controller
{
    public const REPORTS = [
        'documents' => ['Documents by status', 'fa-folder-tree', 'Every form and where it stands: draft or final.'],
        'receipts' => ['Received payments', 'fa-hand-holding-dollar', 'Payments received, by status and project.'],
        'numbers' => ['Transaction numbers', 'fa-hashtag', 'Reserved numbers not used yet, by person and age.'],
    ];

    public function index()
    {
        $kpi = [
            'docs_month' => Document::whereMonth('date', now()->month)->whereYear('date', now()->year)->where('state', '!=', 'cancel')->count(),
            'final_month' => Document::where('state', 'done')->where('done_at', '>=', now()->startOfMonth())->count(),
            'running' => \App\Models\Project::running()->count(),
            'rec_pending' => Receipt::where('status', '!=', 'recorded')->count(),
            'free_numbers' => Reservation::where('state', 'reserved')->count(),
        ];
        return view('reports.index', ['reports' => static::REPORTS, 'kpi' => $kpi]);
    }

    /** Budget line report: every line with its total, spent and remaining, and every expense recorded on it. */
    public function lines(Request $r)
    {
        $projects = \App\Models\Project::orderBy('code')->get(['id', 'code', 'name', 'active', 'currency_id']);
        $spent = array_keys(\App\Services\LineBudget::documentSpending());
        $q = BudgetLine::query()->orderByRaw('CAST(project_code AS UNSIGNED)')->orderBy('code');
        if ($r->filled('project')) {
            $q->where('project_code', (string) $r->input('project'));
        } else {
            $q->where(fn ($w) => $w->where('active', true)->orWhereIn('id', $spent));
        }
        if ($s = trim((string) $r->input('q'))) {
            $q->where(fn ($w) => $w->where('code', 'like', "%$s%")->orWhere('description', 'like', "%$s%")->orWhere('project_name', 'like', "%$s%"));
        }
        $lines = \App\Services\LineBudget::figures($q->get());
        $from = $r->input('from');
        $to = $r->input('to');
        $inRange = fn ($d) => (!$from || $d['date'] >= $from) && (!$to || $d['date'] <= $to);
        $lines = $lines->each(fn ($b) => $b->shown = collect($b->doc_list)->filter($inRange)->sortBy('date')->values());
        $show = $r->input('show', 'spent');
        $lines = $lines->filter(fn ($b) => match ($show) {
            'all' => true,
            'over' => $b->remaining_cur !== null && $b->remaining_cur < 0,
            default => $b->shown->count() || (!$from && !$to && (float) $b->spent_cur != 0),
        })->values();
        if ($r->input('export')) {
            return $this->linesXlsx($lines);
        }
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage();
        $items = new \Illuminate\Pagination\LengthAwarePaginator($lines->forPage($page, 50)->values(), $lines->count(), 50, $page,
            ['path' => $r->url(), 'query' => $r->query()]);
        $forms = collect(FormRegistry::all(true))->mapWithKeys(fn ($f) => [$f->code => $f->short]);
        return view('reports.lines', ['items' => $items, 'projects' => $projects, 'forms' => $forms, 'show' => $show,
            'count' => $lines->count(), 'over' => $lines->filter(fn ($b) => $b->remaining_usd !== null && $b->remaining_usd < 0)->count(),
            'expenses' => $lines->sum(fn ($b) => $b->shown->count()), 'noRate' => $lines->where('no_rate', true)->count()]);
    }

    protected function linesXlsx($lines)
    {
        $path = storage_path('app/tmp/' . uniqid('rep_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $bold = (new Style())->setFontBold();
        $w->getCurrentSheet()->setName('Budget lines');
        $w->addRow(Row::fromValues(['Project', 'Budget line', 'Description', 'Accounting center', 'Currency', 'Budget', 'Rate (USD for 1)', 'Budget USD',
            'Spent before the system USD', 'Forms USD', 'Payroll & shared costs USD', 'Spent USD', 'Remaining USD', 'Used %'], $bold));
        foreach ($lines as $b) {
            $w->addRow(Row::fromValues([$b->project_name, $b->code, $b->description, $b->accounting_center_name, $b->cur, (float) $b->total_amount, $b->rate ?: null, $b->total_usd,
                $b->opening_usd, $b->docs_usd, $b->alloc_usd, $b->spent_usd, $b->remaining_usd, $b->used_usd !== null ? round($b->used_usd * 100, 1) : null]));
        }
        $w->addNewSheetAndMakeItCurrent()->setName('Expenses');
        $w->addRow(Row::fromValues(['Project', 'Budget line', 'Date', 'Number', 'Form', 'Note', 'Amount paid', 'Paid in', 'USD'], $bold));
        foreach ($lines as $b) {
            foreach ($b->shown as $d) {
                $w->addRow(Row::fromValues([$b->project_name, $b->code, $d['date'], $d['number'], $d['form'], $d['note'], $d['paid'], $d['paid_cur'], round($d['usd'], 2)]));
            }
        }
        $w->close();
        return response()->download($path, 'budget-lines-' . date('Y-m-d') . '.xlsx')->deleteFileAfterSend();
    }

    public function show(Request $r, string $report)
    {
        abort_unless(isset(static::REPORTS[$report]), 404);
        $data = $this->{'r_' . $report}($r);
        $data += ['key' => $report, 'title' => static::REPORTS[$report][0], 'icon' => static::REPORTS[$report][1], 'desc' => static::REPORTS[$report][2]];
        if ($r->input('export')) {
            return $this->xlsx($data);
        }
        return view('reports.show', $data);
    }

    protected function xlsx(array $d)
    {
        $path = storage_path('app/tmp/' . uniqid('rep_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName(mb_substr($d['title'], 0, 31));
        $w->addRow(Row::fromValues(array_column($d['columns'], 0), (new Style())->setFontBold()));
        foreach ($d['rows'] as $row) {
            $w->addRow(Row::fromValues(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_array($v) ? ($v['x'] ?? '') : $v), $row)));
        }
        $w->close();
        return response()->download($path, \Illuminate\Support\Str::slug($d['title']) . '-' . date('Y-m-d') . '.xlsx')->deleteFileAfterSend();
    }

    protected function dates(Request $r, $q, string $col = 'date'): void
    {
        if ($r->filled('from')) {
            $q->whereDate($col, '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate($col, '<=', $r->input('to'));
        }
    }

    // ------------------------------------------------------------------ reports
    protected function r_documents(Request $r): array
    {
        $q = Document::with(['user'])->where('state', '!=', 'cancel');
        $this->dates($r, $q);
        if ($r->filled('form')) {
            $q->where('form_code', $r->input('form'));
        }
        if ($r->filled('state')) {
            $q->where('state', $r->input('state'));
        }
        $docs = $q->orderBy('form_code')->orderByDesc('date')->limit(5000)->get();
        $forms = collect(FormRegistry::all(true))->keyBy('code');
        $matrix = [];
        foreach ($docs as $d) {
            $matrix[$d->form_code][$d->state] = ($matrix[$d->form_code][$d->state] ?? 0) + 1;
        }
        $rows = $docs->map(fn ($d) => [['x' => $d->number ?: 'draft', 'url' => route('documents.show', $d)], $forms[$d->form_code]?->short ?? $d->form_code, $d->date,
            $d->description, $d->amount, $d->currency, $d->stateLabel(), $d->user?->name, $d->uploaded ? 'Yes' : ''])->all();
        return ['filters' => ['dates', 'form', 'state'], 'forms' => $forms, 'matrix' => $matrix,
            'columns' => [['Number', 'ref'], ['Form', ''], ['Date', 'date'], ['Description', 'wide'], ['Amount', 'num'], ['Cur.', ''], ['Status', 'pill'], ['Prepared by', ''], ['Uploaded', '']],
            'rows' => $rows];
    }

    protected function r_receipts(Request $r): array
    {
        $q = Receipt::with(['bankAccount', 'project', 'reservation'])->orderByDesc('date');
        $this->dates($r, $q);
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }
        $items = $q->limit(5000)->get();
        $rows = $items->map(fn ($x) => [['x' => $x->number, 'url' => route('receipts.show', $x)], $x->date, $x->bankAccount?->code, $x->bank_ref, $x->payer,
            $x->amount, $x->currency, $x->project ? $x->project->code . ' - ' . $x->project->name : '', $x->project_amount, $x->project_currency,
            $x->reservation?->number, \App\Models\Receipt::STATES[$x->status]])->all();
        $byMonth = $items->groupBy(fn ($x) => $x->date->format('Y-m'))->map(fn ($g) => $g->sum('amount'))->sortKeys();
        return ['filters' => ['dates', 'rstatus'], 'byMonth' => $byMonth,
            'columns' => [['Number', 'ref'], ['Date', 'date'], ['Bank', ''], ['Notice', ''], ['From', ''], ['Amount', 'num'], ['Cur.', ''], ['Project', 'wide'], ['In project cur.', 'num'], ['Proj. cur.', ''], ['Transaction', ''], ['Status', 'pill']],
            'rows' => $rows];
    }

    protected function r_numbers(Request $r): array
    {
        $q = Reservation::with(['user', 'type'])->orderBy('date');
        if ($r->input('state', 'reserved') !== 'all') {
            $q->where('state', $r->input('state', 'reserved'));
        }
        $this->dates($r, $q);
        $items = $q->limit(5000)->get();
        $rows = $items->map(fn ($x) => [$x->number, $x->date, $x->type?->name, $x->description, $x->user?->name, \App\Models\Reservation::STATES[$x->state] ?? $x->state,
            $x->used_for, $x->state === 'reserved' ? (int) $x->created_at->diffInDays(now()) : null])->all();
        $byUser = $items->where('state', 'reserved')->groupBy(fn ($x) => $x->user?->name)->map->count()->sortDesc();
        return ['filters' => ['dates', 'nstate'], 'byUser' => $byUser,
            'columns' => [['Number', 'ref'], ['Date', 'date'], ['Type', ''], ['Description', 'wide'], ['Reserved by', ''], ['Status', ''], ['Used for', ''], ['Days free', 'days']],
            'rows' => $rows];
    }
}
