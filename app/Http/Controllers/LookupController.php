<?php

namespace App\Http\Controllers;

use App\Services\Lookup;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    public function search(Request $r, string $source)
    {
        abort_unless($source === 'payees' || str_starts_with($source, 'list:') || isset(Lookup::SOURCES[$source]), 404);
        return response()->json(Lookup::search($source, trim((string) $r->query('q', ''))));
    }
}
