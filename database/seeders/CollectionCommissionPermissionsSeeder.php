<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CollectionCommissionPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = [
            'collection-commissions.view',
            'collection-commissions.manage',
            'collection-commissions.pay',
        ];
        foreach (['web', 'api'] as $guard) {
            foreach ($permissions as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
            }
            Role::where(['name' => 'admin', 'guard_name' => $guard])->first()?->givePermissionTo($permissions);
            Role::where(['name' => 'accountant', 'guard_name' => $guard])->first()?->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
