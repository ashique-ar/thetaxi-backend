<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_generated_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('report_type', 50)->default('operational');
            $table->string('template_id')->nullable();
            $table->string('format', 20)->default('csv');
            $table->string('status', 20)->default('ready')->index();
            $table->json('filters')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('size', 40)->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_generated_reports');
    }
};
