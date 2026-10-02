<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_attribution_migration_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('idempotency_key', 160);
            $t->char('preview_checksum', 64);
            $t->char('booking_scope_checksum', 64);
            $t->unsignedInteger('requested_count');
            $t->unsignedInteger('created_count');
            $t->json('result_snapshot');
            $t->foreignUuid('applied_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('applied_at');
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key'], 'sales_attribution_migration_batch_key');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('sales_attribution_migration_batches');
    }
};
