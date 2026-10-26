<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Allocation lab: a separate, simple test area for the allocation of shared expenses and shared staff. */
return new class extends Migration
{
    public function up(): void
    {
        // a project budget line that takes part of a category (expenses) or of an employee (staff)
        Schema::create('lab_lines', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10);                          // expense | staff
            $t->string('category_code', 40)->nullable();     // project 2 line, e.g. 2-102-5 (expenses)
            $t->foreignId('lab_staff_id')->nullable();       // staff
            $t->foreignId('budget_line_id');
            $t->string('project_code', 20);
            $t->decimal('total', 16, 2)->default(0);         // USD
            $t->decimal('spent', 16, 2)->default(0);         // USD, spent before
            $t->timestamps();
        });
        Schema::create('lab_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('individual_id');
            $t->string('office', 20)->default('Syria');
            $t->decimal('salary', 14, 2)->default(0);        // USD per month
            $t->timestamps();
        });
        Schema::create('lab_expenses', function (Blueprint $t) {
            $t->id();
            $t->date('period')->index();
            $t->string('category_code', 40);
            $t->string('ref', 40)->nullable();
            $t->string('description')->nullable();
            $t->decimal('amount', 14, 2);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_expenses');
        Schema::dropIfExists('lab_staff');
        Schema::dropIfExists('lab_lines');
    }
};
