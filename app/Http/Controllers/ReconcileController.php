<?php

namespace App\Http\Controllers;

use App\Models\QbReconciliation;
use App\Services\QbReconcile;
use App\Support\Audit;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** QuickBooks reconciliation: upload a QuickBooks export, compare it with the final forms of the period, keep the result. */
class ReconcileController extends Controller
{
    public function index()
    {
        return view('reconcile.index', ['runs' => QbReconciliation::with('user')->latest()->paginate(20)]);
    }

    public function store(Request $r)
    {
        $v = $r->validate([
            'file' => 'required|file|max:20480|mimes:xlsx,csv,txt',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
            'tolerance' => 'nullable|numeric|min:0|max:100000',
            'map' => 'nullable|array',
        ], ['file.mimes' => 'Export the QuickBooks report as Excel (.xlsx) or CSV.']);
        $f = $r->file('file');
        try {
            $rows = QbReconcile::readFile($f->getRealPath(), $f->getClientOriginalName());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'The file could not be read: ' . $e->getMessage()])->withInput();
        }
        $det = QbReconcile::detect($rows, array_filter($v['map'] ?? [], fn ($x) => $x !== null));
        if (!$det) {
            return back()->withErrors(['file' => 'No column with the transaction number ("No.", "Num" or "RefNumber") and an amount was found in the first 30 rows. '
                . 'In QuickBooks open Reports → "Transaction List by Date", choose the period, and Export to Excel.'])->withInput();
        }
        [$qb, $other] = QbReconcile::qbTransactions($rows, $det);
        $dates = collect($qb)->flatMap(fn ($t) => $t['dates'])->sort()->values();
        $from = $v['from'] ?? $dates->first();
        $to = $v['to'] ?? $dates->last();
        if (!$from || !$to) {
            return back()->withErrors(['from' => 'The file has no dates: choose the period.'])->withInput();
        }
        $tol = (float) ($v['tolerance'] ?? 1);
        $res = QbReconcile::reconcile($qb, QbReconcile::systemTransactions($from, $to), $tol);
        $sum = QbReconcile::summary($res) + ['qb_numbers' => count($qb), 'qb_other' => $other['rows']];
        [, $map, $headers] = $det;
        $run = QbReconciliation::create([
            'period_from' => $from, 'period_to' => $to, 'file_name' => mb_substr($f->getClientOriginalName(), 0, 250), 'tolerance' => $tol,
            'summary' => $sum, 'rows' => json_encode($res, JSON_UNESCAPED_UNICODE), 'other' => $other,
            'columns' => collect($map)->map(fn ($i) => $headers[$i] ?? ('column ' . ($i + 1)))->all(), 'user_id' => $r->user()->id,
        ]);
        Audit::log('reconcile', null, 'QuickBooks reconciliation ' . $from . ' → ' . $to . ': ' . $sum['ok'] . ' matched, ' . $sum['issues'] . ' to check',
            null, ['run' => $run->id, 'file' => $run->file_name] + $sum);
        return redirect()->route('reconcile.show', $run);
    }

    public function show(Request $r, QbReconciliation $run)
    {
        $rows = collect($run->results());
        $tab = $r->query('show', $run->summary['issues'] ? 'issues' : 'all');
        $shown = match ($tab) {
            'issues' => $rows->whereNotIn('status', ['ok', 'period']),
            'all' => $rows,
            default => $rows->where('status', $tab),
        };
        return view('reconcile.show', ['run' => $run, 'rows' => $shown->values(), 'tab' => $tab, 'labels' => QbReconcile::LABELS]);
    }

    public function export(QbReconciliation $run)
    {
        $path = storage_path('app/tmp/' . uniqid('rec_') . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $w = new Writer();
        $w->openToFile($path);
        $bold = (new Style())->setFontBold();
        $w->getCurrentSheet()->setName('Reconciliation');
        $w->addRow(Row::fromValues(['QuickBooks reconciliation', $run->period_from->format('Y-m-d') . ' → ' . $run->period_to->format('Y-m-d'), 'File: ' . $run->file_name,
            'Run by ' . ($run->user?->name ?? '-') . ' on ' . $run->created_at->format('Y-m-d H:i')], $bold));
        $w->addRow(Row::fromValues([]));
        $w->addRow(Row::fromValues(['Status', 'Number', 'Form', 'Date (system)', 'Description', 'Amount (system)', 'Currency', 'USD (system)',
            'Amount (QuickBooks)', 'Foreign amount (QuickBooks)', 'Date (QuickBooks)', 'Type (QuickBooks)', 'Difference', 'Notes'], $bold));
        foreach ($run->results() as $x) {
            $w->addRow(Row::fromValues([QbReconcile::LABELS[$x['status']][0], $x['num'], $x['form'], $x['date'], $x['description'], $x['sys_amount'], $x['sys_currency'],
                $x['sys_usd'], $x['qb_amount'], $x['qb_foreign'], $x['qb_date'], $x['qb_type'], $x['diff'], implode(' ', $x['notes'])]));
        }
        $w->close();
        return response()->download($path, 'quickbooks-reconciliation-' . $run->period_from->format('Y-m-d') . '-' . $run->period_to->format('Y-m-d') . '.xlsx')->deleteFileAfterSend();
    }
}
