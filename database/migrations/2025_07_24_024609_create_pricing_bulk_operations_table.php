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
        Schema::create('vehicle_pricing_bulk_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('operation_type'); // bulk_update, bulk_import, bulk_copy
            $table->string('operation_name');
            $table->text('description')->nullable();
            $table->json('operation_data'); // Store operation parameters
            $table->json('affected_records'); // IDs of affected records
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed', 'cancelled']);
            $table->integer('total_records')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('successful_records')->default(0);
            $table->integer('failed_records')->default(0);
            $table->json('errors')->nullable(); // Store any errors
            $table->uuid('initiated_by');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'operation_type']);
            $table->index(['initiated_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_pricing_bulk_operations');
    }
};
