<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_receipts', function (Blueprint $table): void {
            $table->char('legacy_repair_request_checksum', 64)->nullable();
            $table->char('legacy_repair_before_checksum', 64)->nullable();
            $table->foreignUuid('legacy_repair_evidence_file_id')->nullable()
                ->constrained('domain_evidence_files')->restrictOnDelete();
            $table->text('legacy_repair_reason')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_payment_receipts')
            && DB::table('booking_payment_receipts')->whereNotNull('legacy_repair_evidence_file_id')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile legacy receipt repair evidence first.');
        }

        Schema::table('booking_payment_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legacy_repair_evidence_file_id');
            $table->dropColumn(['legacy_repair_request_checksum', 'legacy_repair_before_checksum', 'legacy_repair_reason']);
        });
    }
};
