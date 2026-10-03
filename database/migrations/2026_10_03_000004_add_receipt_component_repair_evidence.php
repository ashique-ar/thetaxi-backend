<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_receipt_components', function (Blueprint $table): void {
            $table->string('repair_idempotency_key', 160)->nullable()->unique('receipt_component_repair_key_unique');
            $table->char('repair_request_checksum', 64)->nullable();
            $table->char('repair_before_checksum', 64)->nullable();
            $table->foreignUuid('repair_evidence_file_id')->nullable()
                ->constrained('domain_evidence_files')->restrictOnDelete();
            $table->text('repair_reason')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_payment_receipt_components')
            && DB::table('booking_payment_receipt_components')->whereNotNull('repair_idempotency_key')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile receipt component repair evidence first.');
        }

        Schema::table('booking_payment_receipt_components', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('repair_evidence_file_id');
            $table->dropUnique('receipt_component_repair_key_unique');
            $table->dropColumn([
                'repair_idempotency_key', 'repair_request_checksum', 'repair_before_checksum', 'repair_reason',
            ]);
        });
    }
};
