<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every QuickBooks reconciliation that was run: the period, the file, the counts and the full result (kept as evidence). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qb_reconciliations')) {
            return;
        }
        Schema::create('qb_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->date('period_from');
            $t->date('period_to');
            $t->string('file_name', 255);
            $t->decimal('tolerance', 12, 2)->default(1);
            $t->json('summary');
            $t->longText('rows');           // JSON: one entry per transaction number
            $t->json('other')->nullable();  // QuickBooks rows without a system number
            $t->json('columns')->nullable();// which column of the file was used for what
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qb_reconciliations');
    }
};
