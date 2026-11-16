<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\TxnCounter;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $year = (int) date('Y');
        $counters = TxnCounter::orderByDesc('year')->get();
        if (!$counters->firstWhere('year', $year)) {
            $counters->prepend(new TxnCounter(['year' => $year, 'next_number' => null]));
        }
        $last = Reservation::orderByDesc('id')->value('number');
        return view('admin.settings', compact('counters', 'last'));
    }

    public function update(Request $r)
    {
        $v = $r->validate([
            'org_name' => 'required|string|max:150', 'org_name_tr' => 'nullable|string|max:150',
            'max_attachment_mb' => 'required|integer|min:1|max:100',
            'idle_minutes' => 'required|integer|min:5|max:720',
            'uploader_email_to' => 'nullable|email', 'uploader_email_cc' => 'nullable|string|max:300',
            'logo_file' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'counters' => 'array', 'counters.*' => 'nullable|integer|min:1',
        ]);
        $was = Setting::get('two_factor_required', '1');
        Setting::put('two_factor_required', $r->boolean('two_factor_required') ? '1' : '0');
        if ($was !== Setting::get('two_factor_required')) {
            \App\Support\Audit::log('2fa', null, 'Two-factor requirement turned ' . ($r->boolean('two_factor_required') ? 'on' : 'off'));
        }
        foreach (['org_name', 'org_name_tr', 'max_attachment_mb', 'idle_minutes', 'uploader_email_to', 'uploader_email_cc'] as $k) {
            Setting::put($k, $v[$k] ?? null);
        }
        if ($r->hasFile('logo_file')) {
            $f = $r->file('logo_file');
            Setting::put('org_logo', 'data:' . $f->getMimeType() . ';base64,' . base64_encode(file_get_contents($f->getRealPath())));
        } elseif ($r->boolean('logo_reset')) {
            Setting::put('org_logo', null);
        }
        foreach ($v['counters'] ?? [] as $year => $next) {
            // a number is never given twice: the counter only moves forward
            $cur = (int) TxnCounter::where('year', (int) $year)->value('next_number');
            if ($next && (int) $next > $cur) {
                TxnCounter::updateOrCreate(['year' => (int) $year], ['next_number' => (int) $next]);
            }
        }
        return back()->with('ok', 'Settings saved.');
    }
}
