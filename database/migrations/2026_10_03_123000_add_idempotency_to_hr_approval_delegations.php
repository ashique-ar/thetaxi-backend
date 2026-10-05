<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_approval_delegations') || Schema::hasColumn('hr_approval_delegations', 'idempotency_key')) {
            return;
        }

        Schema::table('hr_approval_delegations', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable();
            $table->char('request_payload_checksum', 64)->nullable();
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            Schema::table('hr_approval_delegations', function (Blueprint $table): void {
                $table->unique(['company_id', 'idempotency_key'], 'hr_delegations_company_idempotency_unique');
            });
        } elseif (in_array($driver, ['sqlsrv', 'pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX hr_delegations_company_idempotency_unique ON hr_approval_delegations (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hr_approval_delegations', 'idempotency_key')) {
            return;
        }

        if (DB::table('hr_approval_delegations')->whereNotNull('idempotency_key')->exists()) {
            throw new RuntimeException('Cannot roll back delegation idempotency while requests reference its keys.');
        }

        Schema::table('hr_approval_delegations', function (Blueprint $table): void {
            $table->dropUnique('hr_delegations_company_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_payload_checksum']);
        });
    }
};
