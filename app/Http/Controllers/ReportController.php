<?php

namespace App\Http\Controllers;

use App\Forms\FormRegistry;
use App\Models\Advance;
use App\Models\AdvanceHolder;
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
        'documents' => ['Documents by status', 'fa-folder-tree', 'Every form and where it stands: draft, in signature, waiting for payment, final.'],
        'signatures' => ['Waiting signatures', 'fa-hourglass-half', 'Who holds which document, and for how many days.'],
        'spending' => ['Spending by budget line', 'fa-chart-pie', 'Final PTR / JAR (and other forms set up for it) added up by project and budget line.'],
        'advances' => ['Advances by holder', 'fa-hand-holding-dollar', 'Granted, spent, cleared and still to clear, with overdue advances.'],
        'receipts' => ['Received payments', 'fa-hand-holding-dollar', 'Payments received, by status and project.'],
        'numbers' => ['Transaction numbers', 'fa-hashtag', 'Reserved numbers not used yet, by person and age.'],
    ];

    public function index()
    {
        $kpi = [
            'docs_month' => Document::whereMonth('date', now()->month)->whereYear('date', now()->year)->where('state', '!=', 'cancel')->count(),
            'waiting' => Document::whereIn('state', ['signing', 'to_pay'])->count(),
            'final_month' => Document::where('state', 'done')->where('done_at', '>=', now()->startOfMonth())->count(),
            'open_adv' => Advance::where('status', 'open')->count(),
            'rec_pending' => Receipt::where('status', '!=', 'recorded')->count(),
            'free_numbers' => Reservation::where('state', 'reserved')->count(),
        ];
        return view('reports.index', ['reports' => static::REPORTS, 'kpi' => $kpi]);
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
        $q = Document::with(['user', 'currentSigner'])->where('state', '!=', 'cancel');
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
            $d->description, $d->amount, $d->currency, $d->stateLabel(), $d->user?->name, $d->currentSigner?->name, $d->uploaded ? 'Yes' : ''])->all();
        return ['filters' => ['dates', 'form', 'state'], 'forms' => $forms, 'matrix' => $matrix,
            'columns' => [['Number', 'ref'], ['Form', ''], ['Date', 'date'], ['Description', 'wide'], ['Amount', 'num'], ['Cur.', ''], ['Status', 'pill'], ['Prepared by', ''], ['Waiting for', ''], ['Uploaded', '']],
            'rows' => $rows];
    }

    protected function r_signatures(Request $r): array
    {
        $docs = Document::with(['currentSigner', 'user'])->whereIn('state', ['signing', 'to_pay'])->get();
        $last = DocumentEvent::whereIn('document_id', $docs->pluck('id'))->whereIn('type', ['submit', 'sign', 'to_pay'])
            ->selectRaw('document_id, max(created_at) as t')->groupBy('document_id')->pluck('t', 'document_id');
        $forms = collect(FormRegistry::all(true))->keyBy('code');
        $rows = $docs->map(function ($d) use ($last, $forms) {
            $since = Carbon::parse($last[$d->id] ?? $d->submitted_at ?? $d->updated_at);
            return [['x' => $d->number, 'url' => route('documents.show', $d)], $forms[$d->form_code]?->short, $d->currentSigner?->name ?? '(no login)',
                $d->state === 'to_pay' ? 'Payment' : 'Signature', $since, (int) $since->diffInDays(now()), $d->amount, $d->currency, $d->user?->name];
        })->sortByDesc(fn ($x) => $x[5])->values()->all();
        $byPerson = collect($rows)->groupBy(fn ($x) => $x[2])->map->count()->sortDesc();
        return ['filters' => [], 'byPerson' => $byPerson,
            'columns' => [['Number', 'ref'], ['Form', ''], ['Waiting for', ''], ['Step', ''], ['Since', 'date'], ['Days', 'days'], ['Amount', 'num'], ['Cur.', ''], ['Prepared by', '']],
            'rows' => $rows];
    }

    protected function r_spending(Request $r): array
    {
        $q = Document::where('state', 'done');
        $this->dates($r, $q);
        $sum = [];
        $forms = [];
        foreach ($q->get() as $d) {
            $f = $forms[$d->form_code] ??= FormRegistry::has($d->form_code) ? FormRegistry::get($d->form_code) : null;
            $an = $f?->settings()['analytics'] ?? null;
            if (!$an || empty($an['table']) || empty($an['amount']) || empty($an['budget_line'])) {
                continue;
            }
            foreach ($d->data[$an['table']] ?? [] as $l) {
                $bl = $l[$an['budget_line']] ?? null;
                if (!$bl) {
                    continue;
                }
                $k = $bl . '|' . $d->currency;
                $sum[$k] ??= ['bl' => $bl, 'cur' => $d->currency, 'amount' => 0.0, 'docs' => []];
                $sum[$k]['amount'] += (float) ($l[$an['amount']] ?? 0);
                $sum[$k]['docs'][$d->id] = true;
            }
        }
        $bls = BudgetLine::whereIn('id', array_column($sum, 'bl'))->get()->keyBy('id');
        $rows = [];
        foreach ($sum as $s) {
            $b = $bls[$s['bl']] ?? null;
            if ($r->filled('project') && (string) $b?->project_code !== (string) $r->input('project')) {
                continue;
            }
            $rows[] = [$b?->project_code, $b?->project_name, $b?->code, $b?->description, $b?->accounting_center_name, count($s['docs']), $s['amount'], $s['cur'],
                       $b?->total_amount !== null ? (float) $b->total_amount : null];
        }
        usort($rows, fn ($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);
        $byProject = collect($rows)->groupBy(fn ($x) => $x[0] . ' ' . $x[1])->map(fn ($g) => $g->sum(6))->sortDesc()->take(12);
        return ['filters' => ['dates', 'project'], 'byProject' => $byProject,
            'columns' => [['Project', ''], ['Project name', ''], ['Budget line', 'ref'], ['Description', 'wide'], ['Accounting center', ''], ['Documents', 'num0'], ['Spent', 'num'], ['Cur.', ''], ['Budget', 'num']],
            'rows' => $rows];
    }

    protected function r_advances(Request $r): array
    {
        $holders = AdvanceHolder::with(['advances.expenses', 'advances.payments', 'account'])->orderBy('name')->get();
        $rows = [];
        foreach ($holders as $h) {
            $t = $h->totals();
            foreach (array_keys($t['granted']) as $cur) {
                $rows[] = [$h->label, $h->account ? $h->account->code . ' ' . $h->account->name : '', $cur, $h->advances->where('status', 'open')->where('currency', $cur)->count(),
                    $t['granted'][$cur], $t['spent'][$cur], $t['cleared'][$cur], $t['refunded'][$cur], $t['open'][$cur], $t['cash'][$cur], $t['overdue']];
            }
        }
        $overdue = Advance::with('holder')->where('status', 'open')->whereDate('due_date', '<', today())->orderBy('due_date')->get();
        return ['filters' => [], 'overdue' => $overdue,
            'columns' => [['Holder', ''], ['Advances account', 'wide'], ['Cur.', ''], ['Open', 'num0'], ['Granted', 'num'], ['Spent', 'num'], ['Cleared', 'num'], ['Refunded', 'num'], ['To clear', 'num'], ['Cash in hand', 'num'], ['Overdue', 'num0']],
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
