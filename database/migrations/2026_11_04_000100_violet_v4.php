<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('form_prints', 'rtl')) {
            Schema::table('form_prints', fn (Blueprint $t) => $t->boolean('rtl')->default(false));
        }
        \App\Support\Upgrade::run();
    }

    public function down(): void
    {
    }
};
