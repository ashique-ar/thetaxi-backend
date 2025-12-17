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
        Schema::dropIfExists('service_packages');
        Schema::create('service_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->decimal('max_km_per_day', 10, 2)->nullable();
            $table->decimal('max_km_per_package', 10, 2)->nullable();
            $table->decimal('price_multiplier', 8, 4)->default(1.0000);
            $table->enum('rate_type', ['flat', 'hourly', 'daily'])->default('flat');
            $table->integer('default_duration_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('service_type_id')->references('id')->on('service_types')->onDelete('cascade');
            $table->index(['service_type_id', 'is_active']);
            $table->index('code');
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
