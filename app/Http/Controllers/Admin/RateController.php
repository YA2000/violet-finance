<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Dated exchange rates: typed the market way, kept as US dollars for 1 unit. The newest also becomes today's rate. */
class RateController extends Controller
{
    public function index(Request $r)
    {
        $cur = Currency::where('code', '!=', 'USD')->orderBy('sequence')->orderBy('code')->get();
        $q = CurrencyRate::with(['currency', 'user'])->orderByDesc('date')->orderBy('currency_id');
        if ($r->filled('currency')) {
            $q->where('currency_id', $r->input('currency'));
        }
        return view('admin.rates.index', ['currencies' => $cur, 'rates' => $q->paginate(60)->withQueryString()]);
    }

    /** rows: "date<TAB>code<TAB>rate" lines pasted from Excel, or the single form. */
    public function store(Request $r)
    {
        $codes = Currency::all()->keyBy(fn ($c) => strtoupper($c->code));
        $lines = [];
        if ($r->filled('paste')) {
            foreach (preg_split('/\r?\n/', trim($r->input('paste'))) as $i => $ln) {
                $c = preg_split('/\t|;|,(?=\S)/', trim($ln));
                if (count($c) >= 3) {
                    $lines[] = [$i + 1, $c[0], $c[1], $c[2]];
                }
            }
        } else {
            $v = $r->validate(['date' => 'required|date', 'currency_id' => 'required|exists:currencies,id', 'rate' => 'required|numeric|gt:0']);
            $lines[] = [1, $v['date'], Currency::find($v['currency_id'])->code, $v['rate']];
        }
        $rows = $errors = [];
        foreach ($lines as [$n, $d, $code, $rate]) {
            $c = $codes[strtoupper(trim($code))] ?? null;
            $rate = (float) str_replace([',', ' '], '', (string) $rate);
            try {
                $date = Carbon::parse(trim($d))->toDateString();
            } catch (\Throwable $e) {
                $date = null;
            }
            if (!$c || $c->code === 'USD' || $rate <= 0 || !$date) {
                $errors[] = "Line $n: " . (!$c ? "unknown currency \"$code\"" : (!$date ? 'the date is not valid' : 'the rate must be above 0')) . '.';
                continue;
            }
            $rows[] = [$c, $date, $c->in_usd ? $rate : 1 / $rate];
        }
        if ($errors || !$rows) {
            return back()->withErrors($errors ?: ['paste' => 'Nothing to save.'])->withInput();
        }
        DB::transaction(function () use ($rows, $r) {
            foreach ($rows as [$c, $date, $usd]) {
                CurrencyRate::updateOrCreate(['currency_id' => $c->id, 'date' => $date], ['usd_rate' => $usd, 'user_id' => $r->user()->id]);
            }
            // the newest dated rate of each currency is also today's rate
            foreach (collect($rows)->pluck(0)->unique('id') as $c) {
                $last = CurrencyRate::where('currency_id', $c->id)->orderByDesc('date')->first();
                $c->update(['usd_rate' => $last->usd_rate]);
            }
        });
        Currency::flush();
        Audit::log('money', null, count($rows) . ' exchange rate(s) saved', null, array_map(fn ($x) => [$x[0]->code, $x[1], $x[2]], $rows));
        return back()->with('ok', count($rows) . ' rate(s) saved.');
    }

    public function destroy(Request $r, CurrencyRate $rate)
    {
        Audit::log('delete', $rate->currency, 'Exchange rate of ' . $rate->date->format('Y-m-d') . ' deleted', $rate->toArray());
        $rate->delete();
        Currency::flush();
        return back()->with('ok', 'Rate deleted.');
    }
}
