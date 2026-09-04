<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_target_copy_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->date('source_period_start');
            $table->date('source_period_end');
            $table->date('target_period_start');
            $table->date('target_period_end');
            $table->json('selection_snapshot');
            $table->char('preview_checksum', 64);
            $table->text('reason');
            $table->foreignUuid('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('prepared_at');
            $table->string('idempotency_key', 160)->unique();
            $table->char('request_payload_checksum', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'target_period_start'], 'sales_target_copy_batch_period_idx');
        });

        Schema::table('sales_target_versions', function (Blueprint $table) {
            $table->foreignUuid('copy_batch_id')->nullable()->after('sales_profile_id')
                ->constrained('sales_target_copy_batches')->restrictOnDelete();
            $table->foreignUuid('copied_from_target_id')->nullable()->after('copy_batch_id')
                ->constrained('sales_target_versions')->restrictOnDelete();
            $table->unique(['copy_batch_id', 'sales_profile_id'], 'sales_target_copy_batch_profile_unique');
            $table->index('copied_from_target_id', 'sales_target_copied_from_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_target_copy_batches')
            && (DB::table('sales_target_copy_batches')->exists()
                || DB::table('sales_target_versions')->whereNotNull('copy_batch_id')->exists())) {
            throw new RuntimeException('Rollback refused: export and reconcile immutable Sales target copy lineage first.');
        }

        Schema::table('sales_target_versions', function (Blueprint $table) {
            $table->dropUnique('sales_target_copy_batch_profile_unique');
            $table->dropIndex('sales_target_copied_from_idx');
            $table->dropConstrainedForeignId('copied_from_target_id');
            $table->dropConstrainedForeignId('copy_batch_id');
        });
        Schema::dropIfExists('sales_target_copy_batches');
    }
};
