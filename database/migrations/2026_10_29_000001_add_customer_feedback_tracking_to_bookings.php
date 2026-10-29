<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('customer_feedback_submitted_at')->nullable();
            $table->text('customer_feedback_response')->nullable();
            $table->timestamp('customer_feedback_responded_at')->nullable();
            $table->foreignUuid('customer_feedback_responded_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_feedback_responded_by');
            $table->dropColumn([
                'customer_feedback_submitted_at',
                'customer_feedback_response',
                'customer_feedback_responded_at',
            ]);
        });
    }
};
