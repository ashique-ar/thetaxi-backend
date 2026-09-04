<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_profile_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('status_filter', 20)->nullable();
            $table->unsignedInteger('row_count');
            $table->string('disk', 40);
            $table->string('path', 1000);
            $table->string('file_name', 255);
            $table->char('file_checksum', 64);
            $table->unsignedBigInteger('file_size');
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('last_downloaded_at')->nullable();
            $table->foreignUuid('last_downloaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_profile_exports');
    }
};
