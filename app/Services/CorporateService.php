<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class CorporateService
{
    // ─── Corporate CRUD ───────────────────────────────────────────────

    public function createCorporate(array $data): Corporate
    {
        $corporate = Corporate::create($data);

        $this->logAudit('create', 'Corporate', $corporate->id, [
            'name' => $corporate->name,
        ]);

        return $corporate;
    }

    public function updateCorporate(Corporate $corporate, array $data): Corporate
    {
        $before = $corporate->only(array_keys($data));
        $corporate->update($data);
        $corporate->refresh();

        $this->logAudit('update', 'Corporate', $corporate->id, [
            'before' => $before,
            'after'  => $corporate->only(array_keys($data)),
        ]);

        return $corporate;
    }

    public function toggleCorporateStatus(Corporate $corporate): Corporate
    {
        $corporate->is_active = !$corporate->is_active;
        $corporate->save();

        $this->logAudit(
            $corporate->is_active ? 'activate' : 'deactivate',
            'Corporate',
            $corporate->id,
            ['is_active' => $corporate->is_active]
        );

        return $corporate;
    }

    // ─── Vehicle Group Assignment ─────────────────────────────────────

    public function assignVehicleGroups(Corporate $corporate, array $vehicleGroupIds): void
    {
        $vehicleGroupIds = collect($vehicleGroupIds)
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->map(fn ($id) => trim($id))
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($corporate, $vehicleGroupIds) {
            DB::table('corporate_vehicle_groups')
                ->where('corporate_id', $corporate->id)
                ->whereNotIn('vehicle_group_id', $vehicleGroupIds ?: ['__none__'])
                ->delete();

            $existingVehicleGroupIds = DB::table('corporate_vehicle_groups')
                ->where('corporate_id', $corporate->id)
                ->pluck('vehicle_group_id')
                ->all();

            $newVehicleGroupIds = array_values(array_diff($vehicleGroupIds, $existingVehicleGroupIds));

            if (!empty($newVehicleGroupIds)) {
                $timestamp = now();
                $rows = array_map(fn ($vehicleGroupId) => [
                    'id' => (string) Str::uuid(),
                    'corporate_id' => $corporate->id,
                    'vehicle_group_id' => $vehicleGroupId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], $newVehicleGroupIds);

                DB::table('corporate_vehicle_groups')->insert($rows);
            }
        });

        $this->logAudit('assign_vehicle_groups', 'Corporate', $corporate->id, [
            'vehicle_group_ids' => $vehicleGroupIds,
        ]);
    }

    public function removeVehicleGroup(Corporate $corporate, string $vehicleGroupId): void
    {
        $corporate->vehicleGroups()->detach($vehicleGroupId);

        $this->logAudit('remove_vehicle_group', 'Corporate', $corporate->id, [
            'vehicle_group_id' => $vehicleGroupId,
        ]);
    }

    // ─── Department CRUD ──────────────────────────────────────────────

    public function createDepartment(Corporate $corporate, array $data): CorporateDepartment
    {
        $data['corporate_id'] = $corporate->id;
        $data['is_active'] = $data['is_active'] ?? true;
        $department = CorporateDepartment::create($data);

        $this->logAudit('create', 'CorporateDepartment', $department->id, [
            'corporate_id' => $corporate->id,
            'name'         => $department->name,
        ]);

        return $department;
    }

    public function updateDepartment(CorporateDepartment $dept, array $data): CorporateDepartment
    {
        $before = $dept->only(array_keys($data));
        $dept->update($data);
        $dept->refresh();

        $this->logAudit('update', 'CorporateDepartment', $dept->id, [
            'before' => $before,
            'after'  => $dept->only(array_keys($data)),
        ]);

        return $dept;
    }

    public function deleteDepartment(CorporateDepartment $dept): void
    {
        $activeCount = $dept->employees()->where('is_active', true)->count();

        if ($activeCount > 0) {
            abort(409, "Cannot delete department. There are {$activeCount} active employees assigned to this department.");
        }

        $dept->delete(); // soft delete

        $this->logAudit('delete', 'CorporateDepartment', $dept->id, [
            'name' => $dept->name,
        ]);
    }

    // ─── Division CRUD ────────────────────────────────────────────────

    public function createDivision(CorporateDepartment $dept, array $data): CorporateDivision
    {
        $data['department_id'] = $dept->id;
        $data['is_active'] = $data['is_active'] ?? true;
        $division = CorporateDivision::create($data);

        $this->logAudit('create', 'CorporateDivision', $division->id, [
            'department_id' => $dept->id,
            'name'          => $division->name,
        ]);

        return $division;
    }

    public function updateDivision(CorporateDivision $div, array $data): CorporateDivision
    {
        $before = $div->only(array_keys($data));
        $div->update($data);
        $div->refresh();

        $this->logAudit('update', 'CorporateDivision', $div->id, [
            'before' => $before,
            'after'  => $div->only(array_keys($data)),
        ]);

        return $div;
    }

    public function deleteDivision(CorporateDivision $div): void
    {
        $activeCount = $div->employees()->where('is_active', true)->count();

        if ($activeCount > 0) {
            abort(409, "Cannot delete division. There are {$activeCount} active employees assigned to this division.");
        }

        $div->delete(); // soft delete

        $this->logAudit('delete', 'CorporateDivision', $div->id, [
            'name' => $div->name,
        ]);
    }

    // ─── Employee Management ──────────────────────────────────────────

    public function addEmployee(Corporate $corporate, array $data): CorporateEmployee
    {
        return DB::transaction(function () use ($corporate, $data) {
            [$department, $division] = $this->resolveEmployeeHierarchy(
                $corporate,
                $data['department_id'],
                $data['division_id'] ?? null
            );

            // Check if a user with this email already exists
            $user = User::where('email', $data['email'])->first();
            $isExistingUser = $user !== null;

            if (!$user) {
                $user = User::create([
                    'email'      => $data['email'],
                    'first_name' => $data['first_name'] ?? '',
                    'last_name'  => $data['last_name'] ?? '',
                    'phone'      => $data['phone'] ?? null,
                    'password'   => Hash::make($data['password'] ?? str()->random(16)),
                    'is_active'  => true,
                ]);
            }

            // Create CorporateEmployee record
            $employee = CorporateEmployee::create([
                'user_id'       => $user->id,
                'corporate_id'  => $corporate->id,
                'department_id' => $department->id,
                'division_id'   => $division?->id,
                'employee_code' => $data['employee_code'] ?? null,
                'is_active'     => true,
            ]);

            // Create UserContext with context_type='corporate'
            $userContext = UserContext::create([
                'user_id'      => $user->id,
                'context_type' => 'corporate',
                'context_id'   => $employee->id,
                'is_active'    => true,
            ]);

            // Assign role via Spatie through the UserContext roles relationship
            if (!empty($data['role'])) {
                $this->assignEmployeeRole($employee, $data['role']);
            }

            $this->logAudit('create', 'CorporateEmployee', $employee->id, [
                'corporate_id'   => $corporate->id,
                'user_id'        => $user->id,
                'email'          => $data['email'],
                'existing_user'  => $isExistingUser,
                'department_id'  => $department->id,
                'division_id'    => $division?->id,
                'role'           => $data['role'] ?? null,
            ]);

            return $employee;
        });
    }

    public function updateEmployee(CorporateEmployee $employee, array $data): CorporateEmployee
    {
        return DB::transaction(function () use ($employee, $data) {
            if (array_key_exists('department_id', $data) || array_key_exists('division_id', $data)) {
                $departmentId = $data['department_id'] ?? $employee->department_id;
                $divisionId = array_key_exists('division_id', $data) ? $data['division_id'] : $employee->division_id;

                [$department, $division] = $this->resolveEmployeeHierarchy(
                    $employee->corporate,
                    $departmentId,
                    $divisionId
                );

                $data['department_id'] = $department->id;
                $data['division_id'] = $division?->id;
            }

            $employeeUpdates = array_intersect_key($data, array_flip([
                'department_id', 'division_id', 'employee_code',
            ]));
            $userUpdates = array_intersect_key($data, array_flip([
                'first_name', 'last_name', 'phone',
            ]));

            $before = [
                'employee' => $employee->only(array_keys($employeeUpdates)),
                'user' => $employee->user?->only(array_keys($userUpdates)) ?? [],
            ];

            if (!empty($employeeUpdates)) {
                $employee->update($employeeUpdates);
            }

            if (!empty($userUpdates) && $employee->user) {
                $employee->user->update($userUpdates);
            }

            if (!empty($data['role'])) {
                $this->assignEmployeeRole($employee, $data['role']);
            }

            $employee->refresh()->load(['user', 'department', 'division', 'userContext.roles']);

            $this->logAudit('update', 'CorporateEmployee', $employee->id, [
                'before' => $before,
                'after'  => [
                    'employee' => $employee->only(array_keys($employeeUpdates)),
                    'user' => $employee->user?->only(array_keys($userUpdates)) ?? [],
                    'role' => $data['role'] ?? $employee->role,
                ],
            ]);

            return $employee;
        });
    }

    public function toggleEmployeeStatus(CorporateEmployee $employee): CorporateEmployee
    {
        return DB::transaction(function () use ($employee) {
            $employee->is_active = !$employee->is_active;
            $employee->save();

            // Also toggle the associated UserContext
            $userContext = $employee->userContext;
            if ($userContext) {
                $userContext->is_active = $employee->is_active;
                $userContext->save();
            }

            $this->logAudit(
                $employee->is_active ? 'activate' : 'deactivate',
                'CorporateEmployee',
                $employee->id,
                ['is_active' => $employee->is_active]
            );

            return $employee;
        });
    }

    public function assignEmployeeRole(CorporateEmployee $employee, string $roleName): void
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => 'api']
        );

        // Sync role on the employee's UserContext roles relationship
        $userContext = $employee->userContext;
        if ($userContext) {
            $userContext->roles()->sync([$role->id]);
        }

        // Also assign the role to the user via Spatie
        $user = $employee->user;
        if ($user && !$user->hasRole($roleName)) {
            $user->assignRole($roleName);
        }

        $this->logAudit('assign_role', 'CorporateEmployee', $employee->id, [
            'role' => $roleName,
        ]);
    }

    // ─── Audit Logging ────────────────────────────────────────────────

    private function resolveEmployeeHierarchy(
        Corporate $corporate,
        string $departmentId,
        ?string $divisionId = null
    ): array {
        $department = CorporateDepartment::where('corporate_id', $corporate->id)
            ->find($departmentId);

        if (!$department) {
            throw ValidationException::withMessages([
                'department_id' => 'Selected department does not belong to the chosen corporate.',
            ]);
        }

        $division = null;
        if ($divisionId) {
            $division = CorporateDivision::where('department_id', $department->id)
                ->find($divisionId);

            if (!$division) {
                throw ValidationException::withMessages([
                    'division_id' => 'Selected division does not belong to the chosen department.',
                ]);
            }
        }

        return [$department, $division];
    }

    private function logAudit(string $action, string $entity, ?string $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id'   => auth()->id(),
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'timestamp' => now(),
            'details'   => $details,
        ]);
    }
}
