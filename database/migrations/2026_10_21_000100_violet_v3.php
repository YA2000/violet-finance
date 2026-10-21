<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // transactions listed on a Remittance Statement, and how the treasury paid each of them
        if (!Schema::hasTable('remittance_items')) {
            Schema::create('remittance_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('remittance_id')->constrained('documents')->cascadeOnDelete();
                $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
                $t->string('currency', 10)->nullable();
                $t->decimal('amount', 20, 2)->default(0);
                $t->string('status', 12)->default('waiting');      // waiting | paid
                $t->decimal('paid_amount', 20, 2)->nullable();
                $t->date('paid_date')->nullable();
                $t->string('paid_ref', 20)->nullable();
                $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('paid_at')->nullable();
                $t->boolean('adjusted')->default(false);
                $t->string('note', 500)->nullable();
                $t->timestamps();
                $t->index(['document_id', 'status']);
            });
        }
        if (!Schema::hasColumn('advance_settlements', 'declared_at')) {
            Schema::table('advance_settlements', function (Blueprint $t) {
                $t->timestamp('declared_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_items');
        if (Schema::hasColumn('advance_settlements', 'declared_at')) {
            Schema::table('advance_settlements', fn (Blueprint $t) => $t->dropColumn('declared_at'));
        }
    }
};
