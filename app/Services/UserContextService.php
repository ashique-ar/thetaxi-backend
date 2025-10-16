<?php

namespace App\Services;

use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\UserContext;
use App\Models\Customer;
use App\Models\Vehicle\VehicleOwner;
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

            // Assign appropriate role if not already assigned
            $this->assignContextRole($user, $contextType);

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
        $roleMap = [
            'customer' => 'customer',
            'vehicle_owner' => 'vehicle-owner', // You may need to create this role
            'staff' => 'staff',
            'driver' => 'driver',
            'agent' => 'agent'
        ];

        if (isset($roleMap[$contextType]) && !$user->hasRole($roleMap[$contextType])) {
            $user->assignRole($roleMap[$contextType]);
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
     * Deactivate a specific context
     */
    public function deactivateContext(User $user, string $contextType): bool
    {
        return $user->contexts()
            ->where('context_type', $contextType)
            ->update(['is_active' => false]);
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
