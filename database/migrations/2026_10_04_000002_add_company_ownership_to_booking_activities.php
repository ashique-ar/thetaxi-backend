<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_activities', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index(['company_id', 'event_at'], 'booking_activities_company_event_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('booking_activities')->whereNotNull('company_id')->exists()) {
            throw new RuntimeException('Cannot remove company ownership from retained booking activities.');
        }

        Schema::table('booking_activities', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex('booking_activities_company_event_idx');
            $table->dropColumn('company_id');
        });
    }
};
