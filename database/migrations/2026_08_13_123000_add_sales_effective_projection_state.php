<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_booking_attribution_events', function (Blueprint $table) {
            $table->timestamp('projected_at')->nullable()->after('effective_at');
            $table->index(
                ['event_type', 'projected_at', 'effective_at'],
                'sales_attribution_event_projection_idx'
            );
        });

        Schema::table('sales_profile_events', function (Blueprint $table) {
            $table->timestamp('projected_at')->nullable()->after('occurred_at');
            $table->index(
                ['event_type', 'projected_at', 'occurred_at'],
                'sales_profile_event_projection_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales_profile_events', function (Blueprint $table) {
            $table->dropIndex('sales_profile_event_projection_idx');
            $table->dropColumn('projected_at');
        });

        Schema::table('sales_booking_attribution_events', function (Blueprint $table) {
            $table->dropIndex('sales_attribution_event_projection_idx');
            $table->dropColumn('projected_at');
        });
    }
};
