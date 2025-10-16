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
        Schema::table('public.booking_approvals', function (Blueprint $table) {
            $table->uuid('processed_by')->nullable()->after('approver_id');
            $table->string('approval_type')->default('manager')->after('priority');
            $table->text('notes')->nullable()->after('comments');
            $table->json('conditions')->nullable()->after('notes');
            $table->timestamp('requested_at')->nullable()->after('conditions');
            $table->timestamp('processed_at')->nullable()->after('requested_at');

            $table->index('processed_by');
            $table->index('approval_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('public.booking_approvals', function (Blueprint $table) {
            $table->dropIndex(['processed_by']);
            $table->dropIndex(['approval_type']);
            
            $table->dropColumn([
                'processed_by',
                'approval_type', 
                'notes',
                'conditions',
                'requested_at',
                'processed_at'
            ]);
        });
    }
};
