<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Version 8: project statuses (auto / manual). Keeps every switched-off project consistent; safe to run every time. */
class V8
{
    public static function run(): void
    {
        if (!Schema::hasColumn('projects', 'status_mode')) {
            return;
        }
        DB::table('projects')->where('active', false)->whereIn('status', ['Ongoing', 'Ended', 'Pipeline'])->update(['status' => 'Closed', 'status_mode' => 'manual']);
    }
}
