<?php

use Illuminate\Database\Migrations\Migration;

/** Last step of every update (keep this file last): the versioned data steps (they need the whole schema). */
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
