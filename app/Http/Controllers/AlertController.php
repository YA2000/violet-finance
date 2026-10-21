<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $r)
    {
        $items = Alert::where('user_id', $r->user()->id)->latest('id')->limit(15)->get()->map(fn ($a) => [
            'id' => $a->id, 'title' => $a->title, 'body' => \Illuminate\Support\Str::limit((string) $a->body, 90), 'icon' => $a->icon,
            'read' => (bool) $a->read_at, 'ago' => $a->created_at->diffForHumans(), 'href' => route('alerts.open', $a),
        ]);
        return response()->json(['items' => $items, 'unread' => Alert::where('user_id', $r->user()->id)->whereNull('read_at')->count()]);
    }

    public function open(Request $r, Alert $alert)
    {
        abort_unless($alert->user_id === $r->user()->id, 404);
        $alert->update(['read_at' => $alert->read_at ?? now()]);
        return redirect($alert->url ?: route('dashboard'));
    }

    public function readAll(Request $r)
    {
        Alert::where('user_id', $r->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
