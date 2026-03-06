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
        Schema::dropIfExists('short_url_clicks');
        Schema::create('short_url_clicks', function (Blueprint $table) {
            $table->uuid()->primary();
            $table->uuid('shortened_url_id')->nullable();
            
            // Request information
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referer')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            
            // Device/Browser information
            $table->string('device_type')->nullable(); // mobile, desktop, tablet
            $table->string('browser')->nullable();
            $table->string('platform')->nullable(); // iOS, Android, Windows, etc.
            
            // Timing information
            $table->timestamp('clicked_at');
            $table->unsignedInteger('response_time_ms')->nullable(); // Time to redirect
            
            // Additional context
            $table->json('utm_parameters')->nullable(); // UTM tracking parameters
            $table->json('additional_data')->nullable(); // Any extra tracking data
            
            $table->timestamps();
            $table->softDeletes();


            // Indexes for analytics queries
            $table->index(['shortened_url_id', 'clicked_at']);
            $table->index(['ip_address', 'clicked_at']);
            $table->index(['device_type', 'clicked_at']);
            $table->index(['country', 'clicked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('short_url_clicks');
    }
};