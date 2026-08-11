<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->nullable()->index();
            $table->uuid('booking_item_id')->nullable()->index();
            $table->uuid('driver_assignment_id')->nullable()->index();
            $table->uuid('inquiry_id')->nullable()->index();
            $table->uuid('sms_message_id')->nullable()->index();
            $table->string('event_key')->index();
            $table->string('channel')->default('sms')->index();
            $table->string('result_status')->index();
            $table->string('source')->default('automation')->index();
            $table->string('recipient_masked')->nullable();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('meta')->nullable();
            $table->timestamp('event_at')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->uuid('inquiry_id')->nullable()->index()->after('driver_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['inquiry_id']);
            $table->dropColumn('inquiry_id');
        });
        Schema::dropIfExists('booking_activities');
    }
};
