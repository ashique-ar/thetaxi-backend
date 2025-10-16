<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicle_pricing_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('notification_type'); // price_change, bulk_operation, approval_required
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable(); // Additional notification data
            $table->uuid('related_id')->nullable(); // ID of related record (pricing history, bulk operation, etc.)
            $table->string('related_type')->nullable(); // Type of related record
            $table->uuid('user_id'); // User to notify
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->json('action_buttons')->nullable(); // Store action buttons configuration
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_read']);
            $table->index(['notification_type', 'priority']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_pricing_notifications');
    }
};
