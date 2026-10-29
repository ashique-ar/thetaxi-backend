<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        if (DB::table('bookings')->where(function ($query): void {
            $query->whereNotNull('customer_feedback_submitted_at')
                ->orWhereNotNull('customer_feedback_response')
                ->orWhereNotNull('customer_feedback_responded_at')
                ->orWhereNotNull('customer_feedback_responded_by');
        })->exists()) {
            throw new \RuntimeException('Rollback refused: export and reconcile saved customer feedback tracking before removing it.');
        }

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
