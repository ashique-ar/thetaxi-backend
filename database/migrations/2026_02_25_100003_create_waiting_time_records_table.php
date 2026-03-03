<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the waiting_time_records table for automatic stationary period detection.
     */
    public function up(): void
    {
        Schema::create('waiting_time_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('assignment_id');

            // Stationary period start
            $table->timestamp('start_time');
            $table->decimal('start_latitude', 10, 8);
            $table->decimal('start_longitude', 11, 8);

            // Stationary period end (nullable until movement resumes)
            $table->timestamp('end_time')->nullable();
            $table->decimal('end_latitude', 10, 8)->nullable();
            $table->decimal('end_longitude', 11, 8)->nullable();

            // Calculated duration
            $table->integer('duration_seconds')->nullable();

            $table->timestamps();
            $table->softDeletes();
            // Indexes
            $table->index('assignment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waiting_time_records');
    }
};
