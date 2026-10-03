<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_tasks', function (Blueprint $table): void {
            $table->string('creation_idempotency_key', 160)->nullable()->unique('sales_task_creation_key_unique');
        });

        Schema::table('sales_task_events', function (Blueprint $table): void {
            $table->unsignedInteger('expected_version')->nullable()->after('to_status');
        });
    }

    public function down(): void
    {
        if (DB::table('sales_tasks')->whereNotNull('creation_idempotency_key')->exists()
            || DB::table('sales_task_events')->whereNotNull('expected_version')->exists()) {
            throw new RuntimeException('Cannot remove Sales Task replay evidence while task writes or transitions use it.');
        }

        Schema::table('sales_task_events', function (Blueprint $table): void {
            $table->dropColumn('expected_version');
        });
        Schema::table('sales_tasks', function (Blueprint $table): void {
            $table->dropUnique('sales_task_creation_key_unique');
            $table->dropColumn('creation_idempotency_key');
        });
    }
};
