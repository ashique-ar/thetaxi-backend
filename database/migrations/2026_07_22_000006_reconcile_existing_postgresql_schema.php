<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->reconcileBookingTerms();
        $this->reconcileOperationalIndexes();
    }

    public function down(): void
    {
        // Forward-only reconciliation. Reverting UUID ownership or removing
        // operational indexes would reintroduce the production defects this
        // migration is designed to repair.
    }

    private function reconcileBookingTerms(): void
    {
        if (! Schema::hasTable('booking_terms')) {
            return;
        }

        DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_foreign');
        DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_terms_and_condition_id_foreign');
        DB::statement('ALTER TABLE booking_terms DROP CONSTRAINT IF EXISTS booking_terms_booking_id_terms_and_condition_id_unique');

        $this->convertToUuid('booking_terms', 'booking_id');
        $this->convertToUuid('booking_terms', 'terms_and_condition_id');

        foreach (['booking_id', 'terms_and_condition_id'] as $column) {
            $nullCount = DB::table('booking_terms')->whereNull($column)->count();
            if ($nullCount > 0) {
                throw new RuntimeException(
                    "booking_terms.{$column} contains {$nullCount} null value(s); repair the affected records before retrying."
                );
            }

            DB::statement("ALTER TABLE booking_terms ALTER COLUMN {$column} SET NOT NULL");
        }

        DB::statement(
            'ALTER TABLE booking_terms ADD CONSTRAINT booking_terms_booking_id_terms_and_condition_id_unique UNIQUE (booking_id, terms_and_condition_id)'
        );

        if (Schema::hasTable('bookings')) {
            $this->restoreValidatedForeignKey(
                'booking_terms',
                'booking_id',
                'bookings',
                'booking_terms_booking_id_foreign',
            );
        }

        if (Schema::hasTable('terms_and_conditions')) {
            $this->restoreValidatedForeignKey(
                'booking_terms',
                'terms_and_condition_id',
                'terms_and_conditions',
                'booking_terms_terms_and_condition_id_foreign',
            );
        }
    }

    private function restoreValidatedForeignKey(
        string $table,
        string $column,
        string $parentTable,
        string $constraint,
    ): void {
        $orphanCount = DB::table("{$table} as child")
            ->leftJoin("{$parentTable} as parent", "child.{$column}", '=', 'parent.id')
            ->whereNull('parent.id')
            ->count();

        if ($orphanCount > 0) {
            throw new RuntimeException(
                "{$table}.{$column} contains {$orphanCount} orphaned value(s); repair the affected records before retrying."
            );
        }

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) REFERENCES {$parentTable}(id) ON DELETE CASCADE NOT VALID"
        );
        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
    }

    private function convertToUuid(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column) || $this->columnType($table, $column) === 'uuid') {
            return;
        }

        $invalid = DB::table($table)
            ->whereNotNull($column)
            ->whereRaw("CAST({$column} AS text) !~* ?", [
                '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
            ])
            ->limit(10)
            ->pluck($column);

        if ($invalid->isNotEmpty()) {
            throw new RuntimeException(
                "{$table}.{$column} contains non-UUID values: " . $invalid->implode(', ')
            );
        }

        DB::statement(
            "ALTER TABLE {$table} ALTER COLUMN {$column} TYPE uuid USING {$column}::text::uuid"
        );
    }

    private function columnType(string $table, string $column): ?string
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('udt_name');
    }

    private function reconcileOperationalIndexes(): void
    {
        $indexes = [
            ['bookings', ['status', 'booking_date'], 'bookings_status_booking_date_index'],
            ['bookings', ['customer_id', 'status'], 'bookings_customer_id_status_index'],
            ['bookings', ['corporate_account_id', 'status'], 'bookings_corporate_account_id_status_index'],
            ['booking_items', ['from_date', 'to_date'], 'booking_items_date_range_idx'],
            ['booking_items', ['booking_id', 'from_date'], 'booking_items_booking_date_idx'],
            ['driver_assignments', ['booking_id'], 'da_booking_id_index'],
            ['vehicle_assignments', ['vehicle_id'], 'va_vehicle_id_index'],
            ['vehicle_assignments', ['booking_id'], 'va_booking_id_index'],
            ['route_points', ['driver_session_id', 'recorded_at'], 'rp_session_recorded_index'],
        ];

        foreach ($indexes as [$table, $columns, $name]) {
            if (! Schema::hasTable($table)
                || ! $this->hasColumns($table, $columns)
                || Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
