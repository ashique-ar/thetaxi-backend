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
        Schema::dropIfExists('shortened_urls');
        Schema::create('shortened_urls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('short_code', 10)->unique()->index();
            $table->text('original_url');
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->string('created_by_type')->nullable(); // e.g., 'booking', 'user'
            $table->uuid('created_by_id')->nullable();
            
            // Analytics fields
            $table->string('source')->nullable(); // e.g., 'email', 'sms', 'web', 'api'
            $table->string('campaign')->nullable(); // e.g., 'payment_reminder', 'quotation_follow_up'
            $table->string('medium')->nullable(); // e.g., 'notification', 'marketing', 'transactional'
            $table->json('metadata')->nullable(); // Additional context data
            
            $table->timestamps();
            $table->softDeletes();

            // Index for cleanup queries
            $table->index(['expires_at', 'created_at']);
            // Index for finding existing URLs
            $table->index(['original_url', 'expires_at']);
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
        Schema::dropIfExists('shortened_urls');
    }
};