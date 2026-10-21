<?php

use Illuminate\Database\Migrations\Migration;

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
