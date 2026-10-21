<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Attachment;
use App\Models\Currency;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\Reservation;
use App\Models\TxnType;
use App\Services\Files;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Inform a new Receive Payment: treasury -> reporting (project) -> AR (record with a transaction number). */
class ReceiptController extends Controller
{
    protected function see(Request $r): void
    {
        $u = $r->user();
        abort_unless($u->hasPerm('receipts.view') || $u->hasPerm('receipts.inform') || $u->hasPerm('receipts.assign') || $u->hasPerm('receipts.record'), 403);
    }

    public function index(Request $r)
    {
        $this->see($r);
        $q = Receipt::with(['bankAccount', 'project', 'reservation', 'creator'])->latest('date')->latest('id');
        $status = $r->input('status');
        if ($status) {
            $q->where('status', $status);
        }
        if ($s = trim((string) $r->input('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%$s%")->orWhere('bank_ref', 'like', "%$s%")->orWhere('payer', 'like', "%$s%")->orWhere('description', 'like', "%$s%"));
        }
        if ($r->filled('from')) {
            $q->whereDate('date', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('date', '<=', $r->input('to'));
        }
        $counts = Receipt::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all();
        return view('receipts.index', ['items' => $q->paginate(30)->withQueryString(), 'counts' => $counts, 'status' => $status,
            'currencies' => Currency::where('active', true)->orderBy('sequence')->pluck('code')]);
    }

    public function store(Request $r)
    {
        [$arr, $file] = Files::rules();
        $v = $r->validate(['date' => 'required|date', 'bank_account_id' => 'required|exists:accounts,id', 'bank_ref' => 'nullable|string|max:80',
            'no_ref' => 'nullable|boolean', 'amount' => 'required|numeric|min:0.01', 'currency' => 'required|string|max:10',
            'payer' => 'nullable|string|max:190', 'description' => 'nullable|string|max:1000', 'files' => $arr, 'files.*' => $file], Files::messages());
        if (!$r->boolean('no_ref') && empty($v['bank_ref'])) {
            return back()->withErrors(['bank_ref' => 'Write the bank notice number, or tick “No notice number” so the system gives one.'])->withInput();
        }
        $rec = DB::transaction(function () use ($r, $v) {
            $rec = Receipt::make(collect($v)->except(['files', 'no_ref'])->all() + ['bank_ref' => $r->boolean('no_ref') ? null : $v['bank_ref'],
                'status' => 'project', 'created_by' => $r->user()->id]);
            foreach ($r->file('files', []) as $f) {
                Attachment::store($f, ['receipt_id' => $rec->id, 'kind' => 'bank notice'], $r->user(), 'receipts/' . $rec->id);
            }
            $rec->log('inform', 'Payment informed by ' . $r->user()->name . '.', $r->user());
            return $rec;
        });
        Alert::send('receipts.assign', 'New payment ' . $rec->number . ': ' . number_format($rec->amount, 2) . ' ' . $rec->currency,
            'Choose the project it belongs to.', route('receipts.show', $rec), 'fa-hand-holding-dollar', $r->user()->id);
        return redirect()->route('receipts.show', $rec)->with('ok', 'Payment ' . $rec->number . ' informed - reporting is notified.');
    }

    public function show(Request $r, Receipt $receipt)
    {
        $this->see($r);
        $receipt->load(['bankAccount', 'project.currency', 'reservation', 'creator', 'assigner', 'recorder', 'attachments.user', 'events.user']);
        return view('receipts.show', ['rc' => $receipt, 'types' => TxnType::where('active', true)->orderBy('sequence')->get(),
            'rfType' => TxnType::where('name', 'like', 'Received Payment%')->value('id'),
            'freeNumbers' => Reservation::where('user_id', $r->user()->id)->where('state', 'reserved')->orderBy('number')->get()]);
    }

    /** Treasury corrects its entry while nobody has handled it yet. */
    public function update(Request $r, Receipt $receipt)
    {
        abort_unless($r->user()->hasPerm('receipts.inform') && $receipt->status === 'project', 403);
        $v = $r->validate(['date' => 'required|date', 'bank_account_id' => 'required|exists:accounts,id', 'bank_ref' => 'nullable|string|max:80',
            'amount' => 'required|numeric|min:0.01', 'currency' => 'required|string|max:10', 'payer' => 'nullable|string|max:190', 'description' => 'nullable|string|max:1000']);
        if (empty($v['bank_ref'])) {
            $v['bank_ref'] = $receipt->number;
            $v['bank_ref_auto'] = true;
        } else {
            $v['bank_ref_auto'] = false;
        }
        $receipt->update($v);
        $receipt->log('edit', 'Details corrected by ' . $r->user()->name . '.', $r->user());
        return back()->with('ok', 'Saved.');
    }

    /** Reporting officer: the project (and, for another currency, the payment request + amount in project currency). */
    public function project(Request $r, Receipt $receipt)
    {
        abort_unless(in_array($receipt->status, ['project', 'record'], true), 422, 'This payment is already recorded.');
        [$arr, $file] = Files::rules();
        $v = $r->validate(['project_id' => 'required|exists:projects,id', 'project_amount' => 'nullable|numeric|min:0', 'files' => $arr, 'files.*' => $file], Files::messages());
        $p = Project::with('currency')->find($v['project_id']);
        $pc = $p->currency?->code;
        $other = $pc && strtoupper($pc) !== strtoupper($receipt->currency);
        if ($other) {
            $hasReq = $receipt->attachments()->where('kind', 'payment request')->exists() || $r->hasFile('files');
            if (empty($v['project_amount']) || !$hasReq) {
                return back()->withErrors(['project_amount' => "The project is in $pc and the payment arrived in {$receipt->currency}: attach the payment request and give the amount in $pc."])->withInput();
            }
        }
        DB::transaction(function () use ($r, $receipt, $v, $p, $pc, $other) {
            foreach ($r->file('files', []) as $f) {
                Attachment::store($f, ['receipt_id' => $receipt->id, 'kind' => 'payment request'], $r->user(), 'receipts/' . $receipt->id);
            }
            $receipt->update(['project_id' => $p->id, 'project_currency' => $pc, 'project_amount' => $other ? $v['project_amount'] : $receipt->amount,
                'status' => 'record', 'assigned_by' => $r->user()->id, 'assigned_at' => now()]);
            $receipt->log('project', 'Project ' . $p->code . ' - ' . $p->name . ' chosen by ' . $r->user()->name . '.', $r->user());
        });
        Alert::send('receipts.record', 'Payment ' . $receipt->number . ' is ready to record', $p->code . ' - ' . $p->name . ' · ' . number_format($receipt->amount, 2) . ' ' . $receipt->currency,
            route('receipts.show', $receipt), 'fa-file-signature', $r->user()->id);
        return back()->with('ok', 'Project saved - the AR accountant is notified.');
    }

    /** AR accountant records it with a reserved transaction number. */
    public function record(Request $r, Receipt $receipt)
    {
        abort_unless($receipt->status === 'record', 422, 'Choose the project first.');
        $v = $r->validate(['reservation_id' => 'nullable|exists:reservations,id', 'txn_type_id' => 'nullable|exists:txn_types,id',
            'record_description' => 'required|string|max:1000', 'bank_account_id' => 'required|exists:accounts,id', 'project_id' => 'required|exists:projects,id']);
        $u = $r->user();
        DB::transaction(function () use ($v, $u, $receipt) {
            if (!empty($v['reservation_id'])) {
                $res = Reservation::findOrFail($v['reservation_id']);
                abort_unless($res->state === 'reserved' && ($res->user_id === $u->id || $u->hasPerm('numbers.manage')), 422, 'Pick one of your free reserved numbers.');
            } else {
                $res = Reservation::reserve(['date' => $receipt->date->toDateString(), 'txn_type_id' => $v['txn_type_id'] ?? TxnType::orderBy('sequence')->value('id'),
                    'project_id' => $v['project_id'], 'description' => mb_substr($v['record_description'], 0, 250)], $u);
            }
            $res->update(['state' => 'used', 'used_for' => 'Receipt ' . $receipt->number]);
            $receipt->update(['reservation_id' => $res->id, 'record_description' => $v['record_description'], 'bank_account_id' => $v['bank_account_id'],
                'project_id' => $v['project_id'], 'status' => 'recorded', 'recorded_by' => $u->id, 'recorded_at' => now()]);
            $receipt->log('record', 'Recorded to the system as ' . $res->number . ' by ' . $u->name . '.', $u);
        });
        $receipt->refresh();
        Alert::send(collect([$receipt->created_by, $receipt->assigned_by])->filter()->all(), 'Payment ' . $receipt->number . ' recorded as ' . $receipt->reservation->number,
            $receipt->record_description, route('receipts.show', $receipt), 'fa-flag-checkered', $u->id);
        return back()->with('ok', 'Recorded as ' . $receipt->reservation->number . '.');
    }

    /** One step back (with a reason). */
    public function back(Request $r, Receipt $receipt)
    {
        $u = $r->user();
        $v = $r->validate(['reason' => 'required|string|max:500']);
        if ($receipt->status === 'record' && ($u->hasPerm('receipts.record') || $u->hasPerm('receipts.assign'))) {
            $receipt->update(['status' => 'project']);
            $to = 'receipts.assign';
        } elseif ($receipt->status === 'recorded' && $u->hasPerm('receipts.record')) {
            $receipt->reservation?->update(['state' => 'reserved', 'used_for' => null]);
            $receipt->update(['status' => 'record', 'reservation_id' => null, 'recorded_at' => null]);
            $to = 'receipts.record';
        } else {
            abort(403);
        }
        $receipt->log('back', 'Sent back by ' . $u->name . ': ' . $v['reason'], $u);
        Alert::send($to, 'Payment ' . $receipt->number . ' sent back', $v['reason'], route('receipts.show', $receipt), 'fa-rotate-left', $u->id);
        return back()->with('ok', 'Sent back.');
    }

    public function files(Request $r, Receipt $receipt)
    {
        $this->see($r);
        [$arr, $file] = Files::rules(true);
        $r->validate(['files' => $arr, 'files.*' => $file, 'kind' => 'nullable|string|max:30'], Files::messages());
        foreach ($r->file('files') as $f) {
            Attachment::store($f, ['receipt_id' => $receipt->id, 'kind' => $r->input('kind') ?: 'file'], $r->user(), 'receipts/' . $receipt->id);
        }
        $receipt->log('attach', count($r->file('files')) . ' file(s) attached by ' . $r->user()->name . '.', $r->user());
        return back()->with('ok', 'Files attached.');
    }
}
