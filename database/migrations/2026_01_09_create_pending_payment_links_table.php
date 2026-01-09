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
        Schema::create('pending_payment_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->nullable()->index();
            $table->string('token', 255)->unique()->index();
            $table->enum('type', ['payment_reminder', 'quotation_conversion', 'retry'])->default('payment_reminder');

            // Booking context stored as JSON for full recovery
            $table->json('booking_context')->nullable();

            // Payment tracking
            $table->decimal('amount_due', 12, 2)->nullable();

            // Link validity and access tracking
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('accessed_at')->nullable();
            $table->integer('access_count')->default(0);
            $table->timestamp('invalidated_at')->nullable()->index();

            // Audit fields
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_payment_links');
    }
};
