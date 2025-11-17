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
        Schema::create('terms_and_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->enum('service_type', ['vehicle_rental', 'driver_service', 'general'])->nullable();
            $table->enum('payment_type', ['full', 'advance', 'quotation'])->nullable();
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_date')->nullable();
            $table->integer('display_order')->default(0);
            $table->string('created_user_id')->nullable();
            $table->string('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('service_type');
            $table->index('payment_type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms_and_conditions');
    }
};
