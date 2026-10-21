<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v2: form builder, roles & permissions, notifications, review, advances, received payments. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_types', function (Blueprint $t) {
            $t->string('short', 60)->nullable();
            $t->string('group', 60)->nullable();
            $t->boolean('active')->default(true);
            $t->longText('schema')->nullable();
        });
        Schema::create('form_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_type_id')->constrained()->cascadeOnDelete();
            $t->longText('schema');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note')->nullable();
            $t->timestamps();
        });
        Schema::table('form_prints', function (Blueprint $t) {
            $t->string('title')->nullable();
            $t->string('orientation', 12)->default('portrait');
            $t->text('labels')->nullable();
            $t->text('hidden_keys')->nullable();
            $t->boolean('in_final')->default(true);
        });
        Schema::table('form_prints', function (Blueprint $t) {
            $t->string('sheet')->nullable()->change();
            $t->string('report')->nullable()->change();
        });
        Schema::table('form_roles', function (Blueprint $t) {
            $t->string('print_label')->nullable();
        });
        Schema::table('document_signatures', function (Blueprint $t) {
            $t->string('print_label')->nullable();
        });

        // ------------------------------------------------------------ roles
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('description')->nullable();
            $t->text('permissions')->nullable();
            $t->timestamps();
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'user_id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_super')->default(false);
        });

        // ------------------------------------------------------------ notifications + review
        Schema::create('alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('url')->nullable();
            $t->string('icon', 40)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });
        Schema::create('review_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();
            $t->text('body');
            $t->string('status', 12)->default('open');   // open | fixed | closed
            $t->text('reply')->nullable();
            $t->foreignId('fixed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('fixed_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->boolean('returned')->default(false);
            $t->timestamps();
        });

        // ------------------------------------------------------------ advances
        Schema::create('advance_holders', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind', 12)->default('person');   // person | cashbox
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('individual_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('advances', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->unique();
            $t->foreignId('holder_id')->constrained('advance_holders');
            $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 12)->default('operating');   // operating | payments
            $t->decimal('amount', 18, 2);
            $t->string('currency', 10)->nullable();
            $t->date('granted_at');
            $t->date('due_date')->nullable();
            $t->text('description')->nullable();
            $t->string('status', 12)->default('open');   // open | closed
            $t->decimal('refunded', 18, 2)->default(0);
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('advance_settlements', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->unique();
            $t->foreignId('holder_id')->constrained('advance_holders');
            $t->foreignId('advance_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('submitted');   // submitted | accepted | returned | settled
            $t->text('note')->nullable();
            $t->text('return_reason')->nullable();
            $t->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('advance_expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('holder_id')->constrained('advance_holders');
            $t->foreignId('advance_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->string('invoice_no', 80)->nullable();
            $t->date('invoice_date')->nullable();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->string('supplier_text')->nullable();
            $t->foreignId('budget_line_id')->nullable()->constrained()->nullOnDelete();
            $t->text('description');
            $t->decimal('amount', 18, 2);
            $t->string('currency', 10)->nullable();
            $t->string('grn', 5)->default('Yes');
            $t->string('status', 12)->default('draft');   // draft | submitted | in_jar | settled | returned
            $t->foreignId('settlement_id')->nullable()->constrained('advance_settlements')->nullOnDelete();
            $t->text('finance_note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('advance_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('advance_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40);
            $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 18, 2)->nullable();
            $t->text('description')->nullable();
            $t->string('status', 12)->default('pending');   // pending | paid
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        // ------------------------------------------------------------ received payments
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->unique();
            $t->date('date');
            $t->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->string('bank_ref', 80)->nullable();
            $t->boolean('bank_ref_auto')->default(false);
            $t->decimal('amount', 18, 2);
            $t->string('currency', 10);
            $t->string('payer')->nullable();
            $t->text('description')->nullable();
            $t->string('status', 20)->default('project');   // project | record | recorded
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('project_amount', 18, 2)->nullable();
            $t->string('project_currency', 10)->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->text('record_description')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('recorded_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('receipt_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 30);
            $t->text('message');
            $t->timestamps();
        });

        // ------------------------------------------------------------ attachments for the new modules
        Schema::table('attachments', function (Blueprint $t) {
            $t->unsignedBigInteger('document_id')->nullable()->change();
            $t->foreignId('expense_id')->nullable()->constrained('advance_expenses')->cascadeOnDelete();
            $t->foreignId('advance_payment_id')->nullable()->constrained('advance_payments')->cascadeOnDelete();
            $t->foreignId('receipt_id')->nullable()->constrained('receipts')->cascadeOnDelete();
            $t->string('kind', 30)->nullable();
        });
    }

    public function down(): void
    {
        // one-way upgrade (restore the database backup taken by the update script to go back)
    }
};
