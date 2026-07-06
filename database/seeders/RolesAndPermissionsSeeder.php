<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use DB;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        DB::table('role_has_permissions')->truncate();
        DB::table('model_has_roles')->truncate();
        DB::table('model_has_permissions')->truncate();
        DB::table('roles')->truncate();
        DB::table('permissions')->truncate();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Final cleaned and merged list of resources
        $resources = [
            'admin',
            'agent-api-sessions',
            'agent-apis',
            'agent-commissions',
            'agents',
            'analytics',
            'booking-addons',
            'booking-channels',
            'booking-items',
            'booking-statuses',
            'bookings',
            'business-settings',
            'cms-content-types',
            'cms-contents',
            'communication',
            'companies',
            'countries',
            'currencies',
            'customers',
            'addons',
            'customer-loyalty',
            'customer-feedback',
            'customer-documents',
            'dashboard',
            'driver-logs',
            'drivers',
            'driving-license-types',
            'driving-licenses',
            'email-templates',
            'gamification',
            'image-galleries',
            'inquiries',
            'loyalty',
            'maintenance-records',
            'maintenance-schedules',
            'manage-roles',
            'notification-logs',
            'notification-templates',
            'notifications',
            'payments',
            'permissions',
            'phone-calls',
            'pricing-slabs',
            'quotations',
            'regions',
            'reports',
            'roles',
            'service-types',
            'sms-templates',
            'staff',
            'staff-performance',
            'staff-schedule',
            'states',
            'system',
            'users',
            'vehicle-addons',
            'vehicle-categories',
            'vehicle-classes',
            'vehicle-common-rates',
            'vehicle-contract-types',
            'vehicle-discounts',
            'vehicle-distance-multipliers',
            'vehicle-fuel-types',
            'vehicle-grades',
            'vehicle-groups',
            'vehicle-images',
            'vehicle-insurance-providers',
            'vehicle-service-types',
            'vehicle-group-pricing',
            'vehicle-pricing-common-rates',
            'vehicle-insurance-types',
            'vehicle-insurances',
            'vehicle-maintenance',
            'vehicle-maintenance-records',
            'vehicle-maintenance-schedules',
            'vehicle-makes',
            'vehicle-models',
            'vehicle-owner-types',
            'vehicle-owners',
            'vehicle-pricing',
            'vehicle-pricing-slab-rates',
            'vehicle-pricing-slabs',
            'vehicle-transmissions',
            'vehicles',
            'vip-types',
            'driver-assignments',
            'logsheets',
            'uploads',
            'agreements',
            'website-settings',
            'call-logs',
            'customer-gamification',
            'staff-assignments',
            'agreement-templates',
            'agreement-signing',
            'medical-records',
            'return-inspection',
            'vehicle-availability',
            'gallery',
            'popup',
            'promo-code',
        ];

        $ops = ['view', 'create', 'edit', 'update', 'delete', 'manage'];
        $permissions = [];

        foreach ($resources as $res) {
            foreach ($ops as $op) {
                $permissions[] = "$res.$op";
            }
        }

        $permissions = array_unique($permissions);

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $rolesPermissions = [
            'admin' => $permissions,

            'sub-admin' => array_filter($permissions, fn($p) => !preg_match(
                '/^(roles|permissions|system)\./',
                $p
            )),

            'data-entry' => array_filter($permissions, fn($p) => preg_match(
                '/^(vehicles|vehicle-categories|vehicle-classes|vehicle-common-rates|vehicle-contract-types|vehicle-maintenance-records|vehicle-maintenance-schedules|vehicle-discounts|vehicle-addons|service-types|customers|inquiries|image-galleries)\./',
                $p
            )),

            'customer' => [
                'bookings.view',
                'bookings.create',
                'bookings.edit',
                'bookings.delete',
                'quotations.view',
                'quotations.create',
                'quotations.edit',
                'quotations.delete',
                'inquiries.view',
                'inquiries.create',
                'inquiries.edit',
                'inquiries.delete',
                'uploads.view',
                'uploads.create',
                'uploads.edit',
                'uploads.delete',
            ],

            'management' => array_merge(
                array_filter($permissions, fn($p) => preg_match(
                    '/^(bookings|customers|inquiries|quotations|image-galleries|popup|promo-code)\./',
                    $p
                )),
                ['reports.view', 'analytics.view']
            ),

            'accountant' => array_filter($permissions, fn($p) => preg_match(
                '/^(payments)\./',
                $p
            )),

            'rep-marketing' => [
                'customers.view',
                'customers.create',
                'customers.edit',
                'customers.delete',
                'quotations.view',
                'quotations.create',
                'quotations.edit',
                'quotations.delete',
                'bookings.view',
                'bookings.create',
                'bookings.edit',
                'bookings.delete',
                'inquiries.view',
                'inquiries.create',
            ],

            'driver-coordinator' => [
                'driver-logs.view',
                'driver-logs.create',
                'driver-logs.edit',
                'driver-logs.delete',
                'driving-licenses.view',
                'driving-licenses.create',
                'driving-licenses.edit',
                'driving-licenses.delete',
            ],

            'driver' => [
                'driver-logs.view',
                'driver-logs.create',
                'driving-licenses.view',
            ],

            'agent' => [
                'bookings.view',
                'bookings.create',
                'agent-commissions.view',
            ],

            'corporate' => [
                'bookings.view',
                'bookings.create',
                'vehicle-discounts.view',
            ],

            'vehicle-owner' => [
                'vehicles.view',
                'vehicles.create',
                'vehicles.edit',
                'vehicle-owners.view',
                'vehicle-owners.edit',
                'bookings.view', // Can also rent cars as customer
                'bookings.create',
                'bookings.edit',
                'bookings.delete',
            ],
        ];

        foreach ($rolesPermissions as $roleName => $perms) {
            $webRole = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $webRole->syncPermissions($perms);

            $apiRole = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
            $apiRole->syncPermissions($perms);
        }
    }
}
