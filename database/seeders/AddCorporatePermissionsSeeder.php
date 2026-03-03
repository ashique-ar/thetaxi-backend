<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * SAFE, ADDITIVE-ONLY seeder for corporate permissions.
 *
 * This seeder ONLY adds new permissions and roles using firstOrCreate.
 * It does NOT truncate any tables or remove existing assignments.
 * Safe to run on a live database at any time.
 *
 * Run with: php artisan db:seed --class=AddCorporatePermissionsSeeder
 */
class AddCorporatePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $corporatePortalPermissions = [
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
            'corporate.view',
        ];

        $systemAdminPermissions = [
            'corporates.view',
            'corporates.create',
            'corporates.edit',
            'corporates.update',
            'corporates.delete',
            'corporates.manage',
            'corporate.view',
            'corporate.create',
            'corporate.edit',
            'corporate.update',
            'corporate.delete',
            'corporate.manage',
        ];

        $allPermissions = array_unique(array_merge($corporatePortalPermissions, $systemAdminPermissions));

        foreach ($allPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $rolesPermissions = [
            'Corporate_Master_Admin' => $corporatePortalPermissions,
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
            foreach (['web', 'api'] as $guard) {
                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);
                $permModels = array_map(
                    fn($p) => Permission::firstOrCreate(['name' => $p, 'guard_name' => $guard]),
                    $perms
                );
                $role->givePermissionTo($permModels);
            }
        }

        foreach (['web', 'api'] as $guard) {
            $adminRole = Role::where(['name' => 'admin', 'guard_name' => $guard])->first();
            if ($adminRole) {
                $allAdminPerms = array_unique(array_merge($systemAdminPermissions, $corporatePortalPermissions));
                $permModels = array_map(
                    fn($p) => Permission::firstOrCreate(['name' => $p, 'guard_name' => $guard]),
                    $allAdminPerms
                );
                $adminRole->givePermissionTo($permModels);
            }

            $subAdminRole = Role::where(['name' => 'sub-admin', 'guard_name' => $guard])->first();
            if ($subAdminRole) {
                $permModels = array_map(
                    fn($p) => Permission::firstOrCreate(['name' => $p, 'guard_name' => $guard]),
                    $systemAdminPermissions
                );
                $subAdminRole->givePermissionTo($permModels);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Corporate permissions and roles added successfully (no existing data was modified).');
    }
}
