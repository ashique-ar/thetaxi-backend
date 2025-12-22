<?php

namespace App\Services;

use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\UserContext;
use App\Models\Customer;
use App\Models\Vehicle\VehicleOwner;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Exception;

class UserContextService
{
    /**
     * Switch user to a specific context
     *
     * @param User $user
     * @param string $contextType
     * @param array $contextData Additional data for context creation
     * @return UserContext
     * @throws Exception
     */
    public function switchContext(User $user, string $contextType, array $contextData = []): UserContext
    {
        DB::beginTransaction();
        
        try {
            // Check if context already exists
            $existingContext = $user->contexts()
                ->where('context_type', $contextType)
                ->where('is_active', true)
                ->first();

            if ($existingContext) {
                DB::commit();
                return $existingContext;
            }

            // Create context based on type
            $contextModel = $this->createContextModel($user, $contextType, $contextData);

            // Create user context record
            $userContext = UserContext::create([
                'user_id' => $user->id,
                'context_type' => $contextType,
                'context_id' => $contextModel->id,
                'is_active' => true,
                'created_user_id' => $user->id
            ]);

            // Assign appropriate role(s) if provided or by mapping
            $rolesToAssign = [];
            if (!empty($contextData['roles'])) {
                $rolesToAssign = $contextData['roles'];
            } else {
                // default mapping
                $mapped = $this->getDefaultRolesForContext($contextType);
                if ($mapped) $rolesToAssign = $mapped;
            }

            if (!empty($rolesToAssign)) {
                $this->assignRolesToContext($user, $userContext, $rolesToAssign);
            }

            DB::commit();
            return $userContext;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create the actual context model (Customer, VehicleOwner, etc.)
     */
    private function createContextModel(User $user, string $contextType, array $contextData)
    {
        switch ($contextType) {
            case 'customer':
                return Customer::firstOrCreate(
                    ['user_id' => $user->id],
                    array_merge([
                        'user_id' => $user->id,
                        'created_user_id' => $user->id
                    ], $contextData)
                );

            case 'vehicle_owner':
                return VehicleOwner::firstOrCreate(
                    ['user_id' => $user->id],
                    array_merge([
                        'user_id' => $user->id,
                        'created_user_id' => $user->id
                    ], $contextData)
                );
            case 'driver':
                return Driver::firstOrCreate(
                    ['user_id' => $user->id],
                    array_merge([
                        'user_id' => $user->id,
                        'created_user_id' => $user->id
                    ], $contextData)
                );

            default:
                throw new Exception("Invalid context type: {$contextType}");
        }
    }

    /**
     * Assign appropriate role for context
     */
    private function assignContextRole(User $user, string $contextType)
    {
        // Deprecated: older method kept for backward compatibility. Prefer assignRolesToContext.
        $roles = $this->getDefaultRolesForContext($contextType);
        if ($roles) {
            // We'll use assignRolesToContext which persists mapping
            $dummyContext = (object)['id' => null];
            $this->assignRolesToContext($user, $dummyContext, $roles);
        }
    }

    /**
     * Return default role names mapped from context type
     */
    private function getDefaultRolesForContext(string $contextType): array
    {
        $roleMap = [
            'customer' => ['customer'],
            'vehicle_owner' => ['vehicle-owner'],
            'staff' => ['staff'],
            'driver' => ['driver'],
            'agent' => ['agent']
        ];

        return $roleMap[$contextType] ?? [];
    }

    /**
     * Assign role(s) to a specific UserContext. Accepts role names or IDs.
     */
    public function assignRolesToContext(User $user, $userContext, array $roles): void
    {
        foreach ($roles as $roleSpec) {
            /** @var Role $roleModel */
            $roleModel = null;

            if (is_numeric($roleSpec)) {
                $roleModel = Role::find((int)$roleSpec);
            } else {
                $roleModel = Role::where('name', (string)$roleSpec)->first();
            }

            if (!$roleModel) continue;

            // Persist mapping in pivot table (avoid duplicates)
            $exists = DB::table('user_context_roles')
                ->where('user_context_id', $userContext->id)
                ->where('role_id', $roleModel->id)
                ->exists();

            if (!$exists) {
                DB::table('user_context_roles')->insert([
                    'user_context_id' => $userContext->id,
                    'role_id' => $roleModel->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Assign to user (if not already present)
            if (!$user->hasRole($roleModel->name)) {
                $user->assignRole($roleModel->name);
            }
        }
    }

    /**
     * Revoke all roles assigned by a given UserContext (and remove mapping entries).
     * Only revoke the role from the user when no other active contexts grant it.
     */
    public function revokeRolesFromContext(User $user, UserContext $userContext): void
    {
        $assigned = DB::table('user_context_roles')->where('user_context_id', $userContext->id)->get();

        foreach ($assigned as $row) {
            $this->revokeRoleFromContext($user, $userContext, $row->role_id);
        }
    }

    /**
     * Revoke a single role from a context (and possibly from the user if no other active contexts grant it)
     */
    public function revokeRoleFromContext(User $user, UserContext $userContext, int $roleId): void
    {
        $role = Role::find($roleId);
        if (!$role) return;

        // Remove mapping for this context and role
        DB::table('user_context_roles')
            ->where('user_context_id', $userContext->id)
            ->where('role_id', $roleId)
            ->delete();

        // Check if any other active contexts for this user have this role
        $other = DB::table('user_context_roles as ucr')
            ->join('user_contexts as uc', 'ucr.user_context_id', '=', 'uc.id')
            ->where('uc.user_id', $user->id)
            ->where('uc.is_active', true)
            ->where('ucr.role_id', $roleId)
            ->exists();

        if (!$other) {
            if ($user->hasRole($role->name)) {
                $user->removeRole($role->name);
            }
        }
    }

    /**
     * Get user's available contexts
     */
    public function getAvailableContexts(User $user): array
    {
        $contexts = [];

        // Check if user can be a customer
        if ($user->canActAsCustomer() || $user->hasRole(['staff', 'admin'])) {
            $contexts[] = [
                'type' => 'customer',
                'label' => 'Customer',
                'active' => $user->customerContext() !== null
            ];
        }

        // Check if user can be a vehicle owner
        if ($user->canActAsVehicleOwner() || $user->hasRole(['admin'])) {
            $contexts[] = [
                'type' => 'vehicle_owner',
                'label' => 'Vehicle Owner',
                'active' => $user->vehicleOwnerContext() !== null
            ];
        }

        if ($user->canActAsDriver() || $user->hasRole(['admin'])) {
            $contexts[] = [
                'type' => 'driver',
                'label' => 'Driver',
                'active' => $user->driverContext() !== null
            ];
        }

        // Add staff context if user has staff role
        if ($user->hasRole(['staff', 'admin'])) {
            $contexts[] = [
                'type' => 'staff',
                'label' => 'Staff',
                'active' => $user->staffContext() !== null
            ];
        }

        return $contexts;
    }

    /**
     * Deactivate a specific context (revokes roles assigned by that context only if no other active contexts grant them)
     */
    public function deactivateContext(User $user, string $contextType): bool
    {
        $contexts = $user->contexts()->where('context_type', $contextType)->where('is_active', true)->get();
        if ($contexts->isEmpty()) return false;

        DB::beginTransaction();
        try {
            foreach ($contexts as $context) {
                // Revoke roles assigned by this context where appropriate
                $this->revokeRolesFromContext($user, $context);

                // Mark context inactive
                $context->is_active = false;
                $context->save();
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get user's primary role (for backwards compatibility)
     */
    public function getPrimaryRole(User $user): string
    {
        // Priority order: admin > staff > agent > vehicle_owner > customer
        $rolePriority = ['admin', 'staff', 'agent', 'vehicle_owner', 'driver', 'customer'];

        $userRoles = $user->getRoleNames()->toArray();
        
        foreach ($rolePriority as $role) {
            if (in_array($role, $userRoles)) {
                return $role;
            }
        }
        
        return 'customer'; // Default fallback
    }
}
