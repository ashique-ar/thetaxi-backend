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
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Spatie\Permission\Models\Role;

class CorporateDynamicPricingSeeder extends Seeder
{
    private const CORPORATE_NAME = 'Cassons Demo Corporate';

    private const SERVICE_MAP = [
        ['code' => 'on_meter', 'name' => 'On Meter', 'sources' => ['day_rental'], 'legacy_codes' => ['corp_on_meter']],
        ['code' => 'ride_now', 'name' => 'Ride Now', 'sources' => ['ride_now'], 'legacy_codes' => ['corp_ride_now', 'corp_manual_dispatch']],
        ['code' => 'point_to_point', 'name' => 'Point to Point', 'sources' => ['point_to_point', 'potint_to_point'], 'legacy_codes' => ['corp_point_to_point']],
        ['code' => 'hourly_package', 'name' => 'Hourly Package', 'sources' => ['ride_now'], 'legacy_codes' => ['corp_hourly_package']],
        ['code' => 'tour', 'name' => 'Tour', 'sources' => ['ride_now'], 'legacy_codes' => ['corp_tour']],
        ['code' => 'special_night', 'name' => 'Special Night Transport', 'sources' => ['ride_now'], 'legacy_codes' => ['corp_special_night']],
        ['code' => 'airport_transfer', 'name' => 'Airport Transfer', 'sources' => ['airport_transfers'], 'legacy_codes' => ['corp_airport_transfer']],
        ['code' => 'transport_contract', 'name' => 'Transport Contract', 'sources' => ['corporate'], 'legacy_codes' => ['corp_ctc']],
    ];

    public function run(): void
    {
        $corporate = Corporate::updateOrCreate(
            ['name' => self::CORPORATE_NAME],
            [
                'contact_email' => 'transport.demo@cassons.test',
                'contact_phone' => '+94770000000',
                'billing_address' => 'Corporate Transport Desk, Colombo',
                'is_active' => true,
                'approval_required' => true,
                'exempt_coordinator_from_approval' => true,
                'coordinator_can_view_payments' => true,
            ]
        );

        $department = CorporateDepartment::updateOrCreate(
            ['corporate_id' => $corporate->id, 'name' => 'Transport'],
            ['description' => 'Seeded corporate transport department.', 'is_active' => true]
        );
        $division = CorporateDivision::updateOrCreate(
            ['department_id' => $department->id, 'name' => 'Operations'],
            ['description' => 'Seeded corporate transport operations division.', 'is_active' => true]
        );

        $this->call(CorporatePermissionsSeeder::class);
        $users = $this->seedCorporateEmployees($corporate->id, $department->id, $division->id);
        $this->deactivateLegacyCorporateCoordinator($corporate->id);

        DB::transaction(function () use ($corporate, $users): void {
            $vehicleGroups = $this->selectVehicleGroups($corporate);
            $corporate->vehicleGroups()->sync($vehicleGroups->pluck('id')->all());

            foreach (self::SERVICE_MAP as $priority => $mapping) {
                $source = $this->requiredPublicSource($mapping);
                $target = $this->upsertCorporateService($source, $mapping, $priority + 1);
                $this->retireOwnedCorporateService($mapping, $corporate->id);

                $this->upsertPivot('corporate_service_types', [
                    'corporate_id' => $corporate->id,
                    'service_type_id' => $target->id,
                ], ['is_active' => true, 'deleted_at' => null]);

                $this->clonePricingGraph(
                    $source,
                    $target,
                    $corporate->id,
                    $vehicleGroups,
                    $users['Corporate_Master_Admin']->id
                );
            }
        });

        $this->command?->info('Corporate pricing cloned for ' . self::CORPORATE_NAME . '.');
    }

    private function selectVehicleGroups(Corporate $corporate): Collection
    {
        $assigned = $corporate->vehicleGroups()
            ->where('vehicle_groups.is_active', true)
            ->orderBy('vehicle_groups.name')
            ->limit(3)
            ->get();

        if ($assigned->count() < 3) {
            $additional = VehicleGroup::query()
                ->where('is_active', true)
                ->whereNotIn('id', $assigned->pluck('id'))
                ->orderBy('name')
                ->limit(3 - $assigned->count())
                ->get();
            $assigned = $assigned->concat($additional);
        }

        if ($assigned->count() !== 3) {
            throw new RuntimeException('Corporate pricing seeder requires at least 3 existing active vehicle groups.');
        }

        return $assigned->values();
    }

    private function requiredPublicSource(array $mapping): ServiceType
    {
        foreach ($mapping['sources'] as $code) {
            $source = ServiceType::query()
                ->where('code', $code)
                ->where(function ($query) {
                    $query->whereNull('owner_type')->orWhere('owner_type', '');
                })
                ->where(function ($query) {
                    $query->whereNull('owner_id')->orWhere('owner_id', '');
                })
                ->first();

            if ($source) {
                return $source;
            }
        }

        throw new RuntimeException(sprintf(
            'Missing public pricing source for %s. Expected service code: %s.',
            $mapping['name'],
            implode(' or ', $mapping['sources'])
        ));
    }

    private function upsertCorporateService(
        ServiceType $source,
        array $mapping,
        int $priority
    ): ServiceType {
        $attributes = collect($source->getAttributes())->except([
            'id', 'code', 'name', 'slug', 'context', 'owner_type', 'owner_id',
            'parent_service_type_id', 'description', 'priority',
            'created_at', 'updated_at', 'deleted_at',
        ])->all();

        return ServiceType::withTrashed()->updateOrCreate(
            [
                'code' => $mapping['code'],
                'context' => 'corporate',
                'owner_type' => '',
                'owner_id' => '',
            ],
            array_merge($attributes, [
                'name' => $mapping['name'],
                'description' => 'Pricing cloned from public ' . $source->name . '.',
                'slug' => Str::slug($mapping['code']),
                'parent_service_type_id' => $source->id,
                'priority' => $priority,
                'deleted_at' => null,
            ])
        );
    }

    private function clonePricingGraph(
        ServiceType $source,
        ServiceType $target,
        string $corporateId,
        Collection $vehicleGroups,
        string $userId
    ): void {
        $slabMap = $this->cloneDefinitions(
            'vehicle_pricing_slab_definitions',
            $source->id,
            $target->id,
            $corporateId,
            $userId
        );
        $commonRateMap = $this->cloneDefinitions(
            'vehicle_pricing_common_rate_definitions',
            $source->id,
            $target->id,
            $corporateId,
            $userId
        );
        $calculationMap = $this->cloneDefinitions(
            'vehicle_pricing_calculation_definitions',
            $source->id,
            $target->id,
            $corporateId,
            $userId
        );

        if ($slabMap === [] || $commonRateMap === [] || $calculationMap === []) {
            throw new RuntimeException("Public service {$source->code} does not contain the complete pricing definition graph.");
        }

        $this->cloneVehiclePricing(
            'vehicle_group_pricing',
            'slab_definition_id',
            $slabMap,
            $source->id,
            $corporateId,
            $vehicleGroups,
            $userId
        );
        $this->cloneVehiclePricing(
            'vehicle_group_common_rate_pricing',
            'common_rate_definition_id',
            $commonRateMap,
            $source->id,
            $corporateId,
            $vehicleGroups,
            $userId,
            false
        );
    }

    private function cloneDefinitions(
        string $table,
        string $sourceServiceId,
        string $targetServiceId,
        string $corporateId,
        string $userId
    ): array {
        $rows = DB::table($table)
            ->where('service_type_id', $sourceServiceId)
            ->where(function ($query) {
                $query->whereNull('owner_type')->orWhere('owner_type', '');
            })
            ->whereNull('owner_id')
            ->whereNull('deleted_at')
            ->when(
                $table === 'vehicle_pricing_calculation_definitions',
                fn ($query) => $query->where('status', 'active'),
                fn ($query) => $query->where('is_active', true)
            )
            ->orderBy('id')
            ->get();
        $map = [];

        foreach ($rows as $row) {
            $payload = $this->clonePayload($table, (array) $row, $userId);
            $sourceId = $payload['id'];
            $payload['id'] = Uuid::uuid5($targetServiceId, "{$table}:{$sourceId}")->toString();
            $payload['service_type_id'] = $targetServiceId;
            $payload['owner_type'] = 'corporate';
            $payload['owner_id'] = $corporateId;
            if (isset($payload['name'])) {
                $payload['name'] = Str::limit($payload['name'] . ' - ' . $targetServiceId, 255, '');
            }
            if ($table === 'vehicle_pricing_common_rate_definitions' && isset($payload['code'])) {
                $payload['code'] = Str::limit($payload['code'] . '_' . Str::substr(str_replace('-', '', $targetServiceId), 0, 8), 100, '');
            }
            DB::table($table)->updateOrInsert(['id' => $payload['id']], $payload);
            $map[$sourceId] = $payload['id'];
        }

        return $map;
    }

    private function cloneVehiclePricing(
        string $pricingTable,
        string $foreignKey,
        array $definitionMap,
        string $sourceServiceId,
        string $corporateId,
        Collection $vehicleGroups,
        string $userId,
        bool $requireEveryPrice = true
    ): void {
        foreach ($vehicleGroups as $vehicleGroup) {
            foreach ($definitionMap as $sourceDefinitionId => $targetDefinitionId) {
                $sourcePrice = DB::table($pricingTable)
                    ->where($foreignKey, $sourceDefinitionId)
                    ->where('vehicle_group_id', $vehicleGroup->id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$sourcePrice) {
                    if (!$requireEveryPrice) {
                        continue;
                    }

                    throw new RuntimeException(
                        "Missing {$pricingTable} for public service {$sourceServiceId}, vehicle group {$vehicleGroup->name}."
                    );
                }

                $payload = $this->clonePayload($pricingTable, (array) $sourcePrice, $userId);
                $payload['id'] = Uuid::uuid5(
                    $targetDefinitionId,
                    "{$pricingTable}:{$vehicleGroup->id}"
                )->toString();
                $payload[$foreignKey] = $targetDefinitionId;
                $payload['owner_type'] = 'corporate';
                $payload['owner_id'] = $corporateId;
                DB::table($pricingTable)->updateOrInsert(['id' => $payload['id']], $payload);
            }
        }
    }

    private function clonePayload(string $table, array $payload, string $userId): array
    {
        $payload = array_intersect_key($payload, array_flip(Schema::getColumnListing($table)));
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        $payload['deleted_at'] = null;

        foreach (['created_user_id', 'created_by'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $payload[$column] = $userId;
            }
        }
        foreach (['updated_user_id', 'updated_by'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $payload[$column] = $userId;
            }
        }

        return $payload;
    }

    private function retireOwnedCorporateService(array $mapping, string $corporateId): void
    {
        $legacyCodes = array_merge([$mapping['code']], $mapping['legacy_codes'] ?? []);

        $legacyServices = ServiceType::withTrashed()
            ->whereIn('code', $legacyCodes)
            ->where('context', 'corporate')
            ->where('owner_type', 'corporate')
            ->where('owner_id', $corporateId)
            ->get();

        foreach ($legacyServices as $legacy) {
            DB::table('corporate_service_types')
                ->where('service_type_id', $legacy->id)
                ->delete();
            $legacy->delete();
        }
    }

    private function upsertPivot(string $table, array $keys, array $values): void
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

    private function seedCorporateEmployees(string $corporateId, string $departmentId, string $divisionId): array
    {
        $employees = [
            'Corporate_Master_Admin' => ['email' => 'corporate.admin@cassons.test', 'first_name' => 'Corporate', 'last_name' => 'Admin', 'employee_code' => 'CORP-DEMO-001'],
            'Transport_Coordinator' => ['email' => 'transport.coordinator@cassons.test', 'first_name' => 'Transport', 'last_name' => 'Coordinator', 'employee_code' => 'CORP-DEMO-002'],
            'Approval_Manager' => ['email' => 'approval.manager@cassons.test', 'first_name' => 'Approval', 'last_name' => 'Manager', 'employee_code' => 'CORP-DEMO-003'],
            'Corporate_Employee' => ['email' => 'corporate.employee@cassons.test', 'first_name' => 'Corporate', 'last_name' => 'Employee', 'employee_code' => 'CORP-DEMO-004'],
        ];
        $users = [];

        foreach ($employees as $roleName => $data) {
            $user = User::withTrashed()->firstOrNew(['email' => $data['email']]);
            $user->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => '+94770000000',
                'is_active' => true,
                'status' => 'active',
            ]);
            if (!$user->exists) {
                $user->password = Hash::make('password');
            }
            $user->deleted_at = null;
            $user->save();

            $employee = CorporateEmployee::withInactive()->withTrashed()->firstOrNew([
                'user_id' => $user->id,
                'corporate_id' => $corporateId,
            ]);
            $employee->fill([
                'department_id' => $departmentId,
                'division_id' => $divisionId,
                'employee_code' => $data['employee_code'],
                'is_active' => true,
            ]);
            $employee->deleted_at = null;
            $employee->save();

            $context = UserContext::withTrashed()->firstOrNew([
                'user_id' => $user->id,
                'context_type' => 'corporate',
                'context_id' => $employee->id,
            ]);
            $context->is_active = true;
            $context->deleted_at = null;
            $context->save();
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
}
