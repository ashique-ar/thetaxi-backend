<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('message');
            $table->string('provider')->nullable();
            $table->string('sender_mask')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('audience_type')->default('manual');
            $table->json('audience_filters')->nullable();
            $table->json('recipient_snapshot')->nullable();
            $table->string('provider_campaign_id')->nullable()->index();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('queued_recipients')->default(0);
            $table->unsignedInteger('sent_recipients')->default(0);
            $table->unsignedInteger('delivered_recipients')->default(0);
            $table->unsignedInteger('failed_recipients')->default(0);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_campaigns');
    }
};
