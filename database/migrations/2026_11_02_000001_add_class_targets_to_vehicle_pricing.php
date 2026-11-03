<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $rateTables = [
        'vehicle_group_pricing' => 'slab_definition_id',
        'vehicle_group_common_rate_pricing' => 'common_rate_definition_id',
    ];

    public function up(): void
    {
        foreach ($this->rateTables as $name => $definition) {
            if (DB::table($name)->whereNull('vehicle_group_id')->exists()) {
                throw new RuntimeException("Repair targetless rates in {$name} before adding class pricing; no records were removed.");
            }
        }

        Schema::table('service_types', function (Blueprint $table): void {
            $table->string('pricing_basis', 20)->default('vehicle_group');
            $table->boolean('tour_booking_enabled')->default(false);
        });

        foreach ($this->rateTables as $name => $definition) {
            $index = $name === 'vehicle_group_pricing' ? 'vgp_class_definition_idx' : 'vgcrp_class_definition_idx';
            Schema::table($name, function (Blueprint $table) use ($definition, $index): void {
                $table->uuid('vehicle_group_id')->nullable()->change();
                $table->foreignUuid('vehicle_class_id')->nullable()->constrained('vehicle_classes')->restrictOnDelete();
                $table->index(['vehicle_class_id', $definition], $index);
            });

            if (DB::getDriverName() === 'mysql') {
                $scope = "CONCAT(vehicle_class_id, ':', {$definition}, ':', COALESCE(owner_type, ''), ':', COALESCE(owner_id, ''))";
                Schema::table($name, function (Blueprint $table) use ($scope): void {
                    // MySQL unique indexes allow multiple NULL owners unless the scope is materialized.
                    $table->string('class_rate_scope')->nullable()
                        ->virtualAs("CASE WHEN vehicle_class_id IS NOT NULL AND deleted_at IS NULL THEN {$scope} ELSE NULL END")
                        ->unique();
                });
            } else {
                $scopeIndex = $name . '_class_scope_unique';
                $ownerId = "COALESCE(CAST(owner_id AS TEXT), '')";
                DB::statement("CREATE UNIQUE INDEX {$scopeIndex} ON {$name} (vehicle_class_id, {$definition}, COALESCE(owner_type, ''), {$ownerId}) WHERE vehicle_class_id IS NOT NULL AND deleted_at IS NULL");
            }
            $constraint = "{$name}_exclusive_target";
            $valid = '((NEW.vehicle_group_id IS NULL) <> (NEW.vehicle_class_id IS NULL))';
            if (DB::getDriverName() === 'sqlite') {
                foreach (['INSERT', 'UPDATE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$constraint}_{$event} BEFORE {$event} ON {$name} WHEN NOT {$valid} BEGIN SELECT RAISE(ABORT, 'A rate must target exactly one vehicle group or class'); END");
                }
            } else {
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$constraint} CHECK ((vehicle_group_id IS NULL) <> (vehicle_class_id IS NULL))");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->rateTables as $name => $definition) {
            if (DB::table($name)->whereNotNull('vehicle_class_id')->exists()) {
                throw new RuntimeException('Cannot remove class pricing while class rates exist. Disable new sales and preserve accepted agreements.');
            }
        }
        if (DB::table('service_types')->where('pricing_basis', 'vehicle_class')->exists()) {
            throw new RuntimeException('Cannot remove class pricing while a service uses it.');
        }
        foreach ($this->rateTables as $name => $definition) {
            $constraint = "{$name}_exclusive_target";
            $index = $name === 'vehicle_group_pricing' ? 'vgp_class_definition_idx' : 'vgcrp_class_definition_idx';
            if (DB::getDriverName() === 'sqlite') {
                foreach (['INSERT', 'UPDATE'] as $event) {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$constraint}_{$event}");
                }
            } elseif (DB::getDriverName() === 'mysql') {
                $serverVersion = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
                $dropClause = stripos($serverVersion, 'mariadb') !== false ? 'DROP CONSTRAINT' : 'DROP CHECK';
                DB::statement("ALTER TABLE {$name} {$dropClause} {$constraint}");
            } else {
                DB::statement("ALTER TABLE {$name} DROP CONSTRAINT {$constraint}");
            }
            if (DB::getDriverName() === 'mysql') {
                Schema::table($name, function (Blueprint $table): void {
                    $table->dropUnique(['class_rate_scope']);
                    $table->dropColumn('class_rate_scope');
                });
            } else {
                DB::statement("DROP INDEX IF EXISTS {$name}_class_scope_unique");
            }
            Schema::table($name, function (Blueprint $table) use ($index): void {
                $table->dropForeign(['vehicle_class_id']);
                $table->dropIndex($index);
                $table->dropColumn('vehicle_class_id');
            });
        }
        Schema::table('service_types', fn (Blueprint $table) => $table->dropColumn(['pricing_basis', 'tour_booking_enabled']));
    }
};
