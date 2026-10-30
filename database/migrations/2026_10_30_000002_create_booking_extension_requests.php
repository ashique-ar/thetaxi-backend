<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_extension_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignUuid('booking_item_id')->constrained('booking_items')->cascadeOnDelete();
            $table->timestamp('current_end_at');
            $table->timestamp('requested_end_at');
            $table->string('reason', 40);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->decimal('additional_charge', 12, 2)->nullable();
            $table->string('charge_currency', 3)->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->index(['company_id', 'status', 'created_at'], 'booking_extension_queue_idx');
            $table->index(['booking_item_id', 'status'], 'booking_extension_item_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extension_requests');
    }
};
