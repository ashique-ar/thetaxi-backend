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
            $table->string('corporate_approval_status', 30)->default('not_required');
            $table->uuid('corporate_approval_by')->nullable();
            $table->timestamp('corporate_approval_at')->nullable();
            $table->text('corporate_approval_note')->nullable();
            $table->unsignedTinyInteger('corporate_approval_step')->default(0);
            $table->json('corporate_approval_history')->nullable();
            $table->index(['corporate_account_id', 'corporate_approval_status'], 'bookings_corp_approval_status_idx');
        });

        DB::table('bookings')->where('is_corporate_booking', true)->where('status', 'pending_approval')
            ->update(['corporate_approval_status' => 'pending']);
        DB::table('bookings')->where('is_corporate_booking', true)->where('status', '!=', 'pending_approval')
            ->update(['corporate_approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_corp_approval_status_idx');
            $table->dropColumn([
                'corporate_approval_status',
                'corporate_approval_by',
                'corporate_approval_at',
                'corporate_approval_note',
                'corporate_approval_step',
                'corporate_approval_history',
            ]);
        });
    }
};
