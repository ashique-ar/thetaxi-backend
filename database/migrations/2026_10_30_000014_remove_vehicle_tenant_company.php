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
        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'wialon_unit_id')) {
            $duplicateUnits = DB::table('vehicles')->whereNotNull('wialon_unit_id')
                ->select('wialon_unit_id')->groupBy('wialon_unit_id')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('wialon_unit_id');
            if ($duplicateUnits->isNotEmpty()) {
                throw new RuntimeException('Cannot make Wialon unit IDs installation-wide; duplicate vehicle assignments: '.implode(', ', $duplicateUnits->all()));
            }
        }

        // These tenant fields have no vehicle-location readers; handover_location is separate.
        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'company_id')) {
            foreach (Schema::getForeignKeys('vehicles') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('vehicles', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes('vehicles') as $index) {
                if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop('vehicles', $index);
            }
            Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'wialon_unit_id')) {
            $hasGlobalUnique = collect(Schema::getIndexes('vehicles'))->contains(fn (array $index) =>
                $index['columns'] === ['wialon_unit_id'] && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'));
            if (! $hasGlobalUnique) {
                Schema::table('vehicles', fn (Blueprint $table) => $table->unique('wialon_unit_id', 'vehicles_wialon_unit_id_unique'));
            }
        }

        Schema::dropIfExists('vehicle_companies');
    }

    public function down(): void
    {
        throw new RuntimeException('Vehicle tenant company assignments cannot be restored after installation-wide conversion.');
    }
};
