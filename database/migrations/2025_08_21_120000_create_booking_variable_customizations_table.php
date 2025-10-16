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
        Schema::create('booking_variable_customizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->nullable();
            $table->string('session_id')->nullable(); // For draft customizations
            $table->string('variable_name');
            $table->string('variable_type'); // slab_rate, common_rate, fixed_value, etc.
            $table->decimal('original_value', 15, 2);
            $table->decimal('custom_value', 15, 2);
            $table->text('customization_reason')->nullable();
            $table->string('context'); // base_pricing, addon_pricing
            $table->json('metadata')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['booking_id', 'context']);
            $table->index(['session_id', 'context']);
            $table->index(['variable_name', 'variable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_variable_customizations');
    }
};
