<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicle_groups', function (Blueprint $table) {
            $table->boolean('is_inquiry_only')->default(false)->after('is_featured')
                ->comment('If true, this vehicle group requires quotation request instead of direct booking');
            $table->boolean('force_quotation_request')->default(false)->after('is_inquiry_only')
                ->comment('If true, force quotation request even if pricing is configured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_groups', function (Blueprint $table) {
            $table->dropColumn(['is_inquiry_only', 'force_quotation_request']);
        });
    }
};
