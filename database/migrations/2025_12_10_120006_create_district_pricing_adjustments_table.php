<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Captures district-level percentage adjustments and availability flags.
     * Keeps adjustments separate from packages so managers can tune per district.
     */
    public function up(): void
    {
        Schema::create('district_pricing_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('district_id')->index()->comment('FK to districts/states');
            $table->uuid('service_type_id')->index();
            $table->uuid('service_package_id')->nullable()->index();
            $table->uuid('vehicle_group_id')->nullable()->index();
            $table->decimal('percentage_change', 8, 4)->default(0)->comment('0.10 = +10%, -0.05 = -5%');
            $table->boolean('is_available')->default(true)->comment('If false, show Request Quotation');
            $table->boolean('request_quote')->default(false)->comment('Force request quotation even if available vehicles exist');
            $table->integer('priority')->default(0)->comment('Higher priority wins when multiple rules match');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['district_id', 'service_type_id', 'is_active']);
            $table->index(['effective_from', 'effective_to']);
            $table->index(['priority', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('district_pricing_adjustments');
    }
};
