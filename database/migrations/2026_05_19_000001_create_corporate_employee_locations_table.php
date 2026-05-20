<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_employee_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_employee_id');
            $table->string('label', 100)->default('Default');
            $table->text('address');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('place_id')->nullable();
            $table->boolean('is_default_pickup')->default(false);
            $table->boolean('is_default_dropoff')->default(false);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['corporate_employee_id', 'is_active']);
            $table->index(['is_default_pickup', 'is_default_dropoff']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_employee_locations');
    }
};
