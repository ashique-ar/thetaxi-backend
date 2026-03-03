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
        Schema::table('shortened_urls', function (Blueprint $table) {
            // Analytics fields
            $table->string('source')->nullable()->after('created_by_id'); // e.g., 'email', 'sms', 'web', 'api'
            $table->string('campaign')->nullable()->after('source'); // e.g., 'payment_reminder', 'quotation_follow_up'
            $table->string('medium')->nullable()->after('campaign'); // e.g., 'notification', 'marketing', 'transactional'
            $table->json('metadata')->nullable()->after('medium'); // Additional context data
            
            // Analytics indexes
            $table->index(['source', 'campaign']);
            $table->index(['created_by_type', 'created_by_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shortened_urls', function (Blueprint $table) {
            $table->dropIndex(['source', 'campaign']);
            $table->dropIndex(['created_by_type', 'created_by_id']);
            $table->dropColumn(['source', 'campaign', 'medium', 'metadata']);
        });
    }
};