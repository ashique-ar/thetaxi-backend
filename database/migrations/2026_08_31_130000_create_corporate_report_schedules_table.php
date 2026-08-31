<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('corporate_report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id');
            $table->string('name');
            $table->string('format', 10)->default('pdf');
            $table->unsignedTinyInteger('delivery_day')->default(1);
            $table->json('recipients');
            $table->json('filters')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->foreign('corporate_id')->references('id')->on('corporates')->cascadeOnDelete();
            $table->unique(['corporate_id', 'name']);
            $table->index(['is_active', 'delivery_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_report_schedules');
    }
};
