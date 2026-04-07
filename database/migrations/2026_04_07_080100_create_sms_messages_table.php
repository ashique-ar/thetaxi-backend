<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('channel')->default('single')->index();
            $table->string('context_type')->nullable()->index();
            $table->uuid('context_id')->nullable()->index();
            $table->string('template_key')->nullable()->index();
            $table->string('recipient');
            $table->string('normalized_recipient')->index();
            $table->string('sender_mask')->nullable();
            $table->text('message');
            $table->string('status')->default('pending')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('provider_campaign_id')->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->json('provider_response')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table
                ->foreign('campaign_id')
                ->references('id')
                ->on('sms_campaigns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
