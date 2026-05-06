<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicle_group_service_pricing_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_group_id');
            $table->uuid('service_type_id');
            $table->boolean('is_inquiry_only')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['vehicle_group_id', 'service_type_id'], 'vg_service_pricing_settings_unique');
            $table->index(['service_type_id', 'is_hidden']);
            $table->index(['vehicle_group_id', 'is_hidden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_group_service_pricing_settings');
    }
};
