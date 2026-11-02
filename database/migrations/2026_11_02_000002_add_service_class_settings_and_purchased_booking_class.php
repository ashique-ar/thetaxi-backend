<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_type_id')->constrained('service_types')->restrictOnDelete();
            $table->foreignUuid('vehicle_class_id')->constrained('vehicle_classes')->restrictOnDelete();
            $table->unsignedSmallInteger('max_passengers');
            $table->unsignedSmallInteger('max_luggage');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_inquiry_only')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->json('allowed_upgrade_class_ids')->nullable();
            $table->json('allowed_substitute_class_ids')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['service_type_id', 'vehicle_class_id'], 'service_vehicle_class_unique');
        });
        Schema::table('service_packages', function (Blueprint $table): void {
            $table->foreignUuid('default_vehicle_class_id')->nullable()->constrained('vehicle_classes')->restrictOnDelete();
            $table->json('allowed_upgrade_class_ids')->nullable();
        });
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->foreignUuid('vehicle_class_id')->nullable()->constrained('vehicle_classes')->restrictOnDelete();
            $table->string('pricing_basis', 20)->default('vehicle_group');
            $table->uuid('vehicle_group_id')->nullable()->change();
            $table->index(['vehicle_class_id', 'from_date', 'to_date'], 'booking_items_class_window_idx');
        });
        Schema::table('vehicle_pricing_history', function (Blueprint $table): void {
            $table->foreignUuid('vehicle_class_id')->nullable()->constrained('vehicle_classes')->restrictOnDelete();
            $table->uuid('vehicle_group_id')->nullable()->change();
            $table->index(['vehicle_class_id', 'service_type_id'], 'pricing_history_class_service_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('booking_items')->where('pricing_basis', 'vehicle_class')->exists()
            || DB::table('service_packages')->whereNotNull('default_vehicle_class_id')->exists()
            || DB::table('service_vehicle_class_settings')->exists()
            || DB::table('vehicle_pricing_history')->whereNotNull('vehicle_class_id')->exists()) {
            throw new RuntimeException('Cannot remove purchased class settings or history while class records exist. Preserve accepted bookings.');
        }
        Schema::table('vehicle_pricing_history', function (Blueprint $table): void {
            $table->dropIndex('pricing_history_class_service_idx');
            $table->dropForeign(['vehicle_class_id']);
            $table->dropColumn('vehicle_class_id');
        });
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->dropIndex('booking_items_class_window_idx');
            $table->dropForeign(['vehicle_class_id']);
            $table->dropColumn(['vehicle_class_id', 'pricing_basis']);
        });
        Schema::table('service_packages', function (Blueprint $table): void {
            $table->dropForeign(['default_vehicle_class_id']);
            $table->dropColumn(['default_vehicle_class_id', 'allowed_upgrade_class_ids']);
        });
        Schema::drop('service_vehicle_class_settings');
    }
};
