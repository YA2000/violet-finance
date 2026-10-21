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
    public function index(Request $r)
    {
        $q = Reservation::with(['type', 'user', 'project', 'document'])->orderByDesc('number');
        if ($s = trim((string) $r->query('q'))) {
            $q->where(function ($w) use ($s) {
                $w->where('number', 'like', "%$s%")->orWhere('description', 'like', "%$s%")
                  ->orWhereHas('type', fn ($t) => $t->where('name', 'like', "%$s%"))
                  ->orWhereHas('user', fn ($t) => $t->where('name', 'like', "%$s%"));
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
        $counts = Reservation::selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->all();
        return view('reservations.index', [
            'items' => $q->paginate(40)->withQueryString(),
            'types' => TxnType::where('active', true)->orderBy('sequence')->get(),
            'counts' => $counts,
            'mineFree' => Reservation::where('user_id', auth()->id())->where('state', 'reserved')->count(),
        ]);
    }

    protected function rules(): array
    {
        return ['date' => 'required|date', 'txn_type_id' => 'required|exists:txn_types,id',
                'project_id' => 'nullable|exists:projects,id', 'cost_center_id' => 'nullable|exists:cost_centers,id',
                'description' => 'required|string|max:250'];
    }

    public function store(Request $r)
    {
        $v = $r->validate($this->rules());
        $res = Reservation::reserve($v, $r->user());
        if ($r->expectsJson()) {
            return response()->json(['id' => $res->id, 'number' => $res->number, 'text' => $res->number . ' - ' . $res->description]);
        }
        return back()->with('ok', 'Number ' . $res->number . ' reserved.')->with('reserved', $res->number);
    }

    public function update(Request $r, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $r->user()->id || $r->user()->hasPerm('numbers.manage'), 403);
        abort_unless($reservation->state === 'reserved', 422, 'Only free numbers can be changed.');
        $v = $r->validate($this->rules());
        if (Carbon::parse($v['date'])->format('y') !== substr($reservation->number, 1, 2)) {
            return back()->withErrors(['date' => 'The date must stay in year 20' . substr($reservation->number, 1, 2) . ' (the number contains the year).']);
        }
        $reservation->update($v);
        return back()->with('ok', $reservation->number . ' updated.');
    }

    public function cancel(Request $r, Reservation $reservation)
    {
        abort_unless($reservation->user_id === $r->user()->id || $r->user()->hasPerm('numbers.manage'), 403);
        abort_unless($reservation->state === 'reserved', 422, 'Only free numbers can be cancelled.');
        $reservation->update(['state' => 'cancel']);
        return back()->with('ok', $reservation->number . ' cancelled.');
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
        $w->addRow(Row::fromValues(['Date (YYYY-MM-DD)', 'Transaction Type', 'Project Code', 'Description', 'Cost Center Code'], $bold));
        $w->addRow(Row::fromValues([date('Y-m-d'), TxnType::orderBy('sequence')->value('name'), '1', 'Example - delete this row', '101-USD']));
        $types = $w->addNewSheetAndMakeItCurrent();
        $types->setName('Transaction Types');
        $w->addRow(Row::fromValues(['Transaction Types (copy the name exactly)'], $bold));
        foreach (TxnType::where('active', true)->orderBy('sequence')->pluck('name') as $n) {
            $w->addRow(Row::fromValues([$n]));
        }
        $w->close();
        return response()->download($path, 'Reservation_Template.xlsx')->deleteFileAfterSend();
    }

    public function import(Request $r)
    {
        $r->validate(['file' => 'required|file|mimes:xlsx|max:5120']);
        $reader = new Reader();
        $reader->open($r->file('file')->getRealPath());
        $rows = [];
        $errors = [];
        $types = TxnType::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower(trim($n)) => $id])->all();
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
                [$date, $type, $proj, $desc, $cc] = array_pad($c, 5, null);
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
                $rows[] = ['date' => $d->toDateString(), 'txn_type_id' => $tid, 'project_id' => $pid, 'cost_center_id' => $cid,
                           'description' => trim((string) $desc)];
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
