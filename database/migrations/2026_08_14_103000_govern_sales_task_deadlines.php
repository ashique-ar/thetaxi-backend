<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_tasks', function (Blueprint $table) {
            $table->string('deadline_contract_version', 30)->nullable()->after('escalate_at');
            $table->json('deadline_snapshot')->nullable()->after('deadline_contract_version');
            $table->char('deadline_checksum', 64)->nullable()->after('deadline_snapshot');
            $table->index(['company_id', 'deadline_contract_version', 'due_at'], 'sales_task_deadline_contract_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_tasks', 'deadline_checksum')
            && DB::table('sales_tasks')->whereNotNull('deadline_checksum')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile governed Sales task deadline evidence first.');
        }

        Schema::table('sales_tasks', function (Blueprint $table) {
            $table->dropIndex('sales_task_deadline_contract_idx');
            $table->dropColumn(['deadline_contract_version', 'deadline_snapshot', 'deadline_checksum']);
        });
    }
};
