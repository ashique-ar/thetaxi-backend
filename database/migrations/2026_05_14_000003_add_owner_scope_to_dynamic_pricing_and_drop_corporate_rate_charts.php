<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'vehicle_pricing_calculation_definitions',
            'vehicle_pricing_common_rate_definitions',
            'vehicle_group_common_rate_pricing',
            'km_range_pricing_rules',
            'price_adjustments',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'owner_type')) {
                    $table->string('owner_type', 50)->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'owner_id')) {
                    $table->uuid('owner_id')->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'priority')) {
                    $table->integer('priority')->default(0)->index();
                }
            });
        }

        if (Schema::hasTable('vehicle_pricing_common_rate_definitions')) {
            Schema::table('vehicle_pricing_common_rate_definitions', function (Blueprint $table) {
                try {
                    $table->dropUnique('unique_common_rate_definition_name');
                } catch (Throwable $e) {
                }
                try {
                    $table->dropUnique('unique_common_rate_definition_code');
                } catch (Throwable $e) {
                }

                $table->unique(
                    ['name', 'service_type_id', 'vehicle_group_id', 'owner_type', 'owner_id'],
                    'unique_common_rate_definition_name_owner'
                );
                $table->unique(
                    ['code', 'service_type_id', 'vehicle_group_id', 'owner_type', 'owner_id'],
                    'unique_common_rate_definition_code_owner'
                );
            });
        }

        $this->migrateCorporateRateChartsToScopedDynamicPricing();

        Schema::dropIfExists('corporate_rate_charts');
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicle_pricing_common_rate_definitions')) {
            Schema::table('vehicle_pricing_common_rate_definitions', function (Blueprint $table) {
                try {
                    $table->dropUnique('unique_common_rate_definition_name_owner');
                } catch (Throwable $e) {
                }
                try {
                    $table->dropUnique('unique_common_rate_definition_code_owner');
                } catch (Throwable $e) {
                }
            });
        }

        Schema::create('corporate_rate_charts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id');
            $table->string('name', 255);
            $table->uuid('vehicle_group_id')->nullable();
            $table->uuid('service_type_id')->nullable();
            $table->decimal('per_km_rate', 10, 2)->nullable();
            $table->decimal('per_hour_rate', 10, 2)->nullable();
            $table->json('fixed_route_pricing')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach ([
            'vehicle_pricing_calculation_definitions',
            'vehicle_pricing_common_rate_definitions',
            'vehicle_group_common_rate_pricing',
            'km_range_pricing_rules',
            'price_adjustments',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'owner_type')) {
                    $table->dropColumn('owner_type');
                }
                if (Schema::hasColumn($tableName, 'owner_id')) {
                    $table->dropColumn('owner_id');
                }
            });
        }

        if (Schema::hasTable('vehicle_pricing_common_rate_definitions')) {
            Schema::table('vehicle_pricing_common_rate_definitions', function (Blueprint $table) {
                $table->unique(['name','service_type_id'], 'unique_common_rate_definition_name');
                $table->unique(['code','service_type_id'], 'unique_common_rate_definition_code');
            });
        }
    }

    private function migrateCorporateRateChartsToScopedDynamicPricing(): void
    {
        if (
            !Schema::hasTable('corporate_rate_charts')
            || !Schema::hasTable('vehicle_pricing_common_rate_definitions')
            || !Schema::hasTable('vehicle_group_common_rate_pricing')
        ) {
            return;
        }

        DB::table('corporate_rate_charts')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->chunk(100, function ($charts) {
                foreach ($charts as $chart) {
                    foreach ([
                        'extra_km_rate' => ['column' => 'per_km_rate', 'type' => 'per_km', 'label' => 'Extra KM Rate'],
                        'extra_hour_rate' => ['column' => 'per_hour_rate', 'type' => 'per_hour', 'label' => 'Extra Hour Rate'],
                    ] as $code => $config) {
                        $value = $chart->{$config['column']} ?? null;
                        if ($value === null || $chart->service_type_id === null) {
                            continue;
                        }

                        $definitionId = (string) Str::uuid();
                        DB::table('vehicle_pricing_common_rate_definitions')->insert([
                            'id' => $definitionId,
                            'name' => "{$chart->name} {$config['label']}",
                            'code' => $code,
                            'service_type_id' => $chart->service_type_id,
                            'vehicle_group_id' => $chart->vehicle_group_id,
                            'description' => 'Migrated from legacy corporate rate chart.',
                            'common_rate_type' => $config['type'],
                            'is_mandatory' => true,
                            'is_active' => (bool) $chart->is_active,
                            'sort_order' => 0,
                            'owner_type' => 'corporate',
                            'owner_id' => $chart->corporate_id,
                            'priority' => 100,
                            'created_user_id' => $chart->created_user_id,
                            'updated_user_id' => $chart->updated_user_id,
                            'created_at' => $chart->created_at ?? now(),
                            'updated_at' => $chart->updated_at ?? now(),
                        ]);

                        if ($chart->vehicle_group_id !== null) {
                            DB::table('vehicle_group_common_rate_pricing')->insert([
                                'id' => (string) Str::uuid(),
                                'vehicle_group_id' => $chart->vehicle_group_id,
                                'common_rate_definition_id' => $definitionId,
                                'value' => $value,
                                'is_active' => (bool) $chart->is_active,
                                'owner_type' => 'corporate',
                                'owner_id' => $chart->corporate_id,
                                'priority' => 100,
                                'created_user_id' => $chart->created_user_id,
                                'updated_user_id' => $chart->updated_user_id,
                                'created_at' => $chart->created_at ?? now(),
                                'updated_at' => $chart->updated_at ?? now(),
                            ]);
                        }
                    }
                }
            });
    }
};
