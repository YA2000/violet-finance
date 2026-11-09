<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('reservations', 'pr_number')) {
            Schema::table('reservations', function (Blueprint $t) {
                $t->string('pr_number', 60)->nullable()->index();
            });
        }
        if (!Schema::hasColumn('txn_types', 'need_project')) {
            Schema::table('txn_types', function (Blueprint $t) {
                $t->boolean('need_project')->default(false);
                $t->boolean('need_cost_center')->default(false);
                $t->string('pr_field', 10)->default('off');   // off | optional | required
            });
        }
        if (!Schema::hasColumn('documents', 'edit_open')) {
            Schema::table('documents', function (Blueprint $t) {
                $t->boolean('edit_open')->default(false);
            });
        }
        \App\Support\Upgrade::run();
    }

    public function down(): void
    {
    }
};
