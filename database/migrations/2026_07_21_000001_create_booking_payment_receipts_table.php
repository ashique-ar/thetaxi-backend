<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_payment_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->index();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 50);
            $table->string('payment_stage', 50)->default('part_payment');
            $table->string('reference')->nullable();
            $table->timestamp('received_at');
            $table->uuid('received_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['booking_id', 'received_at'], 'booking_receipts_booking_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payment_receipts');
    }
};
