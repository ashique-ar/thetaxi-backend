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
        foreach (['hr_notification_template_versions', 'hr_notification_preferences', 'hr_notification_outbox'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['primary'] ?? false) || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary') {
                    continue;
                }

                TenantIndexDropper::drop($tableName, $index);
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        if (Schema::hasTable('hr_notification_template_versions')) {
            $duplicateVersions = DB::table('hr_notification_template_versions')
                ->select('code', 'version')->groupBy('code', 'version')->havingRaw('COUNT(*) > 1')->exists();
            $this->createIndex(
                'hr_notification_template_versions',
                ['code', 'version'],
                $duplicateVersions ? 'hr_notification_template_code_version_idx' : 'hr_notification_template_code_version_unique',
                ! $duplicateVersions
            );
            $this->createIndex('hr_notification_template_versions', ['event_type', 'channel', 'status'], 'hr_notification_template_delivery_lookup_idx');
        }
    }

    private function createIndex(string $tableName, array $columns, string $baseName, bool $unique = false): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($index['columns'] === $columns && (! $unique || $isUnique)) {
                return;
            }
        }

        $name = $baseName;
        for ($suffix = 1; $this->relationExists($name, $tableName); $suffix++) {
            $name = $baseName.'_'.$suffix;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function relationExists(string $name, string $tableName): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::table('pg_class as c')
                ->where('c.relname', $name)
                ->whereRaw('c.relnamespace = (SELECT relnamespace FROM pg_class WHERE oid = to_regclass(?))', [$tableName])
                ->exists();
        }

        return Schema::hasIndex($tableName, $name);
    }

    public function down(): void
    {
        throw new RuntimeException('HR notification records cannot regain tenant company scope.');
    }
};
