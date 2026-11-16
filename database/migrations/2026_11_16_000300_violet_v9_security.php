<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v9: two-factor sign-in, immutable audit trail, dated currency rates, expense lines (fast budget figures). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'two_factor_secret')) {
            Schema::table('users', function (Blueprint $t) {
                $t->text('two_factor_secret')->nullable();          // encrypted
                $t->text('two_factor_recovery')->nullable();        // encrypted JSON of hashed one-time codes
                $t->timestamp('two_factor_confirmed_at')->nullable();
            });
        }
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->id();
                $t->string('action', 40);                 // delete, update, money, login, 2fa...
                $t->string('subject_type', 60)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->string('subject_label', 190)->nullable();
                $t->text('summary')->nullable();
                $t->json('before')->nullable();
                $t->json('after')->nullable();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('ip', 45)->nullable();
                $t->string('hash', 64)->nullable();       // chained: hash(previous hash + this row) - a removed or changed row breaks the chain
                $t->timestamp('created_at')->useCurrent();
                $t->index(['subject_type', 'subject_id']);
            });
        }
        if (!Schema::hasTable('currency_rates')) {
            Schema::create('currency_rates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('currency_id')->constrained()->cascadeOnDelete();
                $t->date('date');
                $t->decimal('usd_rate', 24, 10);          // US dollars for 1 unit
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
                $t->unique(['currency_id', 'date']);
            });
        }
        if (!Schema::hasTable('spend_lines')) {
            Schema::create('spend_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('document_id')->constrained()->cascadeOnDelete();
                $t->string('form_code', 40);
                $t->string('number', 30)->nullable();
                $t->date('date')->nullable();
                $t->unsignedInteger('line_no');
                $t->foreignId('budget_line_id')->constrained()->cascadeOnDelete();
                $t->decimal('amount', 20, 2);             // paid, in the document currency
                $t->string('currency', 10);
                $t->decimal('usd', 20, 2);                // at the rate of the transaction
                $t->string('note', 250)->nullable();
                $t->timestamps();
                $t->index(['budget_line_id', 'date']);
            });
        }
    }

    public function down(): void
    {
    }
};
