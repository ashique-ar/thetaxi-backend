<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->constraintExists('booking_discounts', 'booking_discounts_booking_id_foreign')) {
            Schema::table('booking_discounts', function (Blueprint $table) {
                $table->foreign('booking_id')
                    ->references('id')->on('bookings')
                    ->onDelete('cascade');
            });
        }

        if (!$this->indexExists('booking_discounts', 'bd_booking_type_method_idx')) {
            Schema::table('booking_discounts', function (Blueprint $table) {
                $table->index(['booking_id', 'type', 'application_method'], 'bd_booking_type_method_idx');
            });
        }

        if (!$this->constraintExists('booking_pricings', 'booking_pricings_booking_id_foreign')) {
            Schema::table('booking_pricings', function (Blueprint $table) {
                $table->foreign('booking_id')
                    ->references('id')->on('bookings')
                    ->onDelete('cascade');
            });
        }

        if (!$this->constraintExists('booking_pricings', 'booking_pricings_slab_definition_id_foreign')) {
            Schema::table('booking_pricings', function (Blueprint $table) {
                $table->foreign('slab_definition_id')
                    ->references('id')->on('vehicle_pricing_slab_definitions')
                    ->onDelete('restrict');
            });
        }

        if (!$this->constraintExists('booking_pricings', 'booking_pricings_vehicle_group_pricing_id_foreign')) {
            Schema::table('booking_pricings', function (Blueprint $table) {
                $table->foreign('vehicle_group_pricing_id')
                    ->references('id')->on('vehicle_group_pricing')
                    ->onDelete('restrict');
            });
        }

        if (!$this->indexExists('booking_pricings', 'bp_booking_rate_type_idx')) {
            Schema::table('booking_pricings', function (Blueprint $table) {
                $table->index(['booking_id', 'rate_type'], 'bp_booking_rate_type_idx');
            });
        }

        if ($this->hasColumns('bookings', ['from_date', 'to_date']) && !$this->indexExists('bookings', 'bookings_date_range_idx')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->index(['from_date', 'to_date'], 'bookings_date_range_idx');
            });
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "booking_pricings" DROP CONSTRAINT IF EXISTS "booking_pricings_vehicle_group_pricing_id_foreign"');
        DB::statement('DROP INDEX IF EXISTS "bp_booking_rate_type_idx"');
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function constraintExists(string $table, string $constraintName): bool
    {
        return collect(DB::select(
            <<<'SQL'
                SELECT 1
                FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE t.relname = ?
                  AND c.conname = ?
                  AND n.nspname = ANY (current_schemas(false))
                LIMIT 1
            SQL,
            [$table, $constraintName]
        ))->isNotEmpty();
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(DB::select(
            'SELECT 1 FROM pg_indexes WHERE schemaname = ANY (current_schemas(false)) AND tablename = ? AND indexname = ? LIMIT 1',
            [$table, $indexName]
        ))->isNotEmpty();
    }
};
