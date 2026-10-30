<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('phone_call_follow_up_attempts') && Schema::hasColumn('phone_call_follow_up_attempts', 'company_id')) {
            $duplicateIdempotencyKeys = DB::table('phone_call_follow_up_attempts')
                ->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->exists();
            if ($duplicateIdempotencyKeys) {
                throw new RuntimeException('Phone callback idempotency keys collide across companies; resolve the duplicate keys before removing company scope.');
            }
        }

        foreach (['phone_calls', 'phone_call_assignments', 'phone_call_follow_up_attempts'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                TenantIndexDropper::drop($tableName, $index);
                $columns = array_values(array_diff($index['columns'], ['company_id']));
                if ($columns === []) {
                    continue;
                }

                $unique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
                $replacementExists = collect(Schema::getIndexes($tableName))->contains(fn (array $existing): bool =>
                    $existing['columns'] === $columns
                    && (! $unique || ($existing['unique'] ?? false) || ($existing['type'] ?? null) === 'unique'));
                if ($replacementExists) {
                    continue;
                }

                $name = $tableName.'_installation_'.substr(sha1(implode('|', $columns)), 0, 12).($unique ? '_unique' : '_idx');
                Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
                    $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Phone call records cannot regain tenant company scope.');
    }
};
