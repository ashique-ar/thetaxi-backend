<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_assignment_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('assignment_id')->index();
            $table->uuid('driver_id')->index();
            $table->uuid('database_notification_id')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_channel')->nullable();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->string('acknowledgement_source')->nullable();
            $table->timestamp('fallback_due_at')->nullable()->index();
            $table->timestamp('fallback_checked_at')->nullable();
            $table->string('fallback_result')->nullable()->index();
            $table->uuid('fallback_sms_message_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['assignment_id', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_assignment_notifications');
    }
};
