<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'hr_lifecycle_templates_company_idempotency_unique';

    public function up(): void
    {
        if (!Schema::hasTable('hr_lifecycle_templates') || Schema::hasColumn('hr_lifecycle_templates', 'idempotency_key')) {
            return;
        }

        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'sqlsrv', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver for lifecycle template idempotency.');
        }

        Schema::table('hr_lifecycle_templates', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable();
            $table->char('request_payload_checksum', 64)->nullable();
        });

        if ($driver === 'mysql') {
            Schema::table('hr_lifecycle_templates', fn (Blueprint $table) => $table->unique(['company_id', 'idempotency_key'], self::INDEX));
        } elseif ($driver === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON hr_lifecycle_templates (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        } else {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON hr_lifecycle_templates (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('hr_lifecycle_templates', 'idempotency_key')) {
            return;
        }
        if (DB::table('hr_lifecycle_templates')->whereNotNull('idempotency_key')->exists()) {
            throw new RuntimeException('Cannot remove lifecycle template idempotency evidence while requests reference it.');
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            Schema::table('hr_lifecycle_templates', fn (Blueprint $table) => $table->dropUnique(self::INDEX));
        } elseif ($driver === 'sqlsrv') {
            DB::statement('DROP INDEX '.self::INDEX.' ON hr_lifecycle_templates');
        } else {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
        Schema::table('hr_lifecycle_templates', function (Blueprint $table): void {
            $table->dropColumn(['idempotency_key', 'request_payload_checksum']);
        });
    }
};
