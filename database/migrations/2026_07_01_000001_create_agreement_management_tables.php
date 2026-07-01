<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type')->default('general');
            $table->text('description')->nullable();
            $table->longText('content');
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('template_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('priority')->default('medium');
            $table->longText('content')->nullable();
            $table->longText('terms')->nullable();
            $table->json('parties')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedInteger('renewal_period')->nullable();
            $table->string('status')->default('draft');
            $table->string('signature_status')->default('unsigned');
            $table->json('signatures')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agreement_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agreement_id')->index();
            $table->string('type');
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_activities');
        Schema::dropIfExists('agreements');
        Schema::dropIfExists('agreement_templates');
    }
};
