<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('partners', 'short')) {
            Schema::table('partners', fn (Blueprint $t) => $t->string('short', 30)->nullable());   // ALRAHMA in 298-2026-084-ALRAHMA
        }
        if (!Schema::hasColumn('projects', 'year_no')) {
            Schema::table('projects', function (Blueprint $t) {
                $t->unsignedSmallInteger('year')->nullable();         // year of the start date
                $t->unsignedSmallInteger('year_no')->nullable();      // 084: number of the project in that year
                $t->string('status_mode', 10)->default('auto');       // auto: by the dates and the donor balance | manual
                $t->decimal('agreement_rate', 24, 10)->nullable();    // typed the market way (see currencies.in_usd)
                $t->unique(['year', 'year_no']);
            });
            // projects already switched off stay off: "Ended" by hand becomes Closed; Suspended / Cancelled stay, by hand
            DB::table('projects')->where('status', 'Ended')->update(['status' => 'Closed', 'status_mode' => 'manual']);
            DB::table('projects')->whereIn('status', ['Suspended', 'Cancelled'])->update(['status_mode' => 'manual']);
            DB::table('projects')->where('active', false)->where('status', 'Ongoing')->update(['status' => 'Closed', 'status_mode' => 'manual']);
        }
        if (!Schema::hasTable('project_amendments')) {
            Schema::create('project_amendments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->string('kind', 20)->default('extension');
                $t->date('date');
                $t->date('old_end_date')->nullable();
                $t->date('new_end_date')->nullable();
                $t->decimal('old_amount', 20, 2)->nullable();
                $t->decimal('extra_amount', 20, 2)->default(0);
                $t->string('reference', 120)->nullable();
                $t->text('notes')->nullable();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
    }
};
