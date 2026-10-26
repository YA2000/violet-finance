<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost recovery: shared staff are allocated by an objective key (direct FTE of each project), up to what each project budgeted
 * for them (funding lines + their remaining capacity); what no project budgeted is the planned share of the core fund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_contracts', function (Blueprint $t) {
            $t->string('cost_type', 20)->default('direct')->after('employment');   // direct (works on projects, LOE from HR) | shared (support staff, LOE from the plan)
        });
        Schema::table('budget_lines', function (Blueprint $t) {
            $t->decimal('spent_before', 20, 2)->nullable()->after('total_amount');  // spent in QuickBooks before the system started (opening)
            $t->date('spent_before_date')->nullable()->after('spent_before');
        });
        Schema::table('currencies', function (Blueprint $t) {
            $t->decimal('usd_rate', 16, 7)->nullable();   // US dollars for 1 unit (QuickBooks), to compare budgets in other currencies
        });
        Schema::create('funding_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('budget_line_id')->index();
            $t->foreignId('individual_id')->index();
            $t->string('project_code', 20)->index();
            $t->boolean('active')->default(true);
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->unique(['budget_line_id', 'individual_id']);
        });
        Schema::table('loe_entries', function (Blueprint $t) {
            $t->decimal('amount_usd', 14, 2)->nullable()->after('percent');
            $t->string('reason', 20)->nullable()->after('amount_usd');   // plan: fair | capped | core
        });
        \App\Support\CostRecoveryInstall::run();
    }

    public function down(): void
    {
        Schema::table('loe_entries', fn (Blueprint $t) => $t->dropColumn(['amount_usd', 'reason']));
        Schema::dropIfExists('funding_lines');
        Schema::table('currencies', fn (Blueprint $t) => $t->dropColumn('usd_rate'));
        Schema::table('budget_lines', fn (Blueprint $t) => $t->dropColumn(['spent_before', 'spent_before_date']));
        Schema::table('hr_contracts', fn (Blueprint $t) => $t->dropColumn('cost_type'));
    }
};
