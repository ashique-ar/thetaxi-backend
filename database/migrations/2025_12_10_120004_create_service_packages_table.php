<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores reusable packages (e.g., 100km, 200km) per service type.
     * Packages stay separate from vehicle pricing slabs so they can be reused across districts.
     */
    public function up(): void
    {
        Schema::create('service_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id')->index()->comment('Service type the package belongs to');
            $table->string('name');
            $table->string('code')->nullable()->comment('Optional unique code for API/front-end use');
            $table->text('description')->nullable();
            $table->decimal('included_km', 10, 2)->nullable()->comment('KM allowance included in the package');
            $table->decimal('price_multiplier', 8, 4)->default(1)->comment('Package-level percentage markup/discount (1 = no change)');
            $table->enum('rate_type', ['per_package', 'per_day', 'per_hour'])->default('per_package');
            $table->integer('default_duration_hours')->nullable()->comment('Optional default duration used for duration-based formulas');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['service_type_id', 'code']);
            $table->index(['service_type_id', 'is_active']);
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_packages');
    }
};
