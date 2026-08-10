<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create customer_devices table for storing rider mobile device
 * information, mirroring driver_devices so the same FCM push pipeline can be
 * reused to notify riders (e.g. when a driver is assigned to their booking).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->onDelete('cascade');

            // Device identification
            $table->string('device_uuid')->nullable()->comment('Unique device identifier from mobile app');
            $table->string('device_fingerprint', 500)->nullable()->comment('Device fingerprint for identification (hash of device characteristics)');
            $table->string('device_name')->nullable()->comment('User-friendly device name (e.g., "John\'s iPhone")');
            $table->string('device_model')->nullable()->comment('Device model (e.g., "iPhone 14 Pro", "Samsung Galaxy S23")');
            $table->string('device_manufacturer')->nullable()->comment('Device manufacturer (e.g., "Apple", "Samsung")');

            // Operating system
            $table->string('platform')->comment('OS platform: ios, android');
            $table->string('os_version')->nullable()->comment('OS version (e.g., "17.2", "14")');

            // App information
            $table->string('app_version')->nullable()->comment('Mobile app version (e.g., "1.0.0")');
            $table->string('app_build')->nullable()->comment('App build number');

            // Push notifications
            $table->text('push_token')->nullable()->comment('FCM/APNs push notification token');
            $table->string('push_provider')->nullable()->comment('Push provider: fcm, apns');

            // Status and activity
            $table->boolean('is_active')->default(true)->comment('Whether this device is currently active');
            $table->timestamp('last_active_at')->nullable()->comment('Last activity timestamp');
            $table->timestamp('registered_at')->useCurrent()->comment('When device was first registered');

            // Additional metadata
            $table->string('ip_address')->nullable()->comment('Last known IP address');
            $table->string('locale')->nullable()->comment('Device locale (e.g., "en_US", "si_LK")');
            $table->string('timezone')->nullable()->comment('Device timezone');
            $table->json('metadata')->nullable()->comment('Additional device metadata');

            $table->timestamps();
            $table->softDeletes();

            $table->index('device_uuid');
            $table->index('platform');
            $table->index('is_active');
            $table->index('last_active_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_devices');
    }
};
