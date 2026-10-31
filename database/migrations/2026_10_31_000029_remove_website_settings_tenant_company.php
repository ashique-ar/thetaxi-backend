<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'website_settings';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        $duplicateTypes = DB::table($tableName)
            ->when(Schema::hasColumn($tableName, 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->whereNotNull('type')
            ->select('type')->groupBy('type')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('type');
        if ($duplicateTypes->isNotEmpty()) {
            $conflicts = DB::table($tableName)
                ->whereIn('type', $duplicateTypes)
                ->when(Schema::hasColumn($tableName, 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->orderBy('type')->get(['id', 'type', 'company_id'])
                ->map(fn (object $row): string => "{$row->type} (id={$row->id}, company_id=".($row->company_id ?? 'null').')')
                ->implode('; ');

            throw new RuntimeException('Cannot remove website_settings.company_id while active setting types are duplicated; reconcile these rows first: '.$conflicts);
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, function (Blueprint $table) use ($foreignKey): void {
                    $table->dropForeign($foreignKey['name']);
                });
            }
        }

        $existingColumnSets = array_map(
            static fn (array $index): array => $index['columns'],
            array_filter(Schema::getIndexes($tableName), static fn (array $index): bool => ! in_array('company_id', $index['columns'], true)),
        );

        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['primary'] ?? false) || ($index['type'] ?? null) === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                continue;
            }

            TenantIndexDropper::drop($tableName, $index);
            $columns = array_values(array_diff($index['columns'], ['company_id']));
            if ($columns === [] || in_array($columns, $existingColumnSets, true)) {
                continue;
            }

            $baseName = $tableName.'_installation_'.implode('_', $columns).'_idx';
            $name = $baseName;
            $suffix = 1;
            while ($this->relationExists($tableName, $name)) {
                $name = $baseName.'_'.$suffix++;
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
            $existingColumnSets[] = $columns;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn('company_id');
        });
    }

    private function relationExists(string $tableName, string $name): bool
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
        throw new RuntimeException('Website settings cannot regain tenant company scope.');
    }
};
