<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('booking_common_rate_pricings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('common_rate_definition_id');
            $table->uuid('vehicle_group_common_rate_pricing_id')->nullable();

            $table->decimal('calculated_amount', 14, 2)->default(0);
            $table->string('rate_type')->nullable();
            $table->decimal('applied_rate', 14, 2)->default(0);
            $table->decimal('calculation_base', 14, 2)->nullable();
            $table->boolean('is_mandatory')->default(false);

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['booking_id']);
            $table->index(['common_rate_definition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_common_rate_pricings');
    }
};
