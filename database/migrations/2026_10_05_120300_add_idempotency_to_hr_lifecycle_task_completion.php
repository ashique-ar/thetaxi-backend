<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'hr_lifecycle_tasks_idempotency_unique';

    public function up(): void
    {
        if (! Schema::hasTable('hr_lifecycle_tasks') || Schema::hasColumn('hr_lifecycle_tasks', 'idempotency_key')) return;
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlsrv', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver for lifecycle task completion idempotency.');
        }

        Schema::table('hr_lifecycle_tasks', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable();
            $table->char('completion_checksum', 64)->nullable();
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            Schema::table('hr_lifecycle_tasks', fn (Blueprint $table) => $table->unique('idempotency_key', self::INDEX));
        } else {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON hr_lifecycle_tasks (idempotency_key) WHERE idempotency_key IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hr_lifecycle_tasks', 'idempotency_key')) return;
        if (DB::table('hr_lifecycle_tasks')->whereNotNull('idempotency_key')->orWhereNotNull('completion_checksum')->exists()) {
            throw new RuntimeException('Cannot remove lifecycle completion evidence while requests reference it.');
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            Schema::table('hr_lifecycle_tasks', fn (Blueprint $table) => $table->dropUnique(self::INDEX));
        } elseif ($driver === 'sqlsrv') {
            DB::statement('DROP INDEX '.self::INDEX.' ON hr_lifecycle_tasks');
        } else {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
        Schema::table('hr_lifecycle_tasks', fn (Blueprint $table) => $table->dropColumn(['idempotency_key', 'completion_checksum']));
    }
};
