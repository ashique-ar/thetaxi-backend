<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->string('customer_display_name')->nullable();
            $table->json('eligible_vehicle_group_ids')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('service_vehicle_class_settings')
            ->whereNotNull('customer_display_name')
            ->orWhereNotNull('eligible_vehicle_group_ids')
            ->exists()) {
            throw new RuntimeException('Preserve Tours class labels and eligible fleet-group mappings before rolling back this migration.');
        }

        Schema::table('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->dropColumn(['customer_display_name', 'eligible_vehicle_group_ids']);
        });
    }
};
