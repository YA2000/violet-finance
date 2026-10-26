<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared costs: the monthly QuickBooks report of the shared (relocation) expenses is uploaded,
 * every line is shared between the projects by the FTE of the payroll of the same month,
 * and each project share goes to that project's budget line for the expense category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_categories', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();                 // QuickBooks Product/Service, e.g. 2-102-5
            $t->string('name');
            $t->string('office', 20)->nullable();             // Syria | Turkey | null = every office
            $t->string('basis', 20)->default('fte_office');   // fte_office | fte_all | direct
            $t->unsignedInteger('sequence')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('cost_category_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cost_category_id')->index();
            $t->string('project_code', 20);
            $t->foreignId('budget_line_id');
            $t->timestamps();
            $t->unique(['cost_category_id', 'project_code']);
        });

        Schema::create('expense_runs', function (Blueprint $t) {
            $t->id();
            $t->date('period')->unique();
            $t->string('status', 20)->default('draft');       // draft | approved
            $t->string('file_name')->nullable();
            $t->string('ref_number', 30)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->foreignId('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('allocated_at')->nullable();
            $t->timestamps();
        });

        Schema::create('expense_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_run_id')->index();
            $t->unsignedInteger('row_no')->default(0);
            $t->date('txn_date')->nullable();
            $t->string('txn_type', 40)->nullable();
            $t->string('num', 40)->nullable()->index();
            $t->string('name')->nullable();                    // QuickBooks Name (e.g. 102-Shared Cost Syria-USD)
            $t->text('description')->nullable();
            $t->decimal('amount_usd', 14, 2)->default(0);
            $t->string('currency', 10)->nullable();
            $t->decimal('exchange_rate', 16, 7)->nullable();
            $t->decimal('foreign_amount', 16, 2)->nullable();
            $t->string('category_code', 40)->nullable();
            $t->string('paid', 20)->nullable();
            $t->string('direct_project', 20)->nullable();      // whole line to one project (e.g. a transfer commission)
            $t->boolean('excluded')->default(false);
            $t->string('exclude_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('expense_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_run_id')->index();
            $t->foreignId('expense_line_id')->index();
            $t->string('project_code', 20)->index();
            $t->foreignId('budget_line_id')->nullable();
            $t->decimal('share', 10, 7);
            $t->decimal('amount_usd', 14, 2);
            $t->string('kind', 20)->default('key');            // key | direct | gap (no budget line: carried by the core fund)
            $t->string('gap_from', 20)->nullable();            // project whose share went to the core fund
            $t->timestamps();
        });

        \App\Support\SharedCostInstall::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_allocations');
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expense_runs');
        Schema::dropIfExists('cost_category_lines');
        Schema::dropIfExists('cost_categories');
    }
};
