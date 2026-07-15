<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_distance_pricing_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id');
            $table->string('name', 150);
            $table->boolean('is_default')->default(false);
            $table->string('default_service_mode', 20)->default('disabled');
            $table->text('origin_address');
            $table->decimal('origin_latitude', 10, 7);
            $table->decimal('origin_longitude', 10, 7);
            $table->text('return_address')->nullable();
            $table->decimal('return_latitude', 10, 7)->nullable();
            $table->decimal('return_longitude', 10, 7)->nullable();
            $table->boolean('include_origin_to_pickup')->default(true);
            $table->boolean('include_dropoff_to_return')->default(true);
            $table->string('movement_rate_method', 20)->default('normal_rate');
            $table->decimal('outbound_rate', 12, 2)->nullable();
            $table->decimal('return_rate', 12, 2)->nullable();
            $table->decimal('maximum_outbound_km', 10, 2)->nullable();
            $table->decimal('maximum_return_km', 10, 2)->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['corporate_id', 'is_default', 'is_active'], 'corp_distance_policy_default_idx');
            $table->index(['corporate_id', 'effective_from', 'effective_until'], 'corp_distance_policy_effective_idx');
        });

        Schema::create('corporate_service_distance_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id');
            $table->uuid('service_type_id');
            $table->uuid('policy_id')->nullable();
            $table->string('application_mode', 20)->default('inherit');
            $table->json('origin_location_override')->nullable();
            $table->json('return_location_override')->nullable();
            $table->boolean('include_origin_to_pickup')->nullable();
            $table->boolean('include_dropoff_to_return')->nullable();
            $table->string('movement_rate_method', 20)->nullable();
            $table->decimal('outbound_rate', 12, 2)->nullable();
            $table->decimal('return_rate', 12, 2)->nullable();
            $table->decimal('maximum_outbound_km', 10, 2)->nullable();
            $table->decimal('maximum_return_km', 10, 2)->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['corporate_id', 'service_type_id', 'is_active'], 'corp_service_distance_scope_idx');
            $table->index(['policy_id', 'is_active'], 'corp_service_distance_policy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_service_distance_policies');
        Schema::dropIfExists('corporate_distance_pricing_policies');
    }
};
