<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\TxnType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

class ReservationController extends Controller
{
    /** Filtered list (page and Excel export use the same filters). */
    protected function query(Request $r)
    {
        $q = Reservation::with(['type', 'user', 'project', 'costCenter', 'document']);
        if ($s = trim((string) $r->query('q'))) {
            $q->where(function ($w) use ($s) {
                $w->where('number', 'like', "%$s%")->orWhere('description', 'like', "%$s%")->orWhere('pr_number', 'like', "%$s%")
                  ->orWhereHas('type', fn ($t) => $t->where('name', 'like', "%$s%"))
                  ->orWhereHas('user', fn ($t) => $t->where('name', 'like', "%$s%"))
                  ->orWhereHas('project', fn ($t) => $t->where('name', 'like', "%$s%")->orWhere('code', $s));
            });
        }
        if ($r->query('mine')) {
            $q->where('user_id', auth()->id());
        }
        if ($st = $r->query('state')) {
            $q->where('state', $st);
        }
        if ($t = $r->query('type')) {
            $q->where('txn_type_id', $t);
        }
        if ($p = $r->query('project')) {
            $q->where('project_id', $p);
        }
        if ($by = $r->query('by')) {
            $q->where('user_id', $by);
        }
        if ($f = $r->query('from')) {
            $q->where('date', '>=', $f);
        }
        if ($t2 = $r->query('to')) {
            $q->where('date', '<=', $t2);
        }
        return $q->orderByDesc('number');
    }

    public function index(Request $r)
    {
        $counts = Reservation::selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->all();
        $u = $r->user();
        return view('reservations.index', [
            'items' => $this->query($r)->paginate(50)->withQueryString(),
            'types' => TxnType::where('active', true)->orderBy('sequence')->get(),
            'allTypes' => TxnType::orderBy('sequence')->get(),
            'rules' => TxnType::rulesMap(),
            'counts' => $counts,
            'mineFree' => Reservation::where('user_id', $u->id)->where('state', 'reserved')->count(),
            'people' => \App\Models\User::whereIn('id', Reservation::select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'projectLabel' => $r->query('project') ? \App\Services\Lookup::label('projects', $r->query('project')) : '',
            'manage' => $u->hasPerm('numbers.manage'),
        ]);
    }

    public function store(Request $r)
    {
        $v = Reservation::check($r->all());
        $res = Reservation::reserve($v, $r->user());
        if ($r->expectsJson()) {
            return response()->json(['id' => $res->id, 'number' => $res->number, 'text' => $res->number . ' - ' . $res->description]);
        }
        return back()->with('ok', 'Number ' . $res->number . ' reserved.')->with('reserved', $res->number);
    }

    public function edit(Request $r, Reservation $reservation)
    {
        $u = $r->user();
        abort_unless($u->hasPerm('numbers.manage') || ($reservation->user_id === $u->id && $reservation->state === 'reserved'), 403);
        return view('reservations._edit', ['item' => $reservation->load('user', 'type'), 'types' => TxnType::where('active', true)->orderBy('sequence')->get()]);
    }

    /** The person who reserved it changes a free number; full access changes every detail and the status of any number. */
    public function update(Request $r, Reservation $reservation)
    {
        $u = $r->user();
        $manage = $u->hasPerm('numbers.manage');
        abort_unless($manage || ($reservation->user_id === $u->id && $reservation->state === 'reserved'), 403, 'Only free numbers you reserved can be changed.');
        $v = Reservation::check($r->all());
        if (Carbon::parse($v['date'])->format('y') !== substr($reservation->number, 1, 2)) {
            return back()->withErrors(['date' => 'The date must stay in year 20' . substr($reservation->number, 1, 2) . ' (the number contains the year).']);
        }
        if ($manage && ($st = $r->input('state')) && array_key_exists($st, Reservation::STATES)) {
            $v['state'] = $st;
        }
        $reservation->update($v);
        return back()->with('ok', $reservation->number . ' updated.');
    }

    public function cancel(Request $r, Reservation $reservation)
    {
        $u = $r->user();
        abort_unless($u->hasPerm('numbers.manage') || ($reservation->user_id === $u->id && $reservation->state === 'reserved'), 403, 'Only free numbers you reserved can be cancelled.');
        $reservation->update(['state' => 'cancel']);
        return back()->with('ok', $reservation->number . ' cancelled.');
    }

    /** Full access only. The number is never given again; a form that used it keeps its number. */
    public function destroy(Request $r, Reservation $reservation)
    {
        abort_unless($r->user()->hasPerm('numbers.manage'), 403);
        DB::transaction(function () use ($reservation) {
            \App\Models\Document::where('reservation_id', $reservation->id)->update(['reservation_id' => null]);
            \App\Models\Document::where('paid_reservation_id', $reservation->id)->update(['paid_reservation_id' => null]);
            $reservation->delete();
        });
        return back()->with('ok', $reservation->number . ' deleted. It will not be given again.');
    }

    /** Every number of the current filters, in one Excel file. */
    public function export(Request $r)
    {
        $path = storage_path('app/tmp/' . uniqid('numbers_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Transaction numbers');
        $bold = (new Style())->setFontBold()->setBackgroundColor('E6D8F4');
        $w->addRow(Row::fromValues(['Number', 'Record Date', 'Transaction Date', 'Transaction Type', 'Description', 'Project Code', 'Project',
            'Cost Center', 'PR Number', 'Reserved By', 'Reserved At', 'Status', 'Used For'], $bold));
        $this->query($r)->chunk(1000, function ($rows) use ($w) {
            foreach ($rows as $x) {
                $w->addRow(Row::fromValues([$x->number, $x->created_at?->format('Y-m-d'), $x->date?->format('Y-m-d'), $x->type?->name, $x->description,
                    $x->project?->code, $x->project?->name, $x->costCenter?->code, $x->pr_number, $x->user?->name,
                    $x->created_at?->format('Y-m-d H:i'), Reservation::STATES[$x->state] ?? $x->state, $x->used_for]));
            }
        });
        $w->close();
        return response()->download($path, 'Transaction_Numbers_' . date('Y-m-d') . '.xlsx')->deleteFileAfterSend();
    }

    // ---------------------------------------------------------------- transaction types
    public function types()
    {
        abort_unless(auth()->user()->hasPerm('numbers.manage'), 403);
        $used = Reservation::selectRaw('txn_type_id, count(*) as n')->groupBy('txn_type_id')->pluck('n', 'txn_type_id')->all();
        return view('reservations.types', ['types' => TxnType::orderBy('sequence')->orderBy('id')->get(), 'used' => $used,
            'forms' => \App\Models\FormType::orderBy('sequence')->get(['code', 'short', 'name'])]);
    }

    public function saveTypes(Request $r)
    {
        abort_unless($r->user()->hasPerm('numbers.manage'), 403);
        $rows = (array) $r->input('types', []);
        $names = [];
        foreach ($rows as $k => $row) {
            if (!empty($row['delete'])) {
                continue;
            }
            $n = mb_strtolower(trim((string) ($row['name'] ?? '')));
            if ($n === '') {
                if (str_starts_with((string) $k, 'new')) {
                    continue;
                }
                return back()->withErrors(['types' => 'Every type needs a name.'])->withInput();
            }
            if (isset($names[$n])) {
                return back()->withErrors(['types' => 'The type "' . trim($row['name']) . '" is written twice.'])->withInput();
            }
            $names[$n] = true;
        }
        $skipped = [];
        DB::transaction(function () use ($rows, &$skipped) {
            foreach ($rows as $k => $row) {
                $t = str_starts_with((string) $k, 'new') ? null : TxnType::find((int) $k);
                if (!empty($row['delete'])) {
                    if ($t && Reservation::where('txn_type_id', $t->id)->exists()) {
                        $t->update(['active' => false]);
                        $skipped[] = $t->name;
                    } elseif ($t) {
                        $t->delete();
                    }
                    continue;
                }
                if (trim((string) ($row['name'] ?? '')) === '') {
                    continue;
                }
                $vals = ['name' => trim($row['name']), 'sequence' => (int) ($row['sequence'] ?? 10), 'form_code' => ($row['form_code'] ?? '') ?: null,
                    'active' => !empty($row['active']), 'need_project' => !empty($row['need_project']), 'need_cost_center' => !empty($row['need_cost_center']),
                    'pr_field' => array_key_exists($row['pr_field'] ?? '', TxnType::PR) ? $row['pr_field'] : 'off'];
                $t ? $t->update($vals) : TxnType::create($vals);
            }
        });
        return redirect()->route('reservations.types')->with('ok', 'Transaction types saved.' . ($skipped ? ' In use, switched off instead of deleted: ' . implode(', ', $skipped) . '.' : ''));
    }

    /** My free numbers (form pickers). */
    public function mine(Request $r)
    {
        return Reservation::where('user_id', $r->user()->id)->where('state', 'reserved')->orderBy('number')
            ->get(['id', 'number', 'description', 'date'])->map(fn ($x) => ['id' => $x->id, 'number' => $x->number,
                'text' => $x->number . ' - ' . $x->description]);
    }

    public function template()
    {
        $path = storage_path('app/tmp/Reservation_Template.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $bold = (new Style())->setFontBold()->setBackgroundColor('E6D8F4');
        $w->addRow(Row::fromValues(['Transaction Date (YYYY-MM-DD)', 'Transaction Type', 'Project Code', 'Description', 'Cost Center Code', 'PR Number'], $bold));
        $w->addRow(Row::fromValues([date('Y-m-d'), TxnType::where('active', true)->orderBy('sequence')->value('name'), '1', 'Example - delete this row', '101-USD', '']));
        $types = $w->addNewSheetAndMakeItCurrent();
        $types->setName('Transaction Types');
        $w->addRow(Row::fromValues(['Transaction Type (copy the name exactly)', 'Project', 'Cost Center', 'PR Number'], $bold));
        foreach (TxnType::where('active', true)->orderBy('sequence')->get() as $t) {
            $w->addRow(Row::fromValues([$t->name, $t->need_project ? 'Required' : 'Optional', $t->need_cost_center ? 'Required' : 'Optional', TxnType::PR[$t->pr_field] ?? 'Not asked']));
        }
        $w->close();
        return response()->download($path, 'Reservation_Template.xlsx')->deleteFileAfterSend();
    }

    public function oldTemplate()
    {
        $path = storage_path('app/tmp/Old_Numbers_Template.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $bold = (new Style())->setFontBold()->setBackgroundColor('E6D8F4');
        $w->addRow(Row::fromValues(['Number', 'Date (YYYY-MM-DD)', 'Time (HH:MM)', 'Description', 'Transaction Type', 'Project Code'], $bold));
        $w->addRow(Row::fromValues(['T2500001', '2025-01-15', '10:30', 'Example - delete this row', TxnType::orderBy('sequence')->value('name'), '']));
        $w->close();
        return response()->download($path, 'Old_Numbers_Template.xlsx')->deleteFileAfterSend();
    }

    /** Numbers given before the system, with their descriptions: kept as they are, marked used; new numbers continue after them. All or nothing. */
    public function importOld(Request $r)
    {
        abort_unless($r->user()->hasPerm('numbers.manage'), 403);
        $r->validate(['file' => 'required|file|mimes:xlsx|max:20480']);
        $reader = new Reader();
        $reader->open($r->file('file')->getRealPath());
        $types = TxnType::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower(trim($n)) => $id])->all();
        $firstType = TxnType::orderBy('sequence')->value('id');
        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $i = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $i++;
                if ($i === 1) {
                    continue;
                }
                $c = $row->toArray();
                if (!array_filter($c, fn ($x) => $x !== null && $x !== '')) {
                    continue;
                }
                [$num, $date, $time, $desc, $type, $proj] = array_pad($c, 6, null);
                $num = strtoupper(trim((string) $num));
                if (str_starts_with((string) $desc, 'Example - delete')) {
                    continue;
                }
                if ($num === '' || mb_strlen($num) > 20) {
                    $errors[] = "Row $i: the number is empty or too long.";
                    continue;
                }
                if (isset($seen[$num])) {
                    $errors[] = "Row $i: $num is repeated in the file (row {$seen[$num]}).";
                    continue;
                }
                $seen[$num] = $i;
                try {
                    $at = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse((string) $date);
                    if ($time instanceof \DateTimeInterface) {
                        $at->setTime((int) $time->format('H'), (int) $time->format('i'));
                    } elseif (trim((string) $time) !== '') {
                        [$h, $m] = array_pad(explode(':', trim((string) $time)), 2, 0);
                        $at->setTime((int) $h, (int) $m);
                    }
                } catch (\Throwable) {
                    $errors[] = "Row $i: the date or time of $num is not valid.";
                    continue;
                }
                $typeName = mb_strtolower(trim((string) $type));
                if ($typeName !== '' && !isset($types[$typeName])) {
                    $errors[] = "Row $i: unknown transaction type '$type'.";
                    continue;
                }
                $pid = $proj !== null && $proj !== '' ? Project::where('code', (int) $proj)->value('id') : null;
                if ($proj && !$pid) {
                    $errors[] = "Row $i: project $proj does not exist.";
                    continue;
                }
                $rows[] = ['number' => $num, 'date' => $at->toDateString(), 'txn_type_id' => $types[$typeName] ?? $firstType, 'project_id' => $pid,
                    'description' => mb_substr(trim((string) $desc), 0, 250), 'user_id' => $r->user()->id, 'state' => 'used', 'used_for' => 'Imported',
                    'created_at' => $at->toDateTimeString(), 'updated_at' => now()];
            }
            break;
        }
        $reader->close();
        $nums = array_column($rows, 'number');
        foreach (array_chunk($nums, 500) as $chunk) {
            foreach (Reservation::whereIn('number', $chunk)->pluck('number')->merge(\App\Models\Document::whereIn('number', $chunk)->pluck('number'))->unique() as $n) {
                $errors[] = "$n already exists in the system.";
            }
        }
        if ($errors) {
            return back()->withErrors(array_slice($errors, 0, 30));
        }
        if (!$rows) {
            return back()->withErrors(['file' => 'The file has no numbers.']);
        }
        DB::transaction(function () use ($rows, $nums) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Reservation::insert($chunk);
            }
            // new numbers of those years continue after the imported ones
            $max = [];
            foreach ($nums as $n) {
                if (preg_match('/^T(\d{2})(\d{5,})$/', $n, $m)) {
                    $max['20' . $m[1]] = max($max['20' . $m[1]] ?? 0, (int) $m[2]);
                }
            }
            foreach ($max as $year => $serial) {
                $c = \App\Models\TxnCounter::firstOrCreate(['year' => (int) $year], ['next_number' => $serial + 1]);
                if ($c->next_number <= $serial) {
                    $c->update(['next_number' => $serial + 1]);
                }
            }
        });
        return back()->with('ok', count($rows) . ' old numbers imported.');
    }

    public function import(Request $r)
    {
        $r->validate(['file' => 'required|file|mimes:xlsx|max:5120']);
        $reader = new Reader();
        $reader->open($r->file('file')->getRealPath());
        $rows = [];
        $errors = [];
        $types = TxnType::where('active', true)->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower(trim($n)) => $id])->all();
        foreach ($reader->getSheetIterator() as $sheet) {
            $i = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $i++;
                if ($i === 1) {
                    continue;
                }
                $c = $row->toArray();
                if (!array_filter($c, fn ($x) => $x !== null && $x !== '')) {
                    continue;
                }
                [$date, $type, $proj, $desc, $cc, $pr] = array_pad($c, 6, null);
                if ($desc && str_starts_with((string) $desc, 'Example - delete')) {
                    continue;
                }
                try {
                    $d = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse((string) $date);
                } catch (\Throwable) {
                    $errors[] = "Row $i: date '" . $date . "' is not valid.";
                    continue;
                }
                $tid = $types[mb_strtolower(trim((string) $type))] ?? null;
                if (!$tid) {
                    $errors[] = "Row $i: unknown transaction type '$type'.";
                    continue;
                }
                if (!trim((string) $desc)) {
                    $errors[] = "Row $i: description is required.";
                    continue;
                }
                $pid = $proj !== null && $proj !== '' ? Project::where('code', (int) $proj)->value('id') : null;
                if ($proj && !$pid) {
                    $errors[] = "Row $i: project $proj does not exist.";
                    continue;
                }
                $cid = $cc ? CostCenter::whereRaw('LOWER(code) = ?', [mb_strtolower(trim((string) $cc))])->value('id') : null;
                if ($cc && !$cid) {
                    $errors[] = "Row $i: cost center $cc does not exist.";
                    continue;
                }
                try {
                    $rows[] = Reservation::check(['date' => $d->toDateString(), 'txn_type_id' => $tid, 'project_id' => $pid, 'cost_center_id' => $cid,
                        'description' => trim((string) $desc), 'pr_number' => $pr === null ? null : trim((string) $pr)]);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    foreach ($e->errors() as $m) {
                        $errors[] = "Row $i: " . $m[0];
                    }
                }
            }
            break;
        }
        $reader->close();
        if ($errors) {
            return back()->withErrors($errors);
        }
        if (!$rows) {
            return back()->withErrors(['file' => 'The file has no rows to reserve.']);
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $made = DB::transaction(fn () => array_map(fn ($v) => Reservation::reserve($v, $r->user())->number, $rows));
        return back()->with('ok', count($made) . ' numbers reserved: ' . $made[0] . ' … ' . end($made));
    }
}
