<?php

namespace App\Services;

use App\Models\Corporate\Corporate;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CorporateRoleStarterService
{
    public const TEMPLATES = [
        'Corporate_Master_Admin' => [
            'label' => 'Master Administrator',
            'key' => 'Master_Administrator',
            'permissions' => [
                'manage-roles', 'manage_employees', 'manage_departments', 'manage_divisions',
                'create_bookings', 'create_bookings_for_others', 'view_all_bookings',
                'approve_bookings', 'view_payments', 'manage_billing', 'manage_rate_charts',
                'view_reports', 'schedule_reports', 'view_audit_log', 'corporate.view',
                'bookings.view', 'bookings.create', 'staff-transport.view',
                'staff-transport.manage', 'staff-transport.override', 'staff-transport.generate',
            ],
        ],
        'Transport_Coordinator' => [
            'label' => 'Transport Coordinator',
            'key' => 'Transport_Coordinator',
            'permissions' => [
                'corporate.view', 'manage_employees', 'create_bookings',
                'create_bookings_for_others', 'view_all_bookings', 'bookings.view',
                'bookings.create', 'staff-transport.view', 'staff-transport.manage',
                'staff-transport.override', 'staff-transport.generate',
            ],
        ],
        'Approval_Manager' => [
            'label' => 'Approval Manager',
            'key' => 'Approval_Manager',
            'permissions' => ['corporate.view', 'approve_bookings', 'view_all_bookings'],
        ],
        'Corporate_Employee' => [
            'label' => 'Employee',
            'key' => 'Employee',
            'permissions' => [
                'corporate.view', 'create_bookings', 'bookings.view',
                'bookings.create', 'staff-transport.view',
            ],
        ],
    ];

    /**
     * Create missing company roles and move legacy shared-role assignments.
     *
     * Existing company role permissions are never overwritten.
     *
     * @return array{roles_created: int, assignments_migrated: int}
     */
    public function provision(Corporate $corporate): array
    {
        return DB::transaction(function () use ($corporate): array {
            $created = 0;
            $migrated = 0;

            foreach (self::TEMPLATES as $legacyName => $template) {
                $role = Role::where('guard_name', 'api')
                    ->where('name', $this->roleName($corporate->id, $template['key']))
                    ->first();

                if (! $role) {
                    $role = Role::create([
                        'name' => $this->roleName($corporate->id, $template['key']),
                        'guard_name' => 'api',
                    ]);
                    $role->syncPermissions($this->permissions($template['permissions']));
                    $created++;
                } elseif ($template['key'] === 'Master_Administrator' || $template['key'] === 'Transport_Coordinator') {
                    $role->givePermissionTo($this->permissions(['manage-roles']));
                }

                $legacyRole = Role::where('guard_name', 'api')->where('name', $legacyName)->first();
                if (! $legacyRole) {
                    continue;
                }

                $contexts = DB::table('user_contexts')
                    ->join('corporate_employees', 'corporate_employees.id', '=', 'user_contexts.context_id')
                    ->join('user_context_roles', function ($join) use ($legacyRole) {
                        $join->on('user_context_roles.user_context_id', '=', 'user_contexts.id')
                            ->where('user_context_roles.role_id', '=', $legacyRole->id);
                    })
                    ->where('user_contexts.context_type', 'corporate')
                    ->where('corporate_employees.corporate_id', $corporate->id)
                    ->pluck('user_contexts.id');

                foreach ($contexts as $contextId) {
                    DB::table('user_context_roles')->where([
                        'role_id' => $legacyRole->id,
                        'user_context_id' => $contextId,
                    ])->delete();
                    DB::table('user_context_roles')->updateOrInsert([
                        'role_id' => $role->id,
                        'user_context_id' => $contextId,
                    ]);
                    $migrated++;
                }
            }

            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

            return ['roles_created' => $created, 'assignments_migrated' => $migrated];
        });
    }

    public function resolve(Corporate $corporate, string $requestedName): ?Role
    {
        $template = self::TEMPLATES[$requestedName] ?? null;
        if (! $template) {
            $normalized = str_replace([' ', '-'], '_', trim($requestedName));
            $template = collect(self::TEMPLATES)->first(
                fn (array $candidate) => in_array($normalized, [
                    $candidate['key'],
                    str_replace(' ', '_', $candidate['label']),
                ], true)
            );
        }
        $name = $template
            ? $this->roleName($corporate->id, $template['key'])
            : $requestedName;

        return Role::where('guard_name', 'api')
            ->where('name', $name)
            ->where('name', 'like', 'Corporate_'.$corporate->id.'_%')
            ->first();
    }

    public function roleName(string $corporateId, string $key): string
    {
        return 'Corporate_'.$corporateId.'_'.$key;
    }

    private function permissions(array $names): array
    {
        return collect($names)
            ->map(fn (string $name) => Permission::findOrCreate($name, 'api'))
            ->all();
    }
}
