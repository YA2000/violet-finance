<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function signature()
    {
        return view('account.signature', ['user' => auth()->user()]);
    }

    public function saveSignature(Request $r)
    {
        $r->validate(['drawn' => 'nullable|string', 'upload' => 'nullable|image|mimes:png,jpg,jpeg|max:2048']);
        $data = null;
        if ($r->hasFile('upload')) {
            $f = $r->file('upload');
            $data = 'data:' . $f->getMimeType() . ';base64,' . base64_encode(file_get_contents($f->getRealPath()));
        } elseif (str_starts_with((string) $r->input('drawn'), 'data:image/png;base64,')) {
            $data = $r->input('drawn');
        }
        if (!$data) {
            return back()->withErrors(['drawn' => 'Draw your signature or upload an image first.']);
        }
        $r->user()->update(['signature' => $data]);
        return back()->with('ok', 'Your signature is saved.');
    }

    public function password(Request $r)
    {
        $v = $r->validate(['current' => 'required', 'password' => 'required|min:8|confirmed']);
        if (!Hash::check($v['current'], $r->user()->password)) {
            return back()->withErrors(['current' => 'The current password is not correct.']);
        }
        $r->user()->update(['password' => $v['password']]);
        return back()->with('ok', 'Password changed.');
    }
}
