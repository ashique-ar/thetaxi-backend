<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Models\Vehicle\VehicleOwner;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserContextService
{
    private const INTERNAL_PERMISSION_HINTS = [
        'dashboard.view',
        'users.view',
        'bookings.view',
        'vehicles.view',
        'customers.view',
        'drivers.view',
        'staff.view',
        'agents.view',
        'reports.view',
        'corporates.view',
    ];

    private const ROLE_CONTEXT_MAP = [
        'customer' => ['customer'],
        'driver' => ['driver'],
        'staff' => ['staff'],
        'agent' => ['agent'],
        'vehicle-owner' => ['vehicle_owner'],
        'vehicle_owner' => ['vehicle_owner'],
    ];

    /**
     * Switch user to a specific context.
     *
     * For database-backed contexts, this activates or creates the context record.
     * Context selection itself is request-scoped and handled by resolveActiveContext.
     *
     * @param User $user
     * @param string $contextType
     * @param array $contextData
     * @return UserContext
     * @throws Exception
     */
    public function switchContext(User $user, string $contextType, array $contextData = []): UserContext
    {
        DB::beginTransaction();

        try {
            $selectedContextId = $contextData['context_id'] ?? $contextData['selected_context_id'] ?? null;
            unset($contextData['context_id'], $contextData['selected_context_id']);

            $existingContextQuery = $user->contexts()
                ->where('context_type', $contextType)
                ->where('is_active', true);

            if ($selectedContextId) {
                $existingContextQuery->where('id', $selectedContextId);
            }

            $existingContext = $existingContextQuery->first();

            if ($existingContext) {
                $this->restoreOrCreateContextModel($user, $existingContext, $contextData);

                $rolesToAssign = !empty($contextData['roles'])
                    ? $contextData['roles']
                    : $this->getDefaultRolesForContext($contextType);

                if (!empty($rolesToAssign)) {
                    $this->assignRolesToContext($user, $existingContext, $rolesToAssign);
                }

                DB::commit();
                return $existingContext;
            }

            $inactiveContextQuery = $user->contexts()
                ->where('context_type', $contextType)
                ->where('is_active', false);

            if ($selectedContextId) {
                $inactiveContextQuery->where('id', $selectedContextId);
            }

            $inactiveContext = $inactiveContextQuery->latest('updated_at')->first();

            if ($inactiveContext) {
                $this->restoreOrCreateContextModel($user, $inactiveContext, $contextData);
                $inactiveContext->update(['is_active' => true]);

                $preservedRoleIds = $inactiveContext->roles()->pluck('roles.id')->all();
                $rolesToAssign = array_values(array_unique(array_merge(
                    $preservedRoleIds,
                    !empty($contextData['roles'])
                        ? $contextData['roles']
                        : $this->getDefaultRolesForContext($contextType)
                )));

                if ($rolesToAssign) {
                    $this->assignRolesToContext($user, $inactiveContext, $rolesToAssign);
                }

                DB::commit();
                return $inactiveContext;
            }

            $contextModel = $this->createContextModel($user, $contextType, $contextData);

            $userContext = UserContext::create([
                'user_id' => $user->id,
                'context_type' => $contextType,
                'context_id' => $contextModel->id,
                'is_active' => true,
                'created_user_id' => $user->id,
            ]);

            $rolesToAssign = !empty($contextData['roles'])
                ? $contextData['roles']
                : $this->getDefaultRolesForContext($contextType);

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
     * Return selectable contexts for the signed-in experience.
     */
    public function getAvailableContexts(User $user): array
    {
        return $this->getSelectableContexts($user);
    }

    /**
     * Return activation candidates for admin user management screens.
     */
    public function getActivatableContexts(User $user): array
    {
        $contexts = [];
        $activeContextTypes = $user->contexts()
            ->where('is_active', true)
            ->pluck('context_type')
            ->toArray();
        $inactiveContextTypes = $user->contexts()
            ->where('is_active', false)
            ->pluck('context_type')
            ->toArray();

        if (($user->hasRole(['customer', 'staff', 'admin']) || in_array('customer', $inactiveContextTypes, true)) && !in_array('customer', $activeContextTypes, true)) {
            $contexts[] = [
                'value' => 'customer',
                'label' => 'Customer',
                'active' => true,
                'is_currently_active' => false,
                'is_reactivation' => in_array('customer', $inactiveContextTypes, true),
            ];
        }

        if (($user->hasRole(['admin']) || in_array('vehicle_owner', $inactiveContextTypes, true)) && !in_array('vehicle_owner', $activeContextTypes, true)) {
            $contexts[] = [
                'value' => 'vehicle_owner',
                'label' => 'Vehicle Owner',
                'active' => true,
                'is_currently_active' => false,
                'is_reactivation' => in_array('vehicle_owner', $inactiveContextTypes, true),
            ];
        }

        if (($user->hasRole(['driver', 'admin']) || in_array('driver', $inactiveContextTypes, true)) && !in_array('driver', $activeContextTypes, true)) {
            $contexts[] = [
                'value' => 'driver',
                'label' => 'Driver',
                'active' => true,
                'is_currently_active' => false,
                'is_reactivation' => in_array('driver', $inactiveContextTypes, true),
            ];
        }

        if (($user->hasRole(['staff', 'admin']) || in_array('staff', $inactiveContextTypes, true)) && !in_array('staff', $activeContextTypes, true)) {
            $contexts[] = [
                'value' => 'staff',
                'label' => 'Staff',
                'active' => true,
                'is_currently_active' => false,
                'is_reactivation' => in_array('staff', $inactiveContextTypes, true),
            ];
        }

        return $contexts;
    }

    /**
     * Build the context state payload used by auth/profile and /contexts endpoints.
     */
    public function buildUserContextState(
        User $user,
        ?string $requestedContextType = null,
        ?string $requestedContextId = null,
        ?string $requestedPortalProfile = null
    ): array {
        $contexts = $this->getSelectableContexts($user);
        $activeContext = $this->resolveActiveContext(
            $user,
            $requestedContextType,
            $requestedContextId,
            $requestedPortalProfile,
            $contexts
        );

        $activeKey = $activeContext
            ? $this->contextKey($activeContext['context_type'], $activeContext['id'])
            : null;

        $contexts = array_map(function (array $context) use ($activeKey) {
            $context['is_currently_active'] = $activeKey !== null
                && $this->contextKey($context['context_type'], $context['id']) === $activeKey;

            return $context;
        }, $contexts);

        if ($activeKey !== null) {
            foreach ($contexts as $context) {
                if ($this->contextKey($context['context_type'], $context['id']) === $activeKey) {
                    $activeContext = $context;
                    break;
                }
            }
        }

        return [
            'contexts' => $contexts,
            'available_contexts' => $contexts,
            'active_context' => $activeContext,
            'primary_role' => $this->getPrimaryRole($user),
            'has_multiple_contexts' => count($contexts) > 1,
        ];
    }

    /**
     * Resolve the currently selected context using request headers and fallbacks.
     */
    public function resolveActiveContextFromRequest(User $user, ?Request $request = null, ?array $contexts = null): ?array
    {
        return $this->resolveActiveContext(
            $user,
            $request?->header('X-Active-Context-Type'),
            $request?->header('X-Active-Context-Id'),
            $request?->header('X-Active-Portal-Profile'),
            $contexts
        );
    }

    /**
     * Resolve a single active context summary from a selectable context list.
     */
    public function resolveActiveContext(
        User $user,
        ?string $requestedContextType = null,
        ?string $requestedContextId = null,
        ?string $requestedPortalProfile = null,
        ?array $contexts = null
    ): ?array {
        $contexts = $contexts ?? $this->getSelectableContexts($user);

        if (empty($contexts)) {
            return null;
        }

        if ($requestedContextType && $requestedContextId) {
            $matched = $this->findSelectableContext($contexts, $requestedContextType, $requestedContextId);
            if ($matched) {
                return $matched;
            }
        }

        if ($requestedPortalProfile) {
            $matched = collect($contexts)
                ->first(fn (array $context) => $context['portal_profile'] === $requestedPortalProfile);

            if ($matched) {
                return $matched;
            }
        }

        if ($requestedContextType) {
            $matched = collect($contexts)->first(function (array $context) use ($requestedContextType) {
                return $context['context_type'] === $requestedContextType
                    || $context['portal_profile'] === $requestedContextType;
            });

            if ($matched) {
                return $matched;
            }
        }

        $priority = ['internal', 'corporate', 'agent', 'customer', 'driver', 'vehicle_owner'];

        foreach ($priority as $profile) {
            $matched = collect($contexts)
                ->first(fn (array $context) => $context['portal_profile'] === $profile);

            if ($matched) {
                return $matched;
            }
        }

        return $contexts[0] ?? null;
    }

    /**
     * Assign role(s) to a specific UserContext. Accepts role names or IDs.
     */
    public function assignRolesToContext(User $user, $userContext, array $roles): void
    {
        foreach ($roles as $roleSpec) {
            $roleModel = null;

            if (is_numeric($roleSpec)) {
                $roleModel = Role::find((int) $roleSpec);
            } else {
                $roleModel = Role::where('name', (string) $roleSpec)->first();
            }

            if (!$roleModel || !$userContext?->id) {
                continue;
            }

            $exists = DB::table('user_context_roles')
                ->where('user_context_id', $userContext->id)
                ->where('role_id', $roleModel->id)
                ->exists();

            if (!$exists) {
                DB::table('user_context_roles')->insert([
                    'user_context_id' => $userContext->id,
                    'role_id' => $roleModel->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (!$user->hasRole($roleModel->name)) {
                $user->assignRole($roleModel->name);
            }
        }
    }

    /**
     * Apply context mappings defined on roles to a user.
     *
     * A null context_types value means use the legacy role-name mapping.
     * An empty context_types array means the role should not create/link contexts.
     */
    public function syncContextsForAssignedRoles(User $user, Collection|array $roles, ?string $actorUserId = null): void
    {
        $actorUserId ??= $user->id;

        foreach (collect($roles) as $role) {
            if (!$role instanceof Role || ($role->auto_assign_contexts ?? true) === false) {
                continue;
            }

            foreach ($this->getContextTypesForRole($role) as $contextType) {
                $context = $this->resolveOrCreateContextForRole($user, $contextType, $actorUserId);

                if ($context) {
                    $this->assignRolesToContext($user, $context, [$role->id]);
                }
            }
        }
    }

    /**
     * Revoke all roles assigned by a given UserContext.
     */
    public function revokeRolesFromContext(User $user, UserContext $userContext): void
    {
        $assigned = DB::table('user_context_roles')
            ->where('user_context_id', $userContext->id)
            ->get();

        foreach ($assigned as $row) {
            $this->revokeRoleFromContext($user, $userContext, $row->role_id);
        }
    }

    /**
     * Revoke a single role from a context.
     */
    public function revokeRoleFromContext(User $user, UserContext $userContext, int $roleId): void
    {
        $role = Role::find($roleId);
        if (!$role) {
            return;
        }

        DB::table('user_context_roles')
            ->where('user_context_id', $userContext->id)
            ->where('role_id', $roleId)
            ->delete();

        $other = DB::table('user_context_roles as ucr')
            ->join('user_contexts as uc', 'ucr.user_context_id', '=', 'uc.id')
            ->where('uc.user_id', $user->id)
            ->where('uc.is_active', true)
            ->where('ucr.role_id', $roleId)
            ->exists();

        if (!$other && $user->hasRole($role->name)) {
            $user->removeRole($role->name);
        }
    }

    /**
     * Deactivate a specific context.
     */
    public function deactivateContext(User $user, string $contextType): bool
    {
        $contexts = $user->contexts()
            ->where('context_type', $contextType)
            ->where('is_active', true)
            ->get();

        if ($contexts->isEmpty()) {
            return false;
        }

        DB::beginTransaction();

        try {
            foreach ($contexts as $context) {
                $context->is_active = false;
                $context->save();
                $this->suspendGlobalRolesForContext($user, $context);
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function suspendGlobalRolesForContext(User $user, UserContext $userContext): void
    {
        $roleIds = $userContext->roles()->pluck('roles.id');

        foreach ($roleIds as $roleId) {
            $role = Role::find($roleId);
            if (!$role) {
                continue;
            }

            $usedByAnotherActiveContext = DB::table('user_context_roles as ucr')
                ->join('user_contexts as uc', 'ucr.user_context_id', '=', 'uc.id')
                ->where('uc.user_id', $user->id)
                ->where('uc.is_active', true)
                ->where('ucr.role_id', $roleId)
                ->exists();

            if (!$usedByAnotherActiveContext && $user->hasRole($role->name)) {
                $user->removeRole($role->name);
            }
        }
    }

    /**
     * Get user's primary role.
     */
    public function getPrimaryRole(User $user): string
    {
        $rolePriority = ['admin', 'staff', 'agent', 'driver', 'customer', 'vehicle_owner', 'vehicle-owner'];
        $userRoles = $user->getRoleNames()->toArray();

        foreach ($rolePriority as $role) {
            if (in_array($role, $userRoles, true)) {
                return $role;
            }
        }

        if ($user->contexts()->where('context_type', 'corporate')->where('is_active', true)->exists()) {
            return 'corporate';
        }

        if ($this->canAccessInternalPortal($user)) {
            return 'internal';
        }

        return 'customer';
    }

    /**
     * Create the actual context model.
     */
    private function createContextModel(User $user, string $contextType, array $contextData)
    {
        switch ($contextType) {
            case 'customer':
                return Customer::firstOrCreate(
                    ['user_id' => $user->id],
                    array_merge([
                        'user_id' => $user->id,
                        'created_user_id' => $user->id,
                    ], $contextData)
                );

            case 'vehicle_owner':
                return VehicleOwner::firstOrCreate(
                    ['user_id' => $user->id],
                    array_merge([
                        'user_id' => $user->id,
                        'created_user_id' => $user->id,
                    ], $contextData)
                );

            case 'driver':
                return Driver::firstOrCreate(
                    ['user_id' => $user->id],
                    array_merge([
                        'user_id' => $user->id,
                        'created_user_id' => $user->id,
                    ], $contextData)
                );

            case 'staff':
                return Staff::firstOrCreate(
                    ['user_id' => $user->id],
                    array_merge([
                        'user_id' => $user->id,
                        'staff_type' => 'staff',
                        'created_user_id' => $user->id,
                    ], $contextData)
                );

            default:
                throw new Exception("Invalid context type: {$contextType}");
        }
    }

    private function restoreOrCreateContextModel(
        User $user,
        UserContext $userContext,
        array $contextData = []
    ): void {
        $modelClass = match ($userContext->context_type) {
            'customer' => Customer::class,
            'vehicle_owner' => VehicleOwner::class,
            'driver' => Driver::class,
            'staff' => Staff::class,
            default => null,
        };

        if (!$modelClass) {
            return;
        }

        $contextModel = $modelClass::withTrashed()->find($userContext->context_id)
            ?? $modelClass::withTrashed()->where('user_id', $user->id)->first();

        if (!$contextModel) {
            $contextModel = $this->createContextModel($user, $userContext->context_type, $contextData);
        } else {
            if (method_exists($contextModel, 'trashed') && $contextModel->trashed()) {
                $contextModel->restore();
            }

            $profileData = collect($contextData)
                ->except(['roles', 'context_id', 'selected_context_id'])
                ->all();
            if ($profileData) {
                $contextModel->fill($profileData)->save();
            }
        }

        if ((string) $userContext->context_id !== (string) $contextModel->id) {
            $userContext->update(['context_id' => $contextModel->id]);
        }
    }

    private function contextProfileState(UserContext $userContext): array
    {
        $modelClass = match ($userContext->context_type) {
            'customer' => Customer::class,
            'vehicle_owner' => VehicleOwner::class,
            'driver' => Driver::class,
            'staff' => Staff::class,
            default => null,
        };

        if (!$modelClass) {
            return ['missing' => false, 'deleted' => false];
        }

        $contextModel = $modelClass::withTrashed()->find($userContext->context_id);

        return [
            'missing' => !$contextModel,
            'deleted' => $contextModel && method_exists($contextModel, 'trashed')
                ? $contextModel->trashed()
                : false,
        ];
    }

    private function getContextTypesForRole(Role $role): array
    {
        $contextTypes = $role->context_types ?? null;

        if (is_string($contextTypes)) {
            $decoded = json_decode($contextTypes, true);
            $contextTypes = is_array($decoded) ? $decoded : null;
        }

        if ($contextTypes === null) {
            $contextTypes = self::ROLE_CONTEXT_MAP[$role->name] ?? [];
        }

        return collect($contextTypes)
            ->filter(fn ($contextType) => is_string($contextType) && $contextType !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function resolveOrCreateContextForRole(User $user, string $contextType, string $actorUserId): ?UserContext
    {
        if (in_array($contextType, ['internal', 'agent', 'corporate'], true)) {
            return null;
        }

        $context = $user->contexts()
            ->where('context_type', $contextType)
            ->where('is_active', true)
            ->first();

        if ($context) {
            return $context;
        }

        $contextModel = $this->createContextModel($user, $contextType, [
            'created_user_id' => $actorUserId,
        ]);

        return UserContext::create([
            'user_id' => $user->id,
            'context_type' => $contextType,
            'context_id' => $contextModel->id,
            'is_active' => true,
            'created_user_id' => $actorUserId,
        ]);
    }

    /**
     * Return default role names mapped from context type.
     */
    private function getDefaultRolesForContext(string $contextType): array
    {
        $roleMap = [
            'customer' => ['customer'],
            'vehicle_owner' => ['vehicle-owner'],
            'staff' => ['staff'],
            'driver' => ['driver'],
            'agent' => ['agent'],
        ];

        return $roleMap[$contextType] ?? [];
    }

    /**
     * Get selectable contexts for the current user.
     */
    private function getSelectableContexts(User $user): array
    {
        $user->loadMissing([
            'roles.permissions',
            'contexts.roles.permissions',
            'contexts.corporateEmployee.user',
            'contexts.corporateEmployee.corporate',
            'contexts.corporateEmployee.department',
            'contexts.corporateEmployee.division',
        ]);

        $contexts = collect();

        if ($this->canAccessInternalPortal($user)) {
            $contexts->push($this->buildVirtualContextSummary($user, 'internal'));
        }

        if ($this->canAccessAgentPortal($user) && !$user->contexts->where('is_active', true)->contains('context_type', 'agent')) {
            $contexts->push($this->buildVirtualContextSummary($user, 'agent'));
        }

        foreach ($user->contexts->where('is_active', true) as $userContext) {
            $summary = $this->buildDatabaseContextSummary($user, $userContext);

            if ($summary) {
                $contexts->push($summary);
            }
        }

        return $contexts
            ->unique(fn (array $context) => $this->contextKey($context['context_type'], $context['id']))
            ->values()
            ->all();
    }

    /**
     * Build a context summary for virtual profiles such as internal.
     */
    private function buildVirtualContextSummary(User $user, string $contextType): array
    {
        $portalProfile = $this->portalProfileForContextType($contextType);
        $roles = $this->formatRoleCollection($user->roles instanceof Collection ? $user->roles : collect($user->roles));
        $permissions = $this->formatPermissionCollection($user->getAllPermissions());

        return [
            'id' => $contextType,
            'context_type' => $contextType,
            'context_id' => null,
            'label' => match ($contextType) {
                'agent' => 'Agent Portal',
                default => 'Internal Portal',
            },
            'description' => match ($contextType) {
                'agent' => 'Agency and API access',
                default => 'Operations and administration',
            },
            'portal_profile' => $portalProfile,
            'route' => $this->routeForPortalProfile($portalProfile),
            'icon' => $this->iconForPortalProfile($portalProfile),
            'is_active' => true,
            'is_virtual' => true,
            'roles' => $roles,
            'permissions' => $permissions,
            'metadata' => [
                'source' => 'virtual',
            ],
        ];
    }

    /**
     * Build a summary for a database-backed UserContext.
     */
    private function buildDatabaseContextSummary(User $user, UserContext $userContext): ?array
    {
        if (!$userContext->is_active) {
            return null;
        }

        $portalProfile = $this->portalProfileForContextType($userContext->context_type);

        if ($portalProfile === 'internal') {
            return null;
        }

        $roles = $this->extractContextRoles($userContext);
        $permissions = $this->extractContextPermissions($user, $userContext);

        $label = match ($userContext->context_type) {
            'corporate' => $userContext->corporateEmployee?->corporate?->name ?? 'Corporate Portal',
            'customer' => 'Customer Portal',
            'driver' => 'Driver Portal',
            'vehicle_owner' => 'Vehicle Owner Portal',
            'agent' => 'Agent Portal',
            default => ucfirst(str_replace('_', ' ', $userContext->context_type)),
        };

        $description = match ($userContext->context_type) {
            'corporate' => $this->buildCorporateContextDescription($userContext),
            'customer' => 'Personal bookings and profile',
            'driver' => 'Trips, assignments and profile',
            'vehicle_owner' => 'Vehicle owner profile and activity',
            'agent' => 'Agency profile and operations',
            default => null,
        };

        $profileState = $this->contextProfileState($userContext);

        return [
            'id' => (string) $userContext->id,
            'context_type' => $userContext->context_type,
            'context_id' => $userContext->context_id ? (string) $userContext->context_id : null,
            'label' => $label,
            'description' => $description,
            'portal_profile' => $portalProfile,
            'route' => $this->routeForPortalProfile($portalProfile),
            'icon' => $this->iconForPortalProfile($portalProfile),
            'is_active' => true,
            'is_virtual' => false,
            'roles' => $roles,
            'permissions' => $permissions,
            'metadata' => [
                'user_context_id' => (string) $userContext->id,
                'role_count' => count($roles),
                'profile_missing' => $profileState['missing'],
                'profile_deleted' => $profileState['deleted'],
                'profile_needs_restore' => $profileState['deleted'],
            ],
        ];
    }

    /**
     * Extract roles attached to a user context.
     */
    private function extractContextRoles(UserContext $userContext): array
    {
        $roles = $userContext->relationLoaded('roles')
            ? $userContext->roles
            : $userContext->roles()->get();

        return $this->formatRoleCollection($roles);
    }

    /**
     * Extract permissions attached to a user context.
     */
    private function extractContextPermissions(User $user, UserContext $userContext): array
    {
        $roles = $userContext->relationLoaded('roles')
            ? $userContext->roles
            : $userContext->roles()->with('permissions')->get();

        $permissions = $roles
            ->flatMap(fn ($role) => $role->permissions ?? collect())
            ->filter()
            ->unique('id')
            ->values();

        if ($permissions->isNotEmpty()) {
            return $this->formatPermissionCollection($permissions);
        }

        if ($this->shouldFallbackToGlobalPermissions($user, $userContext)) {
            return $this->formatPermissionCollection($user->getAllPermissions());
        }

        return [];
    }

    /**
     * Global permission fallback is only safe when there is a single context of that type.
     */
    private function shouldFallbackToGlobalPermissions(User $user, UserContext $userContext): bool
    {
        if ($userContext->context_type === 'corporate') {
            $activeCorporateCount = $user->relationLoaded('contexts')
                ? $user->contexts->where('is_active', true)->where('context_type', 'corporate')->count()
                : $user->contexts()->where('is_active', true)->where('context_type', 'corporate')->count();

            return $activeCorporateCount <= 1;
        }

        return true;
    }

    /**
     * Find a selectable context by type and id.
     */
    private function findSelectableContext(array $contexts, string $contextType, string $contextId): ?array
    {
        return collect($contexts)->first(function (array $context) use ($contextType, $contextId) {
            return $context['context_type'] === $contextType
                && (string) $context['id'] === (string) $contextId;
        });
    }

    /**
     * Determine if the user can access the internal portal.
     */
    private function canAccessInternalPortal(User $user): bool
    {
        if ($user->hasRole(['admin', 'staff'])) {
            return true;
        }

        if ($user->contexts()->where('context_type', 'staff')->where('is_active', true)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can access the agent portal profile.
     */
    private function canAccessAgentPortal(User $user): bool
    {
        if ($user->hasRole('agent') || $user->agent_id) {
            return true;
        }

        return $user->contexts()->where('context_type', 'agent')->where('is_active', true)->exists();
    }

    /**
     * Map context type to a portal profile.
     */
    private function portalProfileForContextType(string $contextType): string
    {
        return match ($contextType) {
            'internal', 'staff' => 'internal',
            'corporate' => 'corporate',
            'customer' => 'customer',
            'driver' => 'driver',
            'vehicle_owner' => 'vehicle_owner',
            'agent' => 'agent',
            default => 'internal',
        };
    }

    /**
     * Portal landing route.
     */
    private function routeForPortalProfile(string $portalProfile): string
    {
        return match ($portalProfile) {
            'corporate' => '/corporate/dashboard',
            'customer', 'driver', 'vehicle_owner', 'agent' => '/user',
            default => '/admin/dashboard',
        };
    }

    /**
     * Portal icon.
     */
    private function iconForPortalProfile(string $portalProfile): string
    {
        return match ($portalProfile) {
            'corporate' => 'heroicons_outline:building-office-2',
            'customer' => 'heroicons_outline:users',
            'driver' => 'heroicons_outline:user-circle',
            'vehicle_owner' => 'heroicons_outline:truck',
            'agent' => 'heroicons_outline:rectangle-group',
            default => 'heroicons_outline:computer-desktop',
        };
    }

    /**
     * Build a friendly corporate context description.
     */
    private function buildCorporateContextDescription(UserContext $userContext): ?string
    {
        $employee = $userContext->corporateEmployee;

        if (!$employee) {
            return null;
        }

        $parts = [];

        if ($employee->user?->full_name) {
            $parts[] = $employee->user->full_name;
        }

        $roles = $userContext->relationLoaded('roles')
            ? $userContext->roles
            : $userContext->roles()->get();

        $roleName = $roles
            ->map(fn ($role) => $role->display_name ?? $role->name)
            ->filter()
            ->first();

        if ($roleName) {
            $parts[] = $roleName;
        }

        if ($employee->department?->name) {
            $parts[] = $employee->department->name;
        }

        return empty($parts) ? null : implode(' | ', $parts);
    }

    /**
     * Normalize roles for the frontend.
     */
    private function formatRoleCollection($roles): array
    {
        return collect($roles)
            ->filter()
            ->unique('id')
            ->map(function ($role) {
                return [
                    'id' => (string) $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name ?? $role->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Normalize permissions for the frontend.
     */
    private function formatPermissionCollection($permissions): array
    {
        return collect($permissions)
            ->filter()
            ->unique('id')
            ->map(function ($permission) {
                return [
                    'id' => (string) $permission->id,
                    'name' => $permission->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build a stable context key.
     */
    private function contextKey(string $contextType, string $id): string
    {
        return $contextType . '::' . $id;
    }
}
