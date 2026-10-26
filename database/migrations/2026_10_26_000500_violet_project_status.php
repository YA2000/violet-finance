<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Project status, budget and donor balance; budget lines archived with their project. Applies the list of running projects (Oct-2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            $t->string('status', 20)->default('Ongoing')->after('end_date');     // Ongoing | Ended | Suspended | Cancelled
            $t->decimal('budget_total', 20, 2)->nullable()->after('status');      // project currency
            $t->decimal('balance', 20, 2)->nullable()->after('budget_total');     // donor balance not spent yet, project currency
            $t->date('balance_date')->nullable()->after('balance');
        });
        Schema::table('budget_lines', function (Blueprint $t) {
            $t->timestamp('archived_at')->nullable()->after('active');           // archived together with its project
        });
        \App\Support\ProjectStatusInstall::run();
    }

    public function down(): void
    {
        Schema::table('budget_lines', fn (Blueprint $t) => $t->dropColumn('archived_at'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['status', 'budget_total', 'balance', 'balance_date']));
    }
};
