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
        Schema::create('payment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->nullable()->index();
            $table->string('booking_number')->nullable()->index();
            $table->string('event_type'); // callback_received, verification_result, payment_success, payment_failed, unmatched_callback, etc.
            $table->string('source')->nullable(); // e.g., webxpay
            $table->string('transaction_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->json('payload')->nullable();
            $table->text('message')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};