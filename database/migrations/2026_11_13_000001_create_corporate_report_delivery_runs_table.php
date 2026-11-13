<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('corporate_report_delivery_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id');
            $table->uuid('schedule_id')->nullable();
            $table->uuid('schedule_owner_user_id')->nullable();
            $table->string('schedule_name');
            $table->string('format', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->json('filter_snapshot')->nullable();
            $table->json('recipient_snapshot');
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('artifact_bytes')->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();
            $table->foreign('corporate_id')->references('id')->on('corporates')->cascadeOnDelete();
            $table->index(['corporate_id', 'schedule_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_report_delivery_runs');
    }
};
