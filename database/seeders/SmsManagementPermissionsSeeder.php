<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SmsManagementPermissionsSeeder extends Seeder
{
    /**
     * Seed granular SMS management permissions and map legacy communication permissions.
     */
    public function run(): void
    {
        $permissions = [
            'sms.overview.view',
            'sms.settings.view',
            'sms.settings.manage',
            'sms.sending.manage',
            'sms.campaigns.view',
            'sms.campaigns.manage',
            'sms.messages.view',
            'sms.messages.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $viewPermissions = [
            'sms.overview.view',
            'sms.settings.view',
            'sms.campaigns.view',
            'sms.messages.view',
        ];

        $managePermissions = [
            'sms.settings.manage',
            'sms.sending.manage',
            'sms.campaigns.manage',
            'sms.messages.manage',
        ];

        foreach (['web', 'api'] as $guard) {
            $roles = Role::where('guard_name', $guard)->get();

            foreach ($roles as $role) {
                $rolePermissionNames = $role->permissions()->pluck('name')->all();

                if (in_array('communication.view', $rolePermissionNames, true)) {
                    $role->givePermissionTo($viewPermissions);
                }

                if (in_array('communication.manage', $rolePermissionNames, true)) {
                    $role->givePermissionTo(array_merge($viewPermissions, $managePermissions));
                }
            }
        }
    }
}
