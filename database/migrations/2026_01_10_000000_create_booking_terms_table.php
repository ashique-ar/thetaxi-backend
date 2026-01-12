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
        Schema::create('booking_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id'); // Fixed: should be uuid to match bookings table
            $table->unsignedBigInteger('terms_and_condition_id');
            $table->unsignedInteger('terms_version')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['booking_id','terms_and_condition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_terms');
    }
};