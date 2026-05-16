<?php

namespace Database\Seeders;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Service\ServiceType;
use App\Models\User;
use App\Models\UserContext;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CorporateDynamicPricingSeeder extends Seeder
{
    public function run(): void
    {
        $corporate = Corporate::updateOrCreate(
            ['name' => 'Cassons Demo Corporate'],
            [
                'contact_email' => 'transport.demo@cassons.test',
                'contact_phone' => '+94112000000',
                'billing_address' => 'Corporate Transport Desk, Colombo',
                'is_active' => true,
                'approval_required' => true,
                'exempt_coordinator_from_approval' => true,
                'coordinator_can_view_payments' => true,
            ]
        );

        $department = CorporateDepartment::updateOrCreate(
            ['corporate_id' => $corporate->id, 'name' => 'Transport'],
            [
                'description' => 'Seeded corporate transport department.',
                'is_active' => true,
            ]
        );

        $division = CorporateDivision::updateOrCreate(
            ['department_id' => $department->id, 'name' => 'Operations'],
            [
                'description' => 'Seeded corporate transport operations division.',
                'is_active' => true,
            ]
        );

        $this->call(CorporatePermissionsSeeder::class);

        $seedUsers = $this->seedCorporateEmployees($corporate->id, $department->id, $division->id);
        $this->deactivateLegacyCorporateCoordinator($corporate->id);
        $employeeUser = $seedUsers['Corporate_Master_Admin'];

        $vehicleGroups = $this->vehicleGroups();
        foreach ($vehicleGroups as $vehicleGroup) {
            $this->upsertPivotWithUuid('corporate_vehicle_groups', [
                'corporate_id' => $corporate->id,
                'vehicle_group_id' => $vehicleGroup->id,
            ], []);
        }

        foreach ($this->scenarios() as $index => $scenario) {
            $serviceType = $this->serviceType($scenario, $corporate->id, $index + 1);

            $this->upsertPivotWithUuid('corporate_service_types', [
                'corporate_id' => $corporate->id,
                'service_type_id' => $serviceType->id,
            ], ['is_active' => true, 'deleted_at' => null]);

            $this->seedSlabs($scenario, $serviceType, $corporate->id, $vehicleGroups);
            $this->seedCommonRates($scenario, $serviceType, $corporate->id, $vehicleGroups);
            $this->seedCalculation($scenario, $serviceType, $corporate->id, $employeeUser->id);
        }

        $this->command?->info('Corporate dynamic pricing demo data seeded for Cassons Demo Corporate.');
    }

    private function vehicleGroups()
    {
        $definitions = [
            ['name' => 'Corporate Sedan', 'passengers_count' => 3],
            ['name' => 'Corporate Van', 'passengers_count' => 8],
            ['name' => 'Corporate SUV', 'passengers_count' => 4],
        ];

        return collect($definitions)->map(function (array $definition) {
            return VehicleGroup::updateOrCreate(
                ['name' => $definition['name']],
                [
                    'description' => 'Seeded corporate pricing vehicle group.',
                    'passengers_count' => $definition['passengers_count'],
                    'is_active' => true,
                ]
            );
        });
    }

    private function seedCorporateEmployees(string $corporateId, string $departmentId, string $divisionId): array
    {
        $employees = [
            'Corporate_Master_Admin' => [
                'email' => 'corporate.admin@cassons.test',
                'first_name' => 'Corporate',
                'last_name' => 'Admin',
                'phone' => '+94770000001',
                'employee_code' => 'CORP-DEMO-001',
            ],
            'Transport_Coordinator' => [
                'email' => 'transport.coordinator@cassons.test',
                'first_name' => 'Transport',
                'last_name' => 'Coordinator',
                'phone' => '+94770000002',
                'employee_code' => 'CORP-DEMO-002',
            ],
            'Approval_Manager' => [
                'email' => 'approval.manager@cassons.test',
                'first_name' => 'Approval',
                'last_name' => 'Manager',
                'phone' => '+94770000003',
                'employee_code' => 'CORP-DEMO-003',
            ],
            'Corporate_Employee' => [
                'email' => 'corporate.employee@cassons.test',
                'first_name' => 'Corporate',
                'last_name' => 'Employee',
                'phone' => '+94770000004',
                'employee_code' => 'CORP-DEMO-004',
            ],
        ];

        $users = [];

        foreach ($employees as $roleName => $employeeData) {
            $user = User::updateOrCreate(
                ['email' => $employeeData['email']],
                [
                    'first_name' => $employeeData['first_name'],
                    'last_name' => $employeeData['last_name'],
                    'phone' => $employeeData['phone'],
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'status' => 'active',
                ]
            );

            $employee = CorporateEmployee::updateOrCreate(
                ['user_id' => $user->id, 'corporate_id' => $corporateId],
                [
                    'department_id' => $departmentId,
                    'division_id' => $divisionId,
                    'employee_code' => $employeeData['employee_code'],
                    'is_active' => true,
                ]
            );

            $context = UserContext::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'context_type' => 'corporate',
                    'context_id' => $employee->id,
                ],
                ['is_active' => true]
            );

            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
            DB::table('user_context_roles')->updateOrInsert(
                ['user_context_id' => $context->id, 'role_id' => $role->id],
                ['created_at' => now(), 'updated_at' => now()]
            );

            if (!$user->hasRole($role)) {
                $user->assignRole($role);
            }

            $users[$roleName] = $user;
        }

        return $users;
    }

    private function deactivateLegacyCorporateCoordinator(string $corporateId): void
    {
        $legacyUser = User::where('email', 'corporate.coordinator@cassons.test')->first();

        if (!$legacyUser) {
            return;
        }

        $legacyEmployee = CorporateEmployee::where('user_id', $legacyUser->id)
            ->where('corporate_id', $corporateId)
            ->first();

        if (!$legacyEmployee) {
            return;
        }

        $legacyEmployee->update(['is_active' => false]);

        UserContext::where('user_id', $legacyUser->id)
            ->where('context_type', 'corporate')
            ->where('context_id', $legacyEmployee->id)
            ->update(['is_active' => false]);
    }

    private function upsertPivotWithUuid(string $table, array $keys, array $values): void
    {
        $values = array_filter(
            $values,
            fn ($value, string $column) => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );

        $existing = DB::table($table)->where($keys)->first();
        if ($existing) {
            DB::table($table)->where('id', $existing->id)->update($values + ['updated_at' => now()]);
            return;
        }

        DB::table($table)->insert($keys + $values + [
            'id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function serviceType(array $scenario, string $corporateId, int $priority): ServiceType
    {
        return ServiceType::updateOrCreate(
            [
                'code' => $scenario['code'],
                'context' => 'corporate',
                'owner_type' => 'corporate',
                'owner_id' => $corporateId,
            ],
            [
                'name' => $scenario['name'],
                'description' => $scenario['description'],
                'type' => 'with_driver',
                'slug' => Str::slug($scenario['code']),
                'pricing_mode' => 'dynamic',
                'uses_dropoff_time' => true,
                'allow_multiple_pickup_locations' => true,
                'allow_multiple_dropoff_locations' => true,
                'category' => 'corporate',
                'priority' => $priority,
                'is_internal' => false,
                'is_inquiry' => false,
            ]
        );
    }

    private function seedSlabs(array $scenario, ServiceType $serviceType, string $corporateId, $vehicleGroups): void
    {
        foreach ($scenario['slabs'] as $sort => $slabData) {
            $slab = VehiclePricingSlabDefinition::updateOrCreate(
                [
                    'service_type_id' => $serviceType->id,
                    'name' => $slabData['name'],
                    'owner_type' => 'corporate',
                    'owner_id' => $corporateId,
                ],
                [
                    'type' => $slabData['type'],
                    'min_hours' => $slabData['min_hours'],
                    'max_hours' => $slabData['max_hours'],
                    'min_days' => $slabData['min_days'],
                    'max_days' => $slabData['max_days'],
                    'max_km_per_day' => $slabData['max_km_per_day'],
                    'max_km_per_package' => $slabData['max_km_per_package'],
                    'sort_order' => $sort + 1,
                    'priority' => 100,
                    'is_active' => true,
                ]
            );

            foreach ($vehicleGroups as $vehicleGroup) {
                $multiplier = $this->vehicleMultiplier($vehicleGroup->name);
                VehicleGroupPricing::updateOrCreate(
                    [
                        'slab_definition_id' => $slab->id,
                        'vehicle_group_id' => $vehicleGroup->id,
                        'owner_type' => 'corporate',
                        'owner_id' => $corporateId,
                    ],
                    [
                        'rate' => round($slabData['base_rate'] * $multiplier, 2),
                        'rate_type' => $slabData['rate_type'],
                        'minimum_charge' => $slabData['minimum_charge'] ?? null,
                        'includes_fuel' => true,
                        'includes_driver' => true,
                        'priority' => 100,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function seedCommonRates(array $scenario, ServiceType $serviceType, string $corporateId, $vehicleGroups): void
    {
        foreach ($scenario['rates'] as $sort => $rateData) {
            $definition = VehiclePricingCommonRateDefinition::updateOrCreate(
                [
                    'service_type_id' => $serviceType->id,
                    'code' => $rateData['code'],
                    'owner_type' => 'corporate',
                    'owner_id' => $corporateId,
                ],
                [
                    'name' => $rateData['name'],
                    'description' => $rateData['description'] ?? 'Seeded corporate dynamic pricing rate.',
                    'common_rate_type' => $rateData['type'],
                    'is_mandatory' => true,
                    'is_active' => true,
                    'sort_order' => $sort + 1,
                    'priority' => 100,
                ]
            );

            foreach ($vehicleGroups as $vehicleGroup) {
                $multiplier = ($rateData['vehicle_multiplier'] ?? false) ? $this->vehicleMultiplier($vehicleGroup->name) : 1;
                VehicleGroupCommonRatePricing::updateOrCreate(
                    [
                        'vehicle_group_id' => $vehicleGroup->id,
                        'common_rate_definition_id' => $definition->id,
                        'owner_type' => 'corporate',
                        'owner_id' => $corporateId,
                    ],
                    [
                        'value' => round($rateData['value'] * $multiplier, 2),
                        'is_active' => true,
                        'priority' => 100,
                    ]
                );
            }
        }
    }

    private function seedCalculation(array $scenario, ServiceType $serviceType, string $corporateId, string $userId): void
    {
        VehiclePricingCalculationDefinition::updateOrCreate(
            [
                'service_type_id' => $serviceType->id,
                'name' => $scenario['name'] . ' Corporate Calculation',
                'owner_type' => 'corporate',
                'owner_id' => $corporateId,
            ],
            [
                'description' => $scenario['description'],
                'formula' => $scenario['formula'],
                'variables' => collect($scenario['variables'])->map(fn (string $name) => [
                    'name' => $name,
                    'type' => $name === 'slab_rate' ? 'slab_rate' : 'common_rate',
                    'default_value' => 0,
                    'description' => Str::headline($name),
                ])->values()->all(),
                'conditions' => [],
                'status' => 'active',
                'priority' => 100,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );
    }

    private function vehicleMultiplier(string $name): float
    {
        return match (true) {
            str_contains(strtolower($name), 'suv') => 1.5,
            str_contains(strtolower($name), 'van') => 1.25,
            default => 1.0,
        };
    }

    private function scenarios(): array
    {
        return [
            [
                'code' => 'corp_on_meter',
                'name' => 'Corporate On Meter',
                'description' => 'Start-to-end meter pricing with included first kilometres and day minimums.',
                'formula' => 'base_rate + first_km_rate + (extra_km * extra_km_rate) + (minimum_km_per_day * effective_days * extra_km_rate_per_day)',
                'variables' => ['base_rate', 'first_km_rate', 'extra_km', 'extra_km_rate', 'minimum_km_per_day', 'effective_days', 'extra_km_rate_per_day'],
                'slabs' => [['name' => 'Meter Day Minimum', 'type' => 'per_day', 'min_hours' => 0, 'max_hours' => 24, 'min_days' => 1, 'max_days' => 30, 'max_km_per_day' => 84, 'max_km_per_package' => null, 'base_rate' => 6500, 'rate_type' => 'per_day']],
                'rates' => [
                    ['code' => 'base_rate', 'name' => 'Base Rate', 'type' => 'fixed_amount', 'value' => 1500],
                    ['code' => 'first_km_rate', 'name' => 'First 5 KM Included Rate', 'type' => 'fixed_amount', 'value' => 900],
                    ['code' => 'extra_km_rate', 'name' => 'Extra KM Rate', 'type' => 'per_km', 'value' => 145, 'vehicle_multiplier' => true],
                    ['code' => 'minimum_km_per_day', 'name' => 'Minimum KM Per Day', 'type' => 'fixed_amount', 'value' => 84],
                    ['code' => 'extra_km_rate_per_day', 'name' => 'Extra KM Rate Per Day', 'type' => 'per_km', 'value' => 120],
                ],
            ],
            [
                'code' => 'corp_hourly_package',
                'name' => 'Corporate Hourly Package',
                'description' => 'Minimum hour and kilometre package with extra hour/km overage.',
                'formula' => 'slab_rate + (extra_hours * extra_hour_rate) + (extra_km * extra_km_rate)',
                'variables' => ['slab_rate', 'extra_hours', 'extra_hour_rate', 'extra_km', 'extra_km_rate'],
                'slabs' => [['name' => '3 Hours / 30 KM', 'type' => 'hours', 'min_hours' => 3, 'max_hours' => 3, 'min_days' => 0, 'max_days' => 0, 'max_km_per_day' => null, 'max_km_per_package' => 30, 'base_rate' => 5500, 'rate_type' => 'flat_rate']],
                'rates' => [
                    ['code' => 'extra_hour_rate', 'name' => 'Extra Hour Rate', 'type' => 'per_hour', 'value' => 720],
                    ['code' => 'extra_km_rate', 'name' => 'Extra KM Rate', 'type' => 'per_km', 'value' => 145, 'vehicle_multiplier' => true],
                ],
            ],
            [
                'code' => 'corp_airport_transfer',
                'name' => 'Corporate Airport Transfer',
                'description' => 'Point-to-point airport transfer with waiting allowance and overage.',
                'formula' => 'slab_rate + (waiting_overage_hours * waiting_overage_rate) + (extra_km * extra_km_rate)',
                'variables' => ['slab_rate', 'waiting_overage_hours', 'waiting_overage_rate', 'extra_km', 'extra_km_rate'],
                'slabs' => [['name' => 'Airport Flat Transfer', 'type' => 'flat_rate', 'min_hours' => 1, 'max_hours' => 6, 'min_days' => 0, 'max_days' => 0, 'max_km_per_day' => null, 'max_km_per_package' => 40, 'base_rate' => 8500, 'rate_type' => 'flat_rate']],
                'rates' => [
                    ['code' => 'waiting_allowance_minutes', 'name' => 'Waiting Allowance Minutes', 'type' => 'fixed_amount', 'value' => 45],
                    ['code' => 'waiting_overage_rate', 'name' => 'Waiting Overage Rate', 'type' => 'per_hour', 'value' => 950],
                    ['code' => 'extra_km_rate', 'name' => 'Extra KM Rate', 'type' => 'per_km', 'value' => 135],
                ],
            ],
            [
                'code' => 'corp_tour',
                'name' => 'Corporate Tour',
                'description' => 'Daily tour with minimum kilometres, extra kilometres, hours, and days.',
                'formula' => '(slab_rate * effective_days) + (extra_km * extra_km_rate) + (extra_hours * extra_hour_rate) + (extra_days * extra_day_rate)',
                'variables' => ['slab_rate', 'effective_days', 'extra_km', 'extra_km_rate', 'extra_hours', 'extra_hour_rate', 'extra_days', 'extra_day_rate'],
                'slabs' => [['name' => 'Tour 100 KM / Day', 'type' => 'per_day', 'min_hours' => 0, 'max_hours' => 24, 'min_days' => 1, 'max_days' => 14, 'max_km_per_day' => 100, 'max_km_per_package' => null, 'base_rate' => 12000, 'rate_type' => 'per_day']],
                'rates' => [
                    ['code' => 'extra_km_rate', 'name' => 'Extra KM Rate', 'type' => 'per_km', 'value' => 150, 'vehicle_multiplier' => true],
                    ['code' => 'extra_hour_rate', 'name' => 'Extra Hour Rate', 'type' => 'per_hour', 'value' => 900],
                    ['code' => 'extra_day_rate', 'name' => 'Extra Day Rate', 'type' => 'per_day', 'value' => 10500],
                ],
            ],
            [
                'code' => 'corp_ctc',
                'name' => 'Corporate Transport Contract',
                'description' => '8 hours / 100 km CTC minimum package with configured overages.',
                'formula' => 'slab_rate + (extra_km * extra_km_rate) + (extra_hours * extra_hour_rate) + driver_fixed_rate',
                'variables' => ['slab_rate', 'extra_km', 'extra_km_rate', 'extra_hours', 'extra_hour_rate', 'driver_fixed_rate'],
                'slabs' => [['name' => '8 Hours / 100 KM', 'type' => 'hours', 'min_hours' => 8, 'max_hours' => 8, 'min_days' => 0, 'max_days' => 0, 'max_km_per_day' => null, 'max_km_per_package' => 100, 'base_rate' => 15500, 'rate_type' => 'flat_rate']],
                'rates' => [
                    ['code' => 'extra_km_rate', 'name' => 'Extra KM Rate', 'type' => 'per_km', 'value' => 145],
                    ['code' => 'extra_hour_rate', 'name' => 'Extra Hour Rate', 'type' => 'per_hour', 'value' => 720],
                    ['code' => 'driver_rate_type', 'name' => 'Driver Rate Type Fixed', 'type' => 'fixed_amount', 'value' => 1],
                    ['code' => 'driver_fixed_rate', 'name' => 'Driver Fixed Rate', 'type' => 'fixed_amount', 'value' => 2500],
                ],
            ],
            [
                'code' => 'corp_special_night',
                'name' => 'Corporate Special Night Transport',
                'description' => 'Night/special transport with kilometre rate and vehicle multiplier.',
                'formula' => 'base_rate + (total_distance * night_km_rate) + driver_commission_value',
                'variables' => ['base_rate', 'total_distance', 'night_km_rate', 'driver_commission_value'],
                'slabs' => [['name' => 'Night Transport Minimum', 'type' => 'flat_rate', 'min_hours' => 1, 'max_hours' => 12, 'min_days' => 0, 'max_days' => 0, 'max_km_per_day' => null, 'max_km_per_package' => 25, 'base_rate' => 3500, 'rate_type' => 'flat_rate']],
                'rates' => [
                    ['code' => 'base_rate', 'name' => 'Night Base Rate', 'type' => 'fixed_amount', 'value' => 3500],
                    ['code' => 'night_km_rate', 'name' => 'Night KM Rate', 'type' => 'per_km', 'value' => 160, 'vehicle_multiplier' => true],
                    ['code' => 'driver_rate_type', 'name' => 'Driver Rate Type Commission', 'type' => 'fixed_amount', 'value' => 2],
                    ['code' => 'driver_commission_type', 'name' => 'Driver Commission Type Percentage', 'type' => 'fixed_amount', 'value' => 1],
                    ['code' => 'driver_commission_value', 'name' => 'Driver Commission Value', 'type' => 'percentage', 'value' => 12],
                ],
            ],
            [
                'code' => 'corp_manual_dispatch',
                'name' => 'Corporate Manual Dispatch',
                'description' => 'Manual dispatch using the same corporate dynamic pricing scope.',
                'formula' => 'base_rate + (total_distance * dispatch_km_rate) + (waiting_hours * waiting_overage_rate)',
                'variables' => ['base_rate', 'total_distance', 'dispatch_km_rate', 'waiting_hours', 'waiting_overage_rate'],
                'slabs' => [['name' => 'Manual Dispatch Base', 'type' => 'flat_rate', 'min_hours' => 1, 'max_hours' => 24, 'min_days' => 0, 'max_days' => 0, 'max_km_per_day' => null, 'max_km_per_package' => 50, 'base_rate' => 6000, 'rate_type' => 'flat_rate']],
                'rates' => [
                    ['code' => 'base_rate', 'name' => 'Dispatch Base Rate', 'type' => 'fixed_amount', 'value' => 6000],
                    ['code' => 'dispatch_km_rate', 'name' => 'Dispatch KM Rate', 'type' => 'per_km', 'value' => 140],
                    ['code' => 'waiting_overage_rate', 'name' => 'Waiting Overage Rate', 'type' => 'per_hour', 'value' => 800],
                ],
            ],
        ];
    }
}
