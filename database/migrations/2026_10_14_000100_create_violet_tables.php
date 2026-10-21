<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- master data
        Schema::create('currencies', function (Blueprint $t) {
            $t->id();
            $t->string('code', 10)->unique();
            $t->string('name')->nullable();
            $t->integer('sequence')->default(10);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('list_items', function (Blueprint $t) {
            $t->id();
            $t->string('category', 40)->index();
            $t->string('name');
            $t->integer('sequence')->default(10);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('partners', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('code')->unique();
            $t->string('name');
            $t->string('qb_name')->nullable();
            $t->foreignId('currency_id')->nullable();
            $t->date('end_date')->nullable();
            $t->foreignId('partner_id')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->string('category')->nullable();
            $t->string('sub_category')->nullable();
            $t->text('english_description')->nullable();
            $t->foreignId('currency_id')->nullable();
            $t->string('trac', 40)->nullable()->index();
            $t->string('tr_account_name')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('accounting_centers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('master_name')->nullable();
            $t->string('related_project')->nullable();
            $t->string('project_name')->nullable();
            $t->string('location')->nullable();
            $t->timestamps();
        });
        Schema::create('cost_centers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->foreignId('accounting_center_id')->nullable();
            $t->foreignId('currency_id')->nullable();
            $t->string('location')->nullable();
            $t->timestamps();
        });
        Schema::create('budget_lines', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('project_code', 20)->index();
            $t->string('accounting_center_code', 20);
            $t->string('budget_line', 40);
            $t->string('sub_line', 40)->nullable();
            $t->foreignId('project_id')->nullable()->index();
            $t->foreignId('accounting_center_id')->nullable();
            $t->foreignId('expense_account_id')->nullable();
            $t->foreignId('income_account_id')->nullable();
            $t->text('description')->nullable();
            $t->decimal('total_amount', 20, 2)->nullable();
            $t->text('budget_category')->nullable();
            $t->string('remaining')->nullable();
            $t->boolean('active')->default(true);
            // values derived like the formula columns of the Master sheet
            $t->string('project_name')->nullable();
            $t->string('location')->nullable();
            $t->string('accounting_center_name')->nullable();
            $t->string('partner_name')->nullable();
            $t->string('turkish_account')->nullable();
            $t->string('parent_bl')->nullable();
            $t->string('qb_name')->nullable();
            $t->timestamps();
        });
        Schema::create('individuals', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->string('name_ar')->nullable();
            $t->string('title')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('iban')->nullable();
            $t->string('contract_number')->nullable();
            $t->string('location')->nullable();
            $t->string('mustari_numrasi')->nullable();
            $t->string('cc_code')->nullable();
            $t->string('cc_name')->nullable();
            $t->string('turkish_name')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->string('name_ar')->nullable();
            $t->string('trade_name')->nullable();
            $t->string('bank_account_name')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('iban')->nullable();
            $t->string('currency', 100)->nullable();
            $t->string('account_no')->nullable();
            $t->string('location')->nullable();
            $t->string('mistari_numrasi')->nullable();
            $t->string('contract_number')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // ---------------------------------------------------------------- numbers
        Schema::create('txn_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->integer('sequence')->default(10);
            $t->string('form_code', 30)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('txn_counters', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('year')->unique();
            $t->unsignedInteger('next_number')->default(1);
            $t->timestamps();
        });
        Schema::create('reservations', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->unique();
            $t->date('date');
            $t->foreignId('txn_type_id');
            $t->foreignId('project_id')->nullable();
            $t->foreignId('cost_center_id')->nullable();
            $t->string('description');
            $t->foreignId('user_id')->index();
            $t->string('state', 20)->default('reserved')->index();
            $t->foreignId('document_id')->nullable()->index();
            $t->string('used_for', 40)->nullable();
            $t->timestamps();
        });

        // ---------------------------------------------------------------- forms management
        Schema::create('form_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->string('description')->nullable();
            $t->integer('sequence')->default(10);
            $t->string('color', 20)->default('#6B2FA0');
            $t->string('icon', 40)->default('fa-file-lines');
            $t->timestamps();
        });
        Schema::create('form_numbers', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40);
            $t->string('form_name')->nullable();
            $t->string('release_no', 20)->nullable();
            $t->date('release_date')->nullable();
            $t->string('release_label', 40)->nullable();
            $t->timestamps();
        });
        Schema::create('form_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_type_id')->index();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->integer('sequence')->default(10);
        });
        Schema::create('form_roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_type_id')->index();
            $t->integer('sequence')->default(10);
            $t->string('name');
            $t->foreignId('slot_id')->nullable();
            $t->boolean('is_preparer')->default(false);
            $t->boolean('is_payer')->default(false);
            $t->boolean('has_date')->default(true);
            $t->boolean('required')->default(true);
            $t->foreignId('default_individual_id')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('form_prints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_type_id')->index();
            $t->integer('sequence')->default(10);
            $t->string('name');
            $t->string('sheet', 60)->unique();
            $t->string('report', 40);
            $t->boolean('active')->default(true);
            $t->foreignId('form_number_id')->nullable();
            $t->boolean('show_header')->default(true);
            $t->text('header_left')->nullable();
            $t->text('header_center')->nullable();
            $t->boolean('show_logo')->default(true);
            $t->longText('logo')->nullable();
            $t->text('footer_left')->nullable();
            $t->text('footer_center')->nullable();
            $t->text('footer_right')->nullable();
            $t->timestamps();
        });
        Schema::create('form_labels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_print_id')->index();
            $t->integer('sequence')->default(10);
            $t->string('cell', 12);
            $t->string('kind', 10)->default('label');
            $t->text('default_text')->nullable();
            $t->text('text')->nullable();
            $t->boolean('hidden')->default(false);
            $t->timestamps();
            $t->unique(['form_print_id', 'cell']);
        });
        Schema::create('form_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_type_id')->index();
            $t->integer('sequence')->default(10);
            $t->string('key', 40);
            $t->string('name');
            $t->string('ftype', 20)->default('char');
            $t->text('options')->nullable();
            $t->text('help')->nullable();
            $t->boolean('required')->default(false);
            $t->boolean('printed')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('form_field_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_type_id')->index();
            $t->string('scope', 40)->default('form');
            $t->string('field_key', 60);
            $t->string('label')->nullable();
            $t->text('help')->nullable();
            $t->boolean('hidden')->default(false);
            $t->timestamps();
            $t->unique(['form_type_id', 'scope', 'field_key']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->longText('value')->nullable();
        });

        // ---------------------------------------------------------------- documents
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('form_code', 30)->index();
            $t->string('number', 20)->nullable()->unique();
            $t->foreignId('reservation_id')->nullable();
            $t->date('date');
            $t->foreignId('user_id')->index();
            $t->string('state', 20)->default('draft')->index();
            $t->text('description')->nullable();
            $t->decimal('amount', 20, 2)->nullable();
            $t->string('currency', 10)->nullable();
            $t->longText('data')->nullable();
            $t->longText('extra')->nullable();
            $t->foreignId('current_signer_id')->nullable()->index();
            $t->foreignId('paid_reservation_id')->nullable();
            $t->string('paid_ref', 20)->nullable();
            $t->decimal('paid_rate', 20, 7)->nullable();
            $t->decimal('paid_amount', 20, 2)->nullable();
            $t->date('paid_date')->nullable();
            $t->boolean('uploaded')->default(false);
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->timestamps();
        });
        Schema::create('document_signatures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->index();
            $t->integer('sequence')->default(10);
            $t->string('role');
            $t->string('slot_code', 40)->nullable();
            $t->boolean('is_preparer')->default(false);
            $t->boolean('is_payer')->default(false);
            $t->boolean('has_date')->default(true);
            $t->boolean('required')->default(false);
            $t->foreignId('individual_id')->nullable();
            $t->string('state', 20)->default('pending');
            $t->longText('signature')->nullable();
            $t->foreignId('signed_by')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->date('date')->nullable();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->index();
            $t->string('name');
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size')->default(0);
            $t->foreignId('user_id');
            $t->timestamps();
        });
        Schema::create('document_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->string('type', 30)->default('note');
            $t->text('message');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['document_events', 'attachments', 'document_signatures', 'documents', 'settings', 'form_field_configs',
                  'form_fields', 'form_labels', 'form_prints', 'form_roles', 'form_slots', 'form_numbers', 'form_types',
                  'reservations', 'txn_counters', 'txn_types', 'suppliers', 'individuals', 'budget_lines', 'cost_centers',
                  'accounting_centers', 'accounts', 'projects', 'partners', 'list_items', 'currencies'] as $tb) {
            Schema::dropIfExists($tb);
        }
    }
};
