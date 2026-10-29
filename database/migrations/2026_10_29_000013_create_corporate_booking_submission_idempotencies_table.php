<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_booking_submission_idempotencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('corporate_id')->constrained('corporates')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['corporate_id', 'actor_user_id', 'idempotency_key'], 'corporate_booking_submission_key_unique');
            $table->index('booking_id', 'corporate_booking_submission_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_booking_submission_idempotencies');
    }
};
