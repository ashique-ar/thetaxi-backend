<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_onboarding_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('mobile', 30)->index();
            $table->string('access_token_hash', 64)->unique();
            $table->timestamp('mobile_verified_at');
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->string('status', 30)->default('draft')->index();
            $table->json('payload')->nullable();
            $table->json('review_issues')->nullable();
            $table->text('review_message')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignUuid('onboarding_application_id')->nullable()->constrained('driver_onboarding_applications')->nullOnDelete();
            $table->unsignedSmallInteger('reminder_days')->default(30);
            $table->date('last_reminded_on')->nullable();
            $table->foreignUuid('replaces_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->json('metadata')->nullable();
        });

    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('onboarding_application_id');
            $table->dropConstrainedForeignId('replaces_document_id');
        });
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn(['reminder_days', 'last_reminded_on']));
        Schema::dropIfExists('driver_onboarding_applications');
    }
};
