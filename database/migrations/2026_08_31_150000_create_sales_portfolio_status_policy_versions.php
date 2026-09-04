<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_portfolio_status_policy_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('active_booking_statuses');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 30)->default('draft');
            $table->text('reason');
            $table->string('idempotency_key', 160);
            $table->char('request_checksum', 64);
            $table->foreignUuid('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('approval_idempotency_key', 160)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'version'], 'sales_portfolio_status_policy_version_unique');
            $table->unique(['company_id', 'idempotency_key'], 'sales_portfolio_status_policy_command_unique');
            $table->unique(['company_id', 'approval_idempotency_key'], 'sales_portfolio_status_policy_approval_unique');
            $table->index(['company_id', 'status', 'effective_from', 'effective_until'], 'sales_portfolio_status_policy_effective_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_portfolio_status_policy_versions ADD CONSTRAINT sales_portfolio_status_policy_status CHECK (status IN ('draft', 'approved', 'superseded'))");
            DB::statement('ALTER TABLE sales_portfolio_status_policy_versions ADD CONSTRAINT sales_portfolio_status_policy_checker CHECK (approved_by IS NULL OR approved_by <> prepared_by)');
            DB::statement('ALTER TABLE sales_portfolio_status_policy_versions ADD CONSTRAINT sales_portfolio_status_policy_period CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_portfolio_status_policy_versions')
            && DB::table('sales_portfolio_status_policy_versions')->exists()) {
            throw new RuntimeException('Refusing to drop retained Sales portfolio status-policy evidence.');
        }

        Schema::dropIfExists('sales_portfolio_status_policy_versions');
    }
};
