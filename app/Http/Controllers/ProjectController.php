<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\Currency;
use App\Models\Project;
use App\Services\LineBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Projects: overview of the running projects (with bulk edit) and one page per project with its budget lines. */
class ProjectController extends Controller
{
    /** Project fields editable in bulk (overview and Excel). */
    public const EDIT = [
        'start_date' => 'Start', 'end_date' => 'End', 'status' => 'Status', 'office' => 'Office', 'manager' => 'Manager',
        'budget_total' => 'Budget', 'icr_rate' => 'ICR %', 'spent_total' => 'Spent', 'icr_amount' => 'ICR amount',
        'balance' => 'Remaining', 'received' => 'Received', 'donor_balance' => 'Due from donor',
    ];

    public static function canSee($u): bool
    {
        return collect(['master.projects.manage', 'master.budget_lines.manage', 'reports.view'])->contains(fn ($p) => $u->hasPerm($p));
    }

    protected function see(Request $r): void
    {
        abort_unless(static::canSee($r->user()), 403);
    }

    protected function canEditLines(Request $r): bool
    {
        return $r->user()->hasPerm('master.budget_lines.manage');
    }

    // ================================================================= overview
    public function index(Request $r)
    {
        $this->see($r);
        Project::refreshStatuses();
        $ended = $r->input('show') === 'ended';
        $all = Project::with(['currency', 'partner', 'financeOfficer'])->when($ended, fn ($q) => $q->where('active', false), fn ($q) => $q->running())->orderBy('end_date')->orderBy('code')->get();
        $donor = $all->reject->isInternal();
        $lineStats = BudgetLine::where('active', true)->selectRaw('project_code, COUNT(*) n, SUM(spent_before IS NULL) unknown')->groupBy('project_code')->get()->keyBy('project_code');
        $list = $donor;
        if ($s = trim((string) $r->input('q'))) {
            $list = $list->filter(fn ($p) => str_contains(mb_strtolower($p->code . ' ' . $p->name . ' ' . $p->qb_name . ' ' . $p->manager), mb_strtolower($s)));
        }
        foreach (['office', 'manager', 'sector'] as $f) {
            if ($r->filled($f)) {
                $list = $list->where($f, $r->input($f));
            }
        }
        if ($r->filled('cur')) {
            $list = $list->filter(fn ($p) => $p->currency?->code === $r->input('cur'));
        }
        if ($r->input('soon')) {
            $list = $list->filter->endingSoon();
        }
        if ($r->input('missing')) {        // agreement amount or finance focal point not filled
            $list = $list->filter(fn ($p) => !$p->budget_total || !$p->finance_officer_id);
        }
        $figs = LineBudget::projectFigures($donor);
        $usd = fn ($p, $f) => ($v = in_array($f, ['remaining', 'spent', 'received', 'overhead']) ? ($figs[(string) $p->code][$f] ?? null) : $p->$f) !== null
            && ($rate = LineBudget::rateOf($p)) > 0 ? (float) $v * $rate : null;
        return view('projects.index', [
            'list' => $list->values(), 'donor' => $donor, 'figs' => $figs, 'sectors' => $donor->pluck('sector')->filter()->unique()->sort()->values(),
            'qbPending' => Project::running()->where(fn ($q) => $q->whereNull('qb_added_at')->orWhereIn('code', BudgetLine::where('active', true)->whereNull('qb_added_at')->pluck('project_code')))->get(), 'internal' => $all->filter->isInternal(), 'ended' => $ended, 'lineStats' => $lineStats, 'usd' => $usd,
            'soon' => $donor->filter->endingSoon()->values(), 'endedCount' => Project::where('active', false)->count(),
            'offices' => $donor->pluck('office')->filter()->unique()->sort()->values(), 'managers' => $donor->pluck('manager')->filter()->unique()->sort()->values(),
            'currencies' => $donor->map(fn ($p) => $p->currency?->code)->filter()->unique()->sort()->values(),
            'noRate' => $donor->filter(fn ($p) => LineBudget::rateOf($p) <= 0)->map(fn ($p) => $p->currency?->code ?? '?')->unique()->values(),
            'edit' => $r->boolean('edit') && $r->user()->hasPerm('master.projects.manage'), 'canEdit' => $r->user()->hasPerm('master.projects.manage'),
        ]);
    }

    protected function cleanRow(array $v): array
    {
        $out = [];
        foreach (static::EDIT as $f => $label) {
            if (!array_key_exists($f, $v)) {
                continue;
            }
            $x = is_string($v[$f]) ? trim($v[$f]) : $v[$f];
            if (in_array($f, ['start_date', 'end_date'])) {
                $out[$f] = $x ? \Illuminate\Support\Carbon::parse($x)->toDateString() : null;
            } elseif ($f === 'icr_rate') {
                $out[$f] = $x === null || $x === '' ? null : ((float) $x > 1 ? (float) $x / 100 : (float) $x);   // 5 or 0.05
            } elseif (in_array($f, ['status', 'office', 'manager'])) {
                $out[$f] = $x === '' ? null : $x;
            } else {
                $out[$f] = $x === null || $x === '' ? null : (float) str_replace(',', '', (string) $x);
            }
        }
        if (array_key_exists('status', $out) && !$out['status']) {
            unset($out['status']);
        }
        return $out;
    }

    protected function checkRow(array $v, string $who): array
    {
        $e = [];
        if (isset($v['status']) && !isset(Project::STATUSES[$v['status']])) {
            $e[] = "$who: unknown status \"{$v['status']}\" (" . implode(', ', Project::STATUSES) . ').';
        }
        if (!empty($v['office']) && !isset(Project::OFFICES[$v['office']])) {
            $e[] = "$who: the office is Turkey or Syria.";
        }
        if (!empty($v['start_date']) && !empty($v['end_date']) && $v['end_date'] < $v['start_date']) {
            $e[] = "$who: the end is before the start.";
        }
        return $e;
    }

    /** Bulk edit from the overview: rows[id][field]. All or nothing. */
    public function bulk(Request $r)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $rows = (array) $r->input('rows', []);
        $projects = Project::whereIn('id', array_keys($rows))->get()->keyBy('id');
        $clean = $errors = [];
        foreach ($rows as $id => $v) {
            if (!isset($projects[$id])) {
                continue;
            }
            try {
                $clean[$id] = $this->cleanRow((array) $v);
            } catch (\Throwable $e) {
                $errors[] = $projects[$id]->code . ': a date is not valid.';
                continue;
            }
            $errors = array_merge($errors, $this->checkRow($clean[$id], (string) $projects[$id]->code));
        }
        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }
        $n = $this->apply($projects, $clean);
        return redirect()->route('projects.index', array_filter($r->only(['q', 'office', 'manager', 'cur', 'soon', 'missing']) + ['edit' => 1]))->with('ok', "$n projects saved.");
    }

    protected function apply($projects, array $clean): int
    {
        $n = 0;
        DB::transaction(function () use ($projects, $clean, &$n) {
            foreach ($clean as $id => $v) {
                $p = $projects[$id];
                $p->fill($v);
                if ($p->isDirty()) {
                    $p->save();   // a status other than Ongoing switches the project off and archives its lines
                    $n++;
                }
            }
        });
        return $n;
    }

    public function exportList(Request $r)
    {
        $this->see($r);
        $path = storage_path('app/tmp/' . uniqid('projects_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Projects');
        $w->addRow(Row::fromValues(array_merge(['Code', 'Project name', 'Currency'], array_values(static::EDIT), ['Months left', 'Lines']), (new Style())->setFontBold()));
        $lines = BudgetLine::where('active', true)->selectRaw('project_code, COUNT(*) n')->groupBy('project_code')->pluck('n', 'project_code');
        foreach (Project::with('currency')->running()->orderBy('code')->get()->reject->isInternal() as $p) {
            $vals = [];
            foreach (array_keys(static::EDIT) as $f) {
                $v = $p->$f;
                $vals[] = $v instanceof \Carbon\CarbonInterface ? $v->format('Y-m-d') : ($f === 'icr_rate' && $v !== null ? round($v * 100, 2) : ($v === null ? '' : (is_numeric($v) ? (float) $v : $v)));
            }
            $w->addRow(Row::fromValues(array_merge([$p->code, $p->qb_name ?: $p->name, $p->currency?->code], $vals, [$p->monthsLeft() ?? '', (int) ($lines[(string) $p->code] ?? 0)])));
        }
        $w->close();
        return response()->download($path, 'Running_projects_' . now()->format('Y-m-d') . '.xlsx')->deleteFileAfterSend();
    }

    /** Upload the projects file back: updates every column that is in the file, by Code. All or nothing. */
    public function importList(Request $r)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $r->validate(['file' => 'required|file|mimes:xlsx|max:10240']);
        $rows = LineBudget::readSheet($r->file('file')->getRealPath(), ['code']);
        if ($rows === null) {
            return back()->withErrors(['file' => 'The file needs a "Code" column. Download it from this page first.']);
        }
        $byLabel = collect(static::EDIT)->mapWithKeys(fn ($l, $f) => [mb_strtolower($l) => $f]);
        $codes = collect($rows)->map(fn ($x) => (int) LineBudget::col($x, 'code'))->filter()->all();
        $projects = Project::whereIn('code', $codes)->get()->keyBy('code');
        $clean = $errors = $unknown = [];
        foreach ($rows as $x) {
            $code = (int) LineBudget::col($x, 'code');
            if (!$code) {
                continue;
            }
            if (!isset($projects[$code])) {
                $unknown[] = $code;
                continue;
            }
            $v = [];
            foreach ($x as $h => $val) {
                if (isset($byLabel[$h])) {
                    $v[$byLabel[$h]] = $val;
                }
            }
            try {
                $c = $this->cleanRow($v);
            } catch (\Throwable $e) {
                $errors[] = "$code: a date is not valid.";
                continue;
            }
            $errors = array_merge($errors, $this->checkRow($c, (string) $code));
            $clean[$projects[$code]->id] = $c;
        }
        if ($unknown) {
            $errors[] = 'Unknown project codes: ' . implode(', ', array_slice($unknown, 0, 10)) . '.';
        }
        if ($errors) {
            return back()->withErrors($errors);
        }
        $n = $this->apply($projects->keyBy('id'), $clean);
        return back()->with('ok', "$n projects updated from the file.");
    }

    // ================================================================= new / edit project
    public function create(Request $r)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $p = new Project(['code' => Project::nextCode(), 'status' => 'Ongoing', 'active' => true, 'start_date' => today()]);
        return view('projects.form', $this->formData($p) + ['extendId' => $r->integer('extend') ?: null]);
    }

    public function edit(Request $r, int $id)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        return view('projects.form', $this->formData(Project::with(['financeOfficer', 'mealOfficer', 'programOfficer', 'partner'])->findOrFail($id)));
    }

    protected function formData(Project $p): array
    {
        $partners = \App\Models\Partner::orderBy('name')->get();
        return ['p' => $p, 'partners' => $partners,
            'tags' => $partners->mapWithKeys(fn ($x) => [$x->id => Project::partnerTag($x)]),
            'sectors' => \App\Models\ListItem::where('category', 'program')->orderBy('sequence')->pluck('name'),
            'currencies' => Currency::where('active', true)->orderBy('sequence')->orderBy('code')->get(),
            'nextNo' => collect(range((int) date('Y') - 1, (int) date('Y') + 2))->mapWithKeys(fn ($y) => [$y => Project::nextYearNo($y)])];
    }

    protected function validated(Request $r, ?Project $p): array
    {
        // the imported running projects have no start date / agreement value yet: required for new projects only,
        // otherwise none of them could be saved
        $r->merge(['code' => $p ? $p->code : Project::nextCode()]);   // the number is given by the system, never typed
        $new = !$p;
        $numbered = $new || $p->year_no;                                // new numbering: Code-Year-No-Partner
        $need = $new ? 'required' : 'nullable';
        $v = $r->validate([
            'code' => ['required', 'integer', 'min:1', \Illuminate\Validation\Rule::unique('projects', 'code')->ignore($p?->id)],
            'name' => ($numbered ? 'nullable' : 'required') . '|string|max:200',
            'partner_id' => ($numbered ? 'required_without:new_partner' : 'nullable') . '|nullable|integer|exists:partners,id',
            'new_partner' => 'nullable|string|max:120', 'new_partner_short' => 'nullable|string|max:30',
            'sector' => ($new ? 'required' : 'nullable') . '|string|max:60', 'currency_id' => 'required|integer|exists:currencies,id',
            'start_date' => "$need|date", 'end_date' => array_merge([$need, 'date'], $r->filled('start_date') ? ['after_or_equal:start_date'] : []),
            'budget_total' => "$need|numeric|min:0", 'icr_rate' => 'nullable|numeric|min:0|max:100',
            'agreement_rate' => 'nullable|numeric|gt:0',
            'office' => 'nullable|in:Turkey,Syria', 'status' => 'nullable|in:' . implode(',', array_keys(Project::STATUSES)) . ',auto',
            'finance_officer_id' => 'nullable|integer|exists:individuals,id', 'meal_officer_id' => 'nullable|integer|exists:individuals,id',
            'program_officer_id' => 'nullable|integer|exists:individuals,id',
        ], ['partner_id.required_without' => 'Choose the partner: its short name goes into the project code.'],
            ['budget_total' => 'agreement amount', 'icr_rate' => 'overhead %', 'currency_id' => 'currency', 'agreement_rate' => 'agreement rate']);
        if (!empty($v['new_partner'])) {
            $partner = \App\Models\Partner::firstOrCreate(['name' => trim($v['new_partner'])],
                ['code' => (string) (((int) \App\Models\Partner::pluck('code')->filter(fn ($c) => ctype_digit((string) $c))->max()) + 1)]);
            if (!empty($v['new_partner_short']) && !$partner->short) {
                $partner->update(['short' => strtoupper(trim($v['new_partner_short']))]);
            }
            $v['partner_id'] = $partner->id;
        }
        unset($v['new_partner'], $v['new_partner_short']);
        $v['icr_rate'] = isset($v['icr_rate']) && $v['icr_rate'] !== '' ? (float) $v['icr_rate'] / 100 : null;
        if (Currency::find($v['currency_id'])?->code === 'USD') {
            $v['agreement_rate'] = 1;
        }
        if ($numbered) {
            // 298-2026-084-ALRAHMA: year of the start date, number in that year, partner short name
            $year = (int) \Illuminate\Support\Carbon::parse($v['start_date'] ?? $p?->start_date ?? today())->format('Y');
            $v['year'] = $year;
            $v['year_no'] = $p && $p->year === $year ? $p->year_no : Project::nextYearNo($year);
            $v['name'] = $year . '-' . str_pad((string) $v['year_no'], 3, '0', STR_PAD_LEFT) . '-' . Project::partnerTag(\App\Models\Partner::find($v['partner_id']));
        } else {
            $v['name'] = trim(preg_replace('/^' . preg_quote((string) $v['code'], '/') . '\s*-\s*/', '', $v['name']));   // "510-2026-…" → "2026-…"
        }
        $v['qb_name'] = $v['code'] . '-' . $v['name'];
        $v['manager'] = !empty($v['finance_officer_id']) ? \App\Models\Individual::find($v['finance_officer_id'])?->name : ($p?->manager);
        // status: automatic (dates + donor balance) unless Closed / Suspended / Cancelled is chosen by hand
        $st = $v['status'] ?? null;
        unset($v['status']);
        if ($st === 'auto' || in_array($st, Project::AUTO, true) || $new) {
            $v['status_mode'] = 'auto';
            $v['status'] = $p && $p->status_mode === 'auto' ? $p->status : 'Ongoing';
        } elseif ($st) {
            $v['status_mode'] = 'manual';
            $v['status'] = $st;
        }
        return $v;
    }

    public function store(Request $r)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $v = $this->validated($r, null);
        $p = DB::transaction(fn () => Project::create($v + ['qb_added_at' => null]));
        $p->status = $p->statusByDates();
        $p->save();
        return redirect()->route('projects.show', [$p->id, 'add' => 1])->with('ok', "Project $p->full_code created. Now add its budget lines.");
    }

    public function update(Request $r, int $id)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $p = Project::findOrFail($id);
        $v = $this->validated($r, $p);
        $before = $p->only(array_keys($v));
        $p->fill($v);
        if ($p->isDirty()) {
            \App\Support\Audit::log('update', $p, 'Project changed', array_intersect_key($before, $p->getDirty()), $p->getDirty());
        }
        if ($p->status_mode === 'auto') {
            $p->status = $p->statusByDates();
        }
        $p->save();
        return redirect()->route('projects.show', $p->id)->with('ok', 'Project saved.');
    }

    /** Extension of the agreement: new end date and / or more money; kept in the project history. */
    public function extend(Request $r, int $id)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $p = Project::findOrFail($id);
        $v = $r->validate(['date' => 'required|date', 'new_end_date' => 'nullable|date' . ($p->end_date ? '|after:' . $p->end_date->toDateString() : ''),
            'extra_amount' => 'nullable|numeric', 'reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000'],
            ['new_end_date.after' => 'The new end date must be after the current one (' . $p->end_date?->format('d M Y') . ').'],
            ['new_end_date' => 'new end date', 'extra_amount' => 'additional amount']);
        if (empty($v['new_end_date']) && empty((float) ($v['extra_amount'] ?? 0))) {
            return back()->withErrors(['new_end_date' => 'Give the new end date, an additional amount, or both.'])->withInput();
        }
        \App\Support\Audit::log('update', $p, 'Extension added', ['end' => $p->end_date?->toDateString(), 'amount' => (float) $p->budget_total], $v);
        DB::transaction(function () use ($p, $v, $r) {
            \App\Models\ProjectAmendment::create(['project_id' => $p->id, 'kind' => 'extension', 'date' => $v['date'],
                'old_end_date' => $p->end_date, 'new_end_date' => $v['new_end_date'] ?? null, 'old_amount' => $p->budget_total,
                'extra_amount' => (float) ($v['extra_amount'] ?? 0), 'reference' => $v['reference'] ?? null, 'notes' => $v['notes'] ?? null, 'user_id' => $r->user()->id]);
            if (!empty($v['new_end_date'])) {
                $p->end_date = $v['new_end_date'];
            }
            $p->budget_total = (float) $p->budget_total + (float) ($v['extra_amount'] ?? 0);
            // an extension opens an automatically closed project again
            if ($p->status_mode === 'auto' || $p->status === 'Closed') {
                $p->status_mode = 'auto';
                $p->status = $p->statusByDates();
            }
            $p->save();
        });
        return redirect()->route('projects.show', $p->id)->with('ok', 'Extension saved: ' . ($p->end_date ? 'ends ' . $p->end_date->format('d M Y') : '') . ', agreement ' . number_format((float) $p->budget_total, 2) . '.');
    }

    public function destroyAmendment(Request $r, int $id, int $amendment)
    {
        abort_unless($r->user()->hasPerm('master.projects.manage'), 403);
        $a = \App\Models\ProjectAmendment::where('project_id', $id)->findOrFail($amendment);
        $p = $a->project;
        abort_unless($p->amendments()->first()?->id === $a->id, 422, 'Only the last extension can be removed.');
        \App\Support\Audit::log('delete', $p, 'Extension removed', $a->toArray());
        DB::transaction(function () use ($a, $p) {
            if ($a->new_end_date) {
                $p->end_date = $a->old_end_date;
            }
            $p->budget_total = (float) $p->budget_total - (float) $a->extra_amount;
            if ($p->status_mode === 'auto') {
                $p->status = $p->statusByDates();
            }
            $p->save();
            $a->delete();
        });
        return back()->with('ok', 'Last extension removed: dates and amount are back.');
    }

    // ================================================================= one project
    public function show(Request $r, int $id)
    {
        $this->see($r);
        Project::refreshStatuses();
        $p = Project::with(['currency', 'partner'])->findOrFail($id);
        $archived = $r->input('show') === 'archived';
        $lines = LineBudget::figures(BudgetLine::with('expenseAccount')->where('project_code', (string) $p->code)->where('active', !$archived)
            ->orderBy('accounting_center_code')->orderBy('id')->get(), [$p]);
        $counts = BudgetLine::where('project_code', (string) $p->code)->selectRaw('active, COUNT(*) n')->groupBy('active')->pluck('n', 'active');
        $p->load(['financeOfficer', 'mealOfficer', 'programOfficer']);
        return view('projects.show', [
            'fig' => LineBudget::projectFigures([$p])[(string) $p->code],
            'receipts' => \App\Models\Receipt::with(['reservation', 'bankAccount'])->where('project_id', $p->id)->orderByDesc('date')->get(),
            'notInQb' => BudgetLine::where('project_code', (string) $p->code)->where('active', true)->whereNull('qb_added_at')->count(),
            'centers' => \App\Models\AccountingCenter::orderBy('code')->get(),
            'accounts' => \App\Models\Account::where('active', true)->whereIn('category', ['Expenditures', 'Other Expenditure'])->orderBy('code')->get(['id', 'code', 'name']),
            'isAdmin' => $r->user()->hasPerm('users.manage'),
            'p' => $p, 'lines' => $lines, 'groups' => $lines->groupBy(fn ($b) => $b->accounting_center_code . ' · ' . $b->accounting_center_name),
            'archived' => $archived, 'counts' => $counts, 'rate' => LineBudget::rateOf($p), 'cur' => strtoupper($p->currency?->code ?? 'USD'),
            'canEdit' => $this->canEditLines($r), 'canEditProject' => $r->user()->hasPerm('master.projects.manage'),
            'amendments' => $p->amendments()->with('user')->get(), 'rateSource' => LineBudget::rateSource($p),
        ]);
    }

    /** Many budget lines at once: rows[i] = {center, line, sub, description, account, amount}. The code is project-center-line(-(sub)). */
    public function addLines(Request $r, int $id)
    {
        abort_unless($this->canEditLines($r), 403);
        $p = Project::findOrFail($id);
        $centers = \App\Models\AccountingCenter::pluck('code')->map(fn ($c) => (string) $c)->all();
        $rows = collect((array) $r->input('rows', []))->map(fn ($x) => array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) $x))
            ->filter(fn ($x) => collect($x)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())->values();
        if ($rows->isEmpty()) {
            return back()->withErrors(['rows' => 'Nothing to add: fill at least one row.']);
        }
        $errors = $make = [];
        $seen = [];
        foreach ($rows as $i => $x) {
            $n = 'Row ' . ($i + 1);
            $line = preg_replace('/^' . preg_quote((string) $p->code, '/') . '-\d{3}-/', '', (string) ($x['line'] ?? ''));   // accepts a full code too
            $v = ['project_code' => (string) $p->code, 'accounting_center_code' => (string) ($x['center'] ?? ''), 'budget_line' => $line,
                'sub_line' => ($x['sub'] ?? '') !== '' ? (string) $x['sub'] : null];
            $code = BudgetLine::makeCode($v);
            if (!in_array($v['accounting_center_code'], $centers, true)) {
                $errors[] = "$n: choose the cost center.";
            }
            if ($line === '') {
                $errors[] = "$n: the line number is missing.";
            }
            if (($x['description'] ?? '') === '') {
                $errors[] = "$n: the description is missing.";
            }
            $amt = str_replace([',', ' '], '', (string) ($x['amount'] ?? ''));
            if ($amt === '' || !is_numeric($amt) || (float) $amt < 0) {
                $errors[] = "$n: the amount is not a number.";
            }
            if (isset($seen[$code])) {
                $errors[] = "$n: $code is twice in the list.";
            } elseif ($line !== '' && BudgetLine::where('code', $code)->exists()) {
                $errors[] = "$n: $code exists already.";
            }
            $seen[$code] = true;
            $acc = null;
            if (($x['account'] ?? '') !== '') {
                $acc = \App\Models\Account::where('id', $x['account'])->orWhere('code', explode(' ', (string) $x['account'])[0])->first();
                if (!$acc) {
                    $errors[] = "$n: unknown account {$x['account']}.";
                }
            }
            // a new line starts at zero: everything spent on it comes from the system (PTR, JAR, payroll, shared costs)
            $make[] = $v + ['description' => $x['description'] ?? '', 'expense_account_id' => $acc?->id, 'total_amount' => (float) $amt, 'active' => true, 'spent_before' => 0];
        }
        if ($errors) {
            return back()->withErrors(array_slice($errors, 0, 20))->withInput();
        }
        DB::transaction(function () use ($make) {
            foreach ($make as $v) {
                BudgetLine::create($v);
            }
        });
        return redirect()->route('projects.show', $p->id)->with('ok', count($make) . ' lines added. They are marked "not in QuickBooks yet".');
    }

    // ---------------------------------------------------------------- QuickBooks
    /** Uploader for QuickBooks: the project as a customer (when new) and its lines not in QuickBooks yet as items. */
    public function quickbooks(Request $r, int $id)
    {
        $this->see($r);
        Project::refreshStatuses();
        $p = Project::with(['currency', 'partner'])->findOrFail($id);
        $lines = BudgetLine::with(['expenseAccount', 'incomeAccount'])->where('project_code', (string) $p->code)->where('active', true)->whereNull('qb_added_at')->orderBy('id')->get();
        $path = storage_path('app/tmp/' . uniqid('qbproject_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $b = (new Style())->setFontBold();
        $w->getCurrentSheet()->setName('Customer');
        $w->addRow(Row::fromValues(['Customer', 'Company Name', 'Currency', 'Job Status', 'Job Start Date', 'Job Projected End Date', 'Job Type', 'Notes'], $b));
        if (!$p->inQuickBooks()) {
            $w->addRow(Row::fromValues([$p->qb_name ?: $p->code . '-' . $p->name, $p->partner?->name, $p->currency?->code, 'In progress',
                $p->start_date?->format('Y-m-d'), $p->end_date?->format('Y-m-d'), $p->sector, trim(($p->office ? $p->office . ' office' : '') . ($p->icr_rate ? ' · overhead ' . round($p->icr_rate * 100, 2) . ' %' : ''), ' ·')]));
        }
        $w->addNewSheetAndMakeItCurrent()->setName('Items');
        $w->addRow(Row::fromValues(['Item Name', 'Subitem Of', 'Item Type', 'Description', 'Expense Account', 'Expense Account No.', 'Income Account', 'Cost', 'Customer', 'Class'], $b));
        $parents = [];
        foreach ($lines as $l) {
            if ($l->parent_bl && !isset($parents[$l->parent_bl]) && !BudgetLine::where('code', $l->parent_bl)->whereNotNull('qb_added_at')->exists()) {
                $parents[$l->parent_bl] = true;   // Turkey salary lines: the parent item first
                $w->addRow(Row::fromValues([$l->parent_bl, '', 'Service', $l->description, $l->expenseAccount?->name, $l->expenseAccount?->code, $l->incomeAccount?->name, '', $p->qb_name, $l->accounting_center_name]));
            }
            $w->addRow(Row::fromValues([$l->code, $l->parent_bl ?: '', 'Service', $l->description, $l->expenseAccount?->name, $l->expenseAccount?->code, $l->incomeAccount?->name,
                (float) $l->total_amount, $p->qb_name, $l->accounting_center_name]));
        }
        $w->close();
        return response()->download($path, 'QuickBooks_project_' . $p->code . '.xlsx')->deleteFileAfterSend();
    }

    /** The system admin confirms that the project and its new lines are in QuickBooks. */
    public function confirmQuickbooks(Request $r, int $id)
    {
        abort_unless($r->user()->hasPerm('users.manage'), 403, 'Only the system admin confirms QuickBooks.');
        $p = Project::findOrFail($id);
        DB::transaction(function () use ($p, $r) {
            if (!$p->qb_added_at) {
                $p->forceFill(['qb_added_at' => now(), 'qb_added_by' => $r->user()->id])->saveQuietly();
            }
            BudgetLine::where('project_code', (string) $p->code)->whereNull('qb_added_at')->update(['qb_added_at' => now()]);
        });
        return back()->with('ok', 'Marked as added to QuickBooks.');
    }

    public function saveLines(Request $r, int $id)
    {
        abort_unless($this->canEditLines($r), 403);
        Project::findOrFail($id);
        [$n, $errors] = LineBudget::saveMany((array) $r->input('total', []), (array) $r->input('remaining', []));
        return $errors ? back()->withErrors($errors)->withInput() : back()->with('ok', "$n changes saved.");
    }

    public function export(Request $r, int $id)
    {
        $this->see($r);
        $p = Project::findOrFail($id);
        return BudgetLineController::download(BudgetLine::where('project_code', (string) $p->code)->orderBy('active', 'desc')->orderBy('accounting_center_code')->orderBy('id')->get(), 'Project_' . $p->code . '_lines.xlsx');
    }

    public function import(Request $r, int $id)
    {
        abort_unless($this->canEditLines($r), 403);
        $p = Project::findOrFail($id);
        return BudgetLineController::upload($r, (string) $p->code);
    }
}
