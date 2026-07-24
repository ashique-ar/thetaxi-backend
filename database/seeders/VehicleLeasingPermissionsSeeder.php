<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class VehicleLeasingPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'vehicle-leases.view',
            'vehicle-leases.create',
            'vehicle-leases.edit',
            'vehicle-leases.manage',
            'vehicle-leases.payments',
            'vehicle-leases.release',
        ];

        foreach (['web', 'api'] as $guard) {
            foreach ($permissions as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
            }

            Role::where(['name' => 'admin', 'guard_name' => $guard])
                ->first()?->givePermissionTo($permissions);
            Role::where(['name' => 'fleet-manager', 'guard_name' => $guard])
                ->first()?->givePermissionTo([
                    'vehicle-leases.view',
                    'vehicle-leases.create',
                    'vehicle-leases.edit',
                    'vehicle-leases.manage',
                    'vehicle-leases.release',
                ]);
            Role::where(['name' => 'accountant', 'guard_name' => $guard])
                ->first()?->givePermissionTo([
                    'vehicle-leases.view',
                    'vehicle-leases.payments',
                ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
