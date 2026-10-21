<?php

use Illuminate\Database\Migrations\Migration;

/** v2 data: builder schemas for the workbook forms, roles, users -> roles, printout settings. Safe to repeat. */
return new class extends Migration
{
    public function up(): void
    {
        \App\Support\Upgrade::run();
    }

    public function down(): void
    {
    }
};
