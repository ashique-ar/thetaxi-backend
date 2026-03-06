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
        Schema::create('booking_form_tabs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique(); // e.g., 'airport_transfers', 'ride_now'
            $table->string('label'); // Display name
            $table->string('service_type_code')->nullable(); // Link to service_types table
            $table->integer('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->string('icon_type')->default('svg'); // 'svg', 'icon-class', 'image'
            $table->text('icon_data')->nullable(); // SVG path or icon class
            $table->json('metadata')->nullable(); // Additional config
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('sort_order');
            $table->index('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_form_tabs');
    }
};
