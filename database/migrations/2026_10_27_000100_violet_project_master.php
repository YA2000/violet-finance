<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Project master: sector, the three people in charge, QuickBooks status of projects and lines. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            $t->string('sector', 60)->nullable()->after('partner_id');                 // from the "Program" list
            $t->foreignId('finance_officer_id')->nullable()->after('manager');         // individuals
            $t->foreignId('meal_officer_id')->nullable()->after('finance_officer_id');
            $t->foreignId('program_officer_id')->nullable()->after('meal_officer_id');
            $t->timestamp('qb_added_at')->nullable();                                  // confirmed in QuickBooks by the system admin
            $t->foreignId('qb_added_by')->nullable();
        });
        Schema::table('budget_lines', function (Blueprint $t) {
            $t->timestamp('qb_added_at')->nullable();
        });
        // everything that exists today is already in QuickBooks
        DB::table('projects')->update(['qb_added_at' => now()]);
        DB::table('budget_lines')->update(['qb_added_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('budget_lines', fn (Blueprint $t) => $t->dropColumn('qb_added_at'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['sector', 'finance_officer_id', 'meal_officer_id', 'program_officer_id', 'qb_added_at', 'qb_added_by']));
    }
};
