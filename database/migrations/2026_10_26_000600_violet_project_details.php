<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Project details from the finance projects follow-up sheet; applies the updated list of running projects (26-10-2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            $t->date('start_date')->nullable()->after('partner_id');
            $t->decimal('icr_rate', 6, 4)->nullable()->after('budget_total');        // indirect cost rate, 0.05 = 5 %
            $t->decimal('spent_total', 20, 2)->nullable()->after('icr_rate');        // spent so far, project currency
            $t->decimal('icr_amount', 20, 2)->nullable()->after('spent_total');      // indirect cost charged so far
            $t->decimal('received', 20, 2)->nullable()->after('balance');            // received from the donor, project currency
            $t->decimal('donor_balance', 20, 2)->nullable()->after('received');      // (spent + ICR) − received: + due from the donor, − held
            $t->string('office', 20)->nullable()->after('donor_balance');            // Turkey | Syria
            $t->string('manager')->nullable()->after('office');
        });
        \App\Support\ProjectDetailsInstall::run();
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['start_date', 'icr_rate', 'spent_total', 'icr_amount', 'received', 'donor_balance', 'office', 'manager']));
    }
};
