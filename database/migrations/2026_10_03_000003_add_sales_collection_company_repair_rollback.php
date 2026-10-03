<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_collection_company_repairs', function (Blueprint $table): void {
            $table->char('before_checksum', 64)->nullable();
            $table->char('after_checksum', 64)->nullable();
            $table->unsignedInteger('request_record_count')->nullable();
        });

        Schema::create('sales_collection_company_repair_rollbacks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignUuid('evidence_file_id')->constrained('domain_evidence_files')->restrictOnDelete();
            $table->text('reason');
            $table->char('preview_checksum', 64);
            $table->char('request_checksum', 64);
            $table->char('idempotency_hash', 64);
            $table->unsignedInteger('record_count');
            $table->foreignUuid('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('rolled_back_at');
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_hash'], 'sales_collection_company_rollback_key_unique');
            $table->index(['booking_id', 'rolled_back_at'], 'sales_collection_company_rollback_booking_idx');
        });

        Schema::create('sales_collection_company_repair_rollback_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('rollback_id')->constrained('sales_collection_company_repair_rollbacks')->restrictOnDelete();
            $table->foreignUuid('repair_id')->constrained('sales_collection_company_repairs')->restrictOnDelete();
            $table->string('source_table', 80);
            $table->uuid('source_record_id');
            $table->char('before_checksum', 64);
            $table->char('after_checksum', 64);
            $table->timestamps();
            $table->unique('repair_id', 'sales_collection_company_repair_rollback_once_unique');
            $table->index(['rollback_id', 'source_table', 'source_record_id'], 'sales_collection_company_rollback_source_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('sales_collection_company_repairs')->exists()
            || DB::table('sales_collection_company_repair_rollbacks')->exists()) {
            throw new RuntimeException('Collection company repair and rollback evidence is retained; this migration cannot be rolled back.');
        }

        Schema::dropIfExists('sales_collection_company_repair_rollback_items');
        Schema::dropIfExists('sales_collection_company_repair_rollbacks');
        Schema::table('sales_collection_company_repairs', function (Blueprint $table): void {
            $table->dropColumn(['before_checksum', 'after_checksum', 'request_record_count']);
        });
    }
};
