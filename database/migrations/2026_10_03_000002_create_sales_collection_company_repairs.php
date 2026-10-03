<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_collection_company_repairs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('source_table', 80);
            $table->uuid('source_record_id');
            $table->foreignUuid('before_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('after_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('evidence_file_id')->constrained('domain_evidence_files')->restrictOnDelete();
            $table->text('reason');
            $table->char('preview_checksum', 64);
            $table->char('request_checksum', 64);
            $table->char('idempotency_hash', 64);
            $table->char('source_key_hash', 64);
            $table->foreignUuid('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('repaired_at');
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_hash', 'source_key_hash'], 'sales_collection_company_repair_key_unique');
            $table->index(['booking_id', 'repaired_at'], 'sales_collection_company_repair_booking_idx');
            $table->index(['source_table', 'source_record_id'], 'sales_collection_company_repair_source_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_collection_company_repairs')
            && DB::table('sales_collection_company_repairs')->exists()) {
            throw new RuntimeException('Collection company repair evidence is retained; this migration cannot be rolled back.');
        }

        Schema::dropIfExists('sales_collection_company_repairs');
    }
};
