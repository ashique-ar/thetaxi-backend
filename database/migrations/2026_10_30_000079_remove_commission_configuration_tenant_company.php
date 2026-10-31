<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    private const TABLES = [
        'sales_commission_plan_families',
        'sales_commission_plan_assignments',
        'sales_commission_staff_overrides',
        'sales_commission_cycle_versions',
        'sales_commission_cycle_assignments',
        'sales_commission_business_calendars',
    ];

    public function up(): void
    {
        $this->assertNoGlobalCollisions();

        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes($tableName) as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop($tableName, $index);
                }
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        foreach (['sales_commission_plan_assignments', 'sales_commission_cycle_assignments'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                DB::table($tableName)->where('scope_type', 'company')->update(['scope_type' => 'installation']);
            }
        }

        $this->ensureIndex('sales_commission_plan_families', ['code'], 'sales_commission_plan_family_code_unique', true);
        $this->ensureIndex('sales_commission_plan_assignments', ['scope_type', 'effective_from'], 'commission_assignment_scope_effective_idx');
        $this->ensureIndex('sales_commission_cycle_versions', ['code', 'version'], 'sales_commission_cycle_version_unique', true);
        $this->ensureIndex('sales_commission_cycle_assignments', ['scope_type', 'effective_from'], 'commission_cycle_assignment_effective_idx');
        $this->ensureIndex('sales_commission_business_calendars', ['code', 'effective_from'], 'sales_commission_calendar_version_unique', true);
        $this->ensureIndex('sales_commission_business_calendars', ['status', 'effective_from'], 'sales_commission_calendar_effective_idx');
    }

    private function assertNoGlobalCollisions(): void
    {
        $this->assertUniqueValues('sales_commission_plan_families', ['code']);
        $this->assertUniqueValues('sales_commission_cycle_versions', ['code', 'version']);
        $this->assertUniqueValues('sales_commission_business_calendars', ['code', 'effective_from']);
        $this->assertNoOverlappingPlanAssignments();
        $this->assertNoOverlappingRows('sales_commission_staff_overrides', ['staff_id']);
        $this->assertNoOverlappingRows('sales_commission_cycle_assignments', ['scope_type', 'sales_profile_id', 'staff_id', 'staff_category']);
        $this->assertNoOverlappingRows('sales_commission_cycle_versions', ['code']);
        $this->assertNoOverlappingRows('sales_commission_business_calendars', ['code']);
    }

    private function assertUniqueValues(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }
        $collisions = DB::table($tableName)->select($columns)->selectRaw('COUNT(*) AS row_count')
            ->groupBy(...$columns)->havingRaw('COUNT(*) > 1')->limit(20)->get();
        if ($collisions->isNotEmpty()) {
            throw new RuntimeException("Cannot make {$tableName} installation-wide; duplicate keys: ".json_encode($collisions->all(), JSON_THROW_ON_ERROR));
        }
    }

    private function assertNoOverlappingRows(string $tableName, array $scopeColumns): void
    {
        if (! Schema::hasTable($tableName)
            || ! Schema::hasColumn($tableName, 'status')
            || ! Schema::hasColumn($tableName, 'effective_from')) {
            return;
        }

        $rows = DB::table($tableName)->where('status', 'approved')->orderBy('effective_from')->get();
        for ($leftIndex = 0; $leftIndex < $rows->count(); $leftIndex++) {
            $left = $rows[$leftIndex];
            for ($rightIndex = $leftIndex + 1; $rightIndex < $rows->count(); $rightIndex++) {
                $right = $rows[$rightIndex];
                $sameScope = true;
                foreach ($scopeColumns as $column) {
                    $leftValue = $left->{$column} ?? null;
                    $rightValue = $right->{$column} ?? null;
                    if ($column === 'scope_type') {
                        $leftValue = $leftValue === 'company' ? 'installation' : $leftValue;
                        $rightValue = $rightValue === 'company' ? 'installation' : $rightValue;
                    }
                    if ($leftValue !== $rightValue) {
                        $sameScope = false;
                        break;
                    }
                }
                if (! $sameScope) {
                    continue;
                }

                $leftEnd = $left->effective_until ? strtotime((string) $left->effective_until) : PHP_INT_MAX;
                $rightEnd = $right->effective_until ? strtotime((string) $right->effective_until) : PHP_INT_MAX;
                if (strtotime((string) $left->effective_from) < $rightEnd
                    && strtotime((string) $right->effective_from) < $leftEnd) {
                    throw new RuntimeException("Cannot make {$tableName} installation-wide; approved rows {$left->id} and {$right->id} overlap for the same scope.");
                }
            }
        }
    }

    private function assertNoOverlappingPlanAssignments(): void
    {
        if (! Schema::hasTable('sales_commission_plan_assignments')) {
            return;
        }
        $rows = DB::table('sales_commission_plan_assignments as assignment')
            ->join('sales_commission_plan_families as family', 'family.id', '=', 'assignment.plan_family_id')
            ->where('assignment.status', 'approved')->orderBy('assignment.effective_from')
            ->get(['assignment.*', 'family.commission_category']);
        for ($leftIndex = 0; $leftIndex < $rows->count(); $leftIndex++) {
            $left = $rows[$leftIndex];
            for ($rightIndex = $leftIndex + 1; $rightIndex < $rows->count(); $rightIndex++) {
                $right = $rows[$rightIndex];
                $sameScope = ($left->scope_type === 'company' ? 'installation' : $left->scope_type)
                    === ($right->scope_type === 'company' ? 'installation' : $right->scope_type)
                    && $left->commission_category === $right->commission_category
                    && $left->sales_profile_id === $right->sales_profile_id
                    && $left->staff_id === $right->staff_id
                    && $left->staff_category === $right->staff_category;
                if (! $sameScope) {
                    continue;
                }
                $leftEnd = $left->effective_until ? strtotime((string) $left->effective_until) : PHP_INT_MAX;
                $rightEnd = $right->effective_until ? strtotime((string) $right->effective_until) : PHP_INT_MAX;
                if (strtotime((string) $left->effective_from) < $rightEnd
                    && strtotime((string) $right->effective_from) < $leftEnd) {
                    throw new RuntimeException("Cannot make sales_commission_plan_assignments installation-wide; approved rows {$left->id} and {$right->id} overlap for the same scope and category.");
                }
            }
        }
    }

    private function ensureIndex(string $tableName, array $columns, string $baseName, bool $unique = false): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }
        foreach (Schema::getIndexes($tableName) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique';
            if ($index['columns'] === $columns && (! $unique || $isUnique)) {
                return;
            }
        }

        $name = $baseName;
        for ($suffix = 1; $this->relationExists($tableName, $name); $suffix++) {
            $name = $baseName.'_'.$suffix;
        }
        Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function relationExists(string $tableName, string $name): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::table('pg_class as c')->where('c.relname', $name)
                ->whereRaw('c.relnamespace = (SELECT relnamespace FROM pg_class WHERE oid = to_regclass(?))', [$tableName])
                ->exists();
        }

        return Schema::hasIndex($tableName, $name);
    }

    public function down(): void
    {
        throw new RuntimeException('Commission configuration cannot regain tenant company scope.');
    }
};
