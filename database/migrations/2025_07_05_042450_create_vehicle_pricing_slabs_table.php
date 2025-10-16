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
        Schema::create('vehicle_pricing_slabs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id')->index();
            $table->integer('min_days');
            $table->integer('max_days');
            $table->decimal('base_rate', 12, 2);
            $table->enum('rate_type', ['flat', 'percentage'])->default('flat');
            $table->decimal('extra_rate', 12, 2)->nullable();
            $table->uuid('region_id')->nullable()->index();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_pricing_slabs');
    }
};
