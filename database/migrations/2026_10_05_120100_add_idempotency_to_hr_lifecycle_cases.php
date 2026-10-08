<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'hr_lifecycle_cases_company_idempotency_unique';

    public function up(): void
    {
        if (!Schema::hasTable('hr_lifecycle_cases') || Schema::hasColumn('hr_lifecycle_cases', 'idempotency_key')) {
            return;
        }

        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'sqlsrv', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver for lifecycle case idempotency.');
        }

        Schema::table('hr_lifecycle_cases', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable();
            $table->char('request_payload_checksum', 64)->nullable();
        });

        if ($driver === 'mysql') {
            Schema::table('hr_lifecycle_cases', fn (Blueprint $table) => $table->unique(['company_id', 'idempotency_key'], self::INDEX));
        } elseif ($driver === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON hr_lifecycle_cases (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        } else {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON hr_lifecycle_cases (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('hr_lifecycle_cases', 'idempotency_key')) {
            return;
        }
        if (DB::table('hr_lifecycle_cases')->whereNotNull('idempotency_key')->orWhereNotNull('request_payload_checksum')->exists()) {
            throw new RuntimeException('Cannot remove lifecycle case idempotency evidence while requests reference it.');
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            Schema::table('hr_lifecycle_cases', fn (Blueprint $table) => $table->dropUnique(self::INDEX));
        } elseif ($driver === 'sqlsrv') {
            DB::statement('DROP INDEX '.self::INDEX.' ON hr_lifecycle_cases');
        } else {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
        Schema::table('hr_lifecycle_cases', function (Blueprint $table): void {
            $table->dropColumn(['idempotency_key', 'request_payload_checksum']);
        });
    }
};
