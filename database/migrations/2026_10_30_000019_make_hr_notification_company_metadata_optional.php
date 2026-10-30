<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_notification_outbox') && Schema::hasColumn('hr_notification_outbox', 'company_id')) {
            Schema::table('hr_notification_outbox', fn (Blueprint $table) => $table->uuid('company_id')->nullable()->change());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_notification_outbox') || ! Schema::hasColumn('hr_notification_outbox', 'company_id')) {
            return;
        }

        if (DB::table('hr_notification_outbox')->whereNull('company_id')->exists()) {
            throw new RuntimeException('Cannot restore required HR notification company IDs while installation-wide notifications exist.');
        }

        Schema::table('hr_notification_outbox', fn (Blueprint $table) => $table->uuid('company_id')->nullable(false)->change());
    }
};
