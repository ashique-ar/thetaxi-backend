<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_target_versions', function (Blueprint $table) {
            $table->string('draft_idempotency_key', 160)->nullable()->after('reason');
            $table->char('draft_request_checksum', 64)->nullable()->after('draft_idempotency_key');
            $table->text('approval_reason')->nullable()->after('approved_at');
            $table->string('approval_idempotency_key', 160)->nullable()->after('approval_reason');
            $table->char('approval_request_checksum', 64)->nullable()->after('approval_idempotency_key');
            $table->unique(['company_id', 'draft_idempotency_key'], 'sales_target_draft_idem_unique');
            $table->unique(['company_id', 'approval_idempotency_key'], 'sales_target_approval_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_target_versions', function (Blueprint $table) {
            $table->dropUnique('sales_target_draft_idem_unique');
            $table->dropUnique('sales_target_approval_idem_unique');
            $table->dropColumn([
                'draft_idempotency_key', 'draft_request_checksum', 'approval_reason',
                'approval_idempotency_key', 'approval_request_checksum',
            ]);
        });
    }
};
