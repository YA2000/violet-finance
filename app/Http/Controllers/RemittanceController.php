<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Files;
use App\Services\Remittances;
use Illuminate\Http\Request;

/** Internal viewer: Remittance Statements from fully signed transactions. Treasury: pay their items. */
class RemittanceController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $ready = Remittances::ready($u)->groupBy(fn ($d) => $d->currency ?: '—');
        $statements = Document::where('form_code', Remittances::FORM)->where('state', '!=', 'cancelled')
            ->withCount(['remittanceLines', 'remittanceLines as paid_count' => fn ($q) => $q->where('status', 'paid')])
            ->with('user')->latest('id')->limit(60)->get();
        return view('remittances.index', ['ready' => $ready, 'statements' => $statements]);
    }

    public function store(Request $r)
    {
        $v = $r->validate(['docs' => 'required|array|min:1', 'docs.*' => 'integer'], ['docs.required' => 'Tick the transactions to pay.']);
        $rem = Remittances::create($v['docs'], $r->user());
        return redirect()->route('documents.show', $rem)->with('ok', 'Remittance Statement ' . $rem->number . ' prepared. Check it and press Submit to send it for signature.');
    }

    public function payments(Request $r)
    {
        return view('remittances.payments', ['statements' => Remittances::payable()]);
    }

    public function pay(Request $r)
    {
        [$arr, $file] = Files::rules(true);
        $v = $r->validate(['items' => 'required|array|min:1', 'items.*' => 'integer', 'amount' => 'array', 'amount.*' => 'nullable|numeric|min:0',
            'note' => 'array', 'note.*' => 'nullable|string|max:300', 'paid_date' => 'required|date', 'files' => $arr, 'files.*' => $file],
            ['items.required' => 'Tick the items you paid.', 'files.required' => 'Attach the payment proof.'] + Files::messages());
        $rows = [];
        foreach ($v['items'] as $id) {
            $rows[(int) $id] = ['amount' => $v['amount'][$id] ?? null, 'note' => $v['note'][$id] ?? null];
        }
        $n = Remittances::pay($rows, $v['paid_date'], $r->file('files', []), $r->user());
        return back()->with('ok', $n . ' payment' . ($n === 1 ? '' : 's') . ' recorded. The transaction' . ($n === 1 ? ' is' : 's are') . ' final now.');
    }
}
