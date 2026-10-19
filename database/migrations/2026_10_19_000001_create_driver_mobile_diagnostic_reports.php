<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_mobile_diagnostic_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_id')->unique();
            $table->foreignUuid('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('severity', 20);
            $table->string('category', 40);
            $table->string('source', 100)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->string('error_type', 200)->nullable();
            $table->text('error_summary')->nullable();
            $table->json('context')->nullable();
            $table->json('events')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['driver_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_mobile_diagnostic_reports');
    }
};
