<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('currencies', 'in_usd')) {
            Schema::table('currencies', function (Blueprint $t) {
                // quoted in US dollars for 1 unit (1 EUR = 1.16 USD); the others in units for 1 US dollar (1 USD = 122 SYP)
                $t->boolean('in_usd')->default(false);
            });
            DB::table('currencies')->whereIn('code', \App\Support\V7::IN_USD)->update(['in_usd' => true]);
        }
        \App\Support\Upgrade::run();
    }

    public function down(): void
    {
    }
};
