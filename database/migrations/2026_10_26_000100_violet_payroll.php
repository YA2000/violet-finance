<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified payroll: contracts of every kind of staff, monthly payroll runs, the level of effort (LOE)
 * each employee gives to the budget lines, and the allocation of each salary to projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('individual_id')->index();
            $t->string('number', 60)->nullable();
            $t->string('position')->nullable();
            $t->string('office', 20)->default('Syria');              // Syria | Turkey (decides the payroll rules)
            $t->string('employment', 20)->default('full_time');      // full_time | part_time | daily | shift
            $t->string('pay_basis', 20)->default('monthly');         // monthly | daily | shift | hourly
            $t->decimal('rate', 14, 4)->default(0);                  // monthly salary, or the rate of one day / shift / hour
            $t->string('currency', 10)->default('USD');
            $t->decimal('fte', 6, 4)->default(1);                    // part time: 0.5 ...
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->foreignId('default_budget_line_id')->nullable();     // used when the employee has only one budget line
            $t->foreignId('previous_id')->nullable();                // contract this one replaced (position / salary change)
            $t->string('change_reason')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->date('period')->unique();                            // first day of the month
            $t->string('status', 20)->default('draft');              // draft | approved
            $t->string('ref_number', 30)->nullable();                // transaction number used in QuickBooks
            $t->decimal('rate_try', 16, 7)->nullable();              // US dollars for 1 TRY (QuickBooks)
            $t->decimal('rate_syp', 16, 7)->nullable();              // US dollars for 1 SYP
            $t->decimal('rate_eur', 16, 7)->nullable();              // US dollars for 1 EUR
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->foreignId('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('allocated_at')->nullable();
            $t->timestamps();
        });

        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->index();
            $t->foreignId('individual_id')->index();
            $t->foreignId('contract_id')->nullable();
            $t->string('office', 20);
            $t->string('pay_basis', 20);
            $t->string('currency', 10);
            $t->decimal('rate', 14, 4)->default(0);
            $t->decimal('fte', 6, 4)->default(1);
            $t->decimal('units_due', 10, 2)->default(0);             // monthly: contract days in the month; others: 0
            $t->decimal('units', 10, 2)->default(0);                 // worked days / shifts / hours
            $t->decimal('basic', 14, 2)->default(0);
            $t->decimal('additions', 14, 2)->default(0);
            $t->decimal('deductions', 14, 2)->default(0);
            $t->decimal('net', 14, 2)->default(0);                   // what the employee receives
            $t->decimal('tr_insurance', 14, 2)->default(0);          // Turkey: employer SGK (from the bordro)
            $t->decimal('tr_tax', 14, 2)->default(0);                // Turkey: income + stamp tax
            $t->decimal('tr_indirim', 14, 2)->default(0);            // Turkey: incentive discount
            $t->decimal('total_cost', 14, 2)->default(0);            // in the contract currency
            $t->decimal('usd_rate', 16, 7)->default(1);
            $t->decimal('total_usd', 14, 2)->default(0);
            $t->decimal('fte_month', 8, 4)->default(0);              // share of a full-time month (for running-cost keys)
            $t->boolean('manual')->default(false);                   // units or amounts changed by hand: kept on refresh
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['payroll_run_id', 'contract_id']);
        });

        Schema::create('loe_entries', function (Blueprint $t) {
            $t->id();
            $t->date('period')->index();
            $t->foreignId('individual_id')->index();
            $t->foreignId('budget_line_id');
            $t->decimal('percent', 7, 3);
            $t->string('source', 20)->default('employee');           // employee | hr | import | copy
            $t->foreignId('user_id')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['period', 'individual_id', 'budget_line_id']);
        });

        Schema::create('payroll_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->index();
            $t->foreignId('payroll_line_id')->index();
            $t->foreignId('individual_id');
            $t->foreignId('budget_line_id')->index();
            $t->string('project_code', 20)->index();
            $t->string('component', 20)->default('salary');          // salary | insurance | tax
            $t->decimal('percent', 7, 3);
            $t->decimal('amount', 14, 2);                            // contract currency
            $t->decimal('amount_usd', 14, 2);
            $t->decimal('fte', 8, 4)->default(0);
            $t->timestamps();
        });

        \App\Support\PayrollInstall::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_allocations');
        Schema::dropIfExists('loe_entries');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('hr_contracts');
    }
};
