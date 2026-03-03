<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CorporatePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Corporate-scoped permissions for portal users
        $corporatePermissions = [
            'manage_employees',
            'manage_departments',
            'manage_divisions',
            'create_bookings',
            'create_bookings_for_others',
            'view_all_bookings',
            'approve_bookings',
            'view_payments',
            'manage_rate_charts',
            'view_reports',
        ];

        // System-level admin permissions for managing corporates
        $systemPermissions = [
            'corporates.view',
            'corporates.create',
            'corporates.edit',
            'corporates.manage',
            'corporate.view',   // Used by frontend navigation/context-switcher
            'corporate.create',
            'corporate.edit',
            'corporate.update',
            'corporate.delete',
            'corporate.manage',
        ];

        // Seed all permissions for both guards
        foreach (array_merge($corporatePermissions, $systemPermissions) as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        // Default corporate roles with their permission sets
        // All corporate roles get 'corporate.view' for navigation visibility
        $rolesPermissions = [
            'Corporate_Master_Admin' => array_merge($corporatePermissions, ['corporate.view']),

            'Transport_Coordinator' => [
                'corporate.view',
                'manage_employees',
                'create_bookings',
                'create_bookings_for_others',
                'view_all_bookings',
            ],

            'Approval_Manager' => [
                'corporate.view',
                'approve_bookings',
                'view_all_bookings',
            ],

            'Corporate_Employee' => [
                'corporate.view',
                'create_bookings',
            ],
        ];

        foreach ($rolesPermissions as $roleName => $perms) {
            $webRole = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $webRole->syncPermissions($perms);

            $apiRole = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
            $apiRole->syncPermissions($perms);
        }

        // Grant system-level corporate permissions to admin role
        $webAdmin = Role::where(['name' => 'admin', 'guard_name' => 'web'])->first();
        if ($webAdmin) {
            $webAdmin->givePermissionTo($systemPermissions);
        }

        $apiAdmin = Role::where(['name' => 'admin', 'guard_name' => 'api'])->first();
        if ($apiAdmin) {
            $apiAdmin->givePermissionTo($systemPermissions);
        }
    }
}
