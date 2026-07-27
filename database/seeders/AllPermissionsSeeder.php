<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Canonical, additive-only permission seeder.
 *
 * This is the single source of truth for application permissions. It is safe
 * to run against existing client databases: no permission, role, or assignment
 * is deleted, and existing role assignments are never replaced.
 */
class AllPermissionsSeeder extends Seeder
{
    private const GUARDS = ['api', 'web'];

    private const STANDARD_ACTIONS = ['view', 'create', 'edit', 'update', 'delete', 'manage'];

    private const RESOURCES = [
        'addons',
        'admin',
        'agent-api-sessions',
        'agent-apis',
        'agent-commissions',
        'agents',
        'agreements',
        'agreement-signing',
        'agreement-templates',
        'airports',
        'analytics',
        'api',
        'booking-addons',
        'booking-channels',
        'booking-form-tabs',
        'booking-items',
        'booking-statuses',
        'bookings',
        'business-settings',
        'call-logs',
        'cms-content-types',
        'cms-contents',
        'collection-commissions',
        'communication',
        'companies',
        'corporate',
        'corporates',
        'countries',
        'currencies',
        'customer-documents',
        'customer-feedback',
        'customer-gamification',
        'customer-loyalty',
        'customers',
        'dashboard',
        'documents',
        'driver-assignments',
        'driver-batta-rules',
        'driver-hire-settlements',
        'driver-logs',
        'drivers',
        'driving-licence-types',
        'driving-licences',
        'driving-license-types',
        'driving-licenses',
        'email-templates',
        'exports',
        'faq-categories',
        'faqs',
        'footer-links',
        'gallery',
        'gamification',
        'image-galleries',
        'inquiries',
        'inquiry-forms',
        'inquiry-service-pages',
        'invoices',
        'km-range-pricing',
        'logsheets',
        'loyalty',
        'maintenance-records',
        'maintenance-schedules',
        'medical-records',
        'navigation-menus',
        'notification-logs',
        'notification-templates',
        'notifications',
        'payment-methods',
        'payments',
        'permissions',
        'phone-calls',
        'popup',
        'price-adjustments',
        'pricing-slabs',
        'promo-code',
        'quotations',
        'regions',
        'reports',
        'roles',
        'service-configs',
        'service-packages',
        'service-types',
        'settings',
        'sms.campaigns',
        'sms.messages',
        'sms.overview',
        'sms.sending',
        'sms.settings',
        'staff',
        'staff-assignments',
        'staff-performance',
        'staff-schedule',
        'staff-transport',
        'states',
        'system',
        'terms',
        'uploads',
        'users',
        'vehicle-addons',
        'vehicle-availability',
        'vehicle-categories',
        'vehicle-classes',
        'vehicle-commissions',
        'vehicle-common-rates',
        'vehicle-contract-types',
        'vehicle-discounts',
        'vehicle-distance-multipliers',
        'vehicle-fuel-types',
        'vehicle-grades',
        'vehicle-group-pricing',
        'vehicle-groups',
        'vehicle-images',
        'vehicle-insurance-providers',
        'vehicle-insurance-types',
        'vehicle-insurances',
        'vehicle-leases',
        'vehicle-maintenance',
        'vehicle-maintenance-records',
        'vehicle-maintenance-schedules',
        'vehicle-makes',
        'vehicle-models',
        'vehicle-owner-types',
        'vehicle-owners',
        'vehicle-pricing',
        'vehicle-pricing-calculations',
        'vehicle-pricing-common-rates',
        'vehicle-pricing-slab-rates',
        'vehicle-pricing-slabs',
        'vehicle-service-types',
        'vehicle-transmissions',
        'vehicles',
        'vip-types',
        'website-settings',
    ];

    private const SPECIAL_PERMISSIONS = [
        'analytics.agents',
        'analytics.bookings',
        'analytics.customers',
        'analytics.dashboard',
        'analytics.drivers',
        'analytics.revenue',
        'analytics.vehicles',
        'api.external',
        'api.webhooks',
        'approve_bookings',
        'bookings.approve',
        'bookings.complete',
        'bookings.complete_repairs',
        'bookings.dispatch',
        'bookings.process_return',
        'bookings.qc_inspect',
        'bookings.tracking_export',
        'bookings.tracking_replay',
        'collection-commissions.pay',
        'create_bookings',
        'create_bookings_for_others',
        'customers.analytics',
        'customers.bookings',
        'customers.export',
        'customers.feedback',
        'customers.loyalty',
        'gamification.bulk-give-points',
        'gamification.give-points',
        'gamification.reset-points',
        'gamification.stats',
        'gamification.undo-points',
        'invoices.generate',
        'invoices.send',
        'invoices.void',
        'loyalty.redeem',
        'manage_departments',
        'manage_divisions',
        'manage_employees',
        'manage_rate_charts',
        'manage-roles',
        'manage-permissions',
        'notifications.broadcast',
        'notifications.create-template',
        'notifications.email',
        'notifications.mark-read',
        'notifications.schedule',
        'notifications.send',
        'notifications.templates',
        'payments.callback',
        'payments.initiate',
        'payments.methods',
        'payments.refund',
        'payments.transactions',
        'reports.generate',
        'staff-transport.generate',
        'staff-transport.override',
        'vehicle-leases.payments',
        'vehicle-leases.release',
        'view_all_bookings',
        'view_audit_log',
        'view_payments',
        'view_reports',
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permissionNames = $this->permissionNames();
        $permissionsByGuard = [];

        foreach (self::GUARDS as $guard) {
            foreach ($permissionNames as $permissionName) {
                $permission = Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => $guard,
                ]);

                $permissionsByGuard[$guard][$permissionName] = $permission;
            }
        }

        foreach (self::GUARDS as $guard) {
            $this->addPermissionsToRole(
                Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guard]),
                $permissionNames,
                $permissionsByGuard[$guard]
            );

            $this->seedBaselineRoles($guard, $permissionNames, $permissionsByGuard[$guard]);
            $this->mapLegacyCommunicationPermissions($guard, $permissionsByGuard[$guard]);
        }

        $registrar->forgetCachedPermissions();

        $this->command?->info(
            sprintf(
                'All permissions are present for [%s] guards; existing roles and assignments were preserved.',
                implode(', ', self::GUARDS)
            )
        );
    }

    /**
     * @return array<int, string>
     */
    public static function allPermissionNames(): array
    {
        $permissions = self::SPECIAL_PERMISSIONS;

        foreach (self::RESOURCES as $resource) {
            foreach (self::STANDARD_ACTIONS as $action) {
                $permissions[] = "{$resource}.{$action}";
            }
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    /**
     * @return array<int, string>
     */
    private function permissionNames(): array
    {
        return self::allPermissionNames();
    }

    /**
     * Add baseline permissions without replacing any client-specific choices.
     *
     * @param array<int, string> $allPermissions
     * @param array<string, Permission> $permissionModels
     */
    private function seedBaselineRoles(string $guard, array $allPermissions, array $permissionModels): void
    {
        $rolePermissions = [
            'sub-admin' => array_values(array_filter(
                $allPermissions,
                fn (string $permission) => ! preg_match('/^(roles|permissions|system)\./', $permission)
            )),
            'data-entry' => $this->matchingPermissions(
                $allPermissions,
                '/^(vehicles|vehicle-|service-types|customers|inquiries|image-galleries)\./'
            ),
            'management' => array_values(array_unique(array_merge(
                $this->matchingPermissions(
                    $allPermissions,
                    '/^(bookings|customers|inquiries|quotations|image-galleries|popup|promo-code)\./'
                ),
                ['reports.view', 'analytics.view']
            ))),
            'accountant' => array_values(array_unique(array_merge(
                $this->matchingPermissions($allPermissions, '/^(payments|invoices|collection-commissions)\./'),
                ['vehicle-leases.view', 'vehicle-leases.payments']
            ))),
            'rep-marketing' => $this->permissionsForResources(
                ['customers', 'quotations', 'bookings', 'inquiries'],
                ['view', 'create', 'edit', 'delete']
            ),
            'driver-coordinator' => $this->permissionsForResources(
                ['driver-logs', 'driving-licenses'],
                ['view', 'create', 'edit', 'delete']
            ),
            'driver' => ['driver-logs.view', 'driver-logs.create', 'driving-licenses.view'],
            'agent' => ['bookings.view', 'bookings.create', 'agent-commissions.view'],
            'corporate' => ['bookings.view', 'bookings.create', 'vehicle-discounts.view'],
            'vehicle-owner' => [
                'vehicles.view',
                'vehicles.create',
                'vehicles.edit',
                'vehicle-owners.view',
                'vehicle-owners.edit',
                'bookings.view',
                'bookings.create',
                'bookings.edit',
                'bookings.delete',
            ],
            'fleet-manager' => [
                'vehicle-leases.view',
                'vehicle-leases.create',
                'vehicle-leases.edit',
                'vehicle-leases.manage',
                'vehicle-leases.release',
            ],
            'Corporate_Master_Admin' => [
                'corporate.view',
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
                'view_audit_log',
                'bookings.view',
                'bookings.create',
            ],
            'Transport_Coordinator' => [
                'corporate.view',
                'manage_employees',
                'create_bookings',
                'create_bookings_for_others',
                'view_all_bookings',
                'bookings.view',
                'bookings.create',
            ],
            'Approval_Manager' => [
                'corporate.view',
                'approve_bookings',
                'view_all_bookings',
            ],
            'Corporate_Employee' => [
                'corporate.view',
                'create_bookings',
                'bookings.view',
                'bookings.create',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);
            $this->addPermissionsToRole($role, $permissions, $permissionModels);
        }
    }

    /**
     * Preserve the old communication-to-SMS permission migration additively.
     *
     * @param array<string, Permission> $permissionModels
     */
    private function mapLegacyCommunicationPermissions(string $guard, array $permissionModels): void
    {
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

        Role::where('guard_name', $guard)
            ->with('permissions:id,name,guard_name')
            ->get()
            ->each(function (Role $role) use ($viewPermissions, $managePermissions, $permissionModels): void {
                $current = $role->permissions->pluck('name');

                if ($current->contains('communication.manage')) {
                    $this->addPermissionsToRole(
                        $role,
                        array_merge($viewPermissions, $managePermissions),
                        $permissionModels
                    );
                } elseif ($current->contains('communication.view')) {
                    $this->addPermissionsToRole($role, $viewPermissions, $permissionModels);
                }
            });
    }

    /**
     * @param array<int, string> $permissionNames
     * @param array<string, Permission> $permissionModels
     */
    private function addPermissionsToRole(Role $role, array $permissionNames, array $permissionModels): void
    {
        $permissions = collect($permissionNames)
            ->unique()
            ->map(fn (string $name) => $permissionModels[$name] ?? null)
            ->filter()
            ->values();

        if ($permissions->isNotEmpty()) {
            $role->givePermissionTo($permissions);
        }
    }

    /**
     * @param array<int, string> $permissions
     * @return array<int, string>
     */
    private function matchingPermissions(array $permissions, string $pattern): array
    {
        return array_values(array_filter(
            $permissions,
            fn (string $permission) => preg_match($pattern, $permission) === 1
        ));
    }

    /**
     * @param array<int, string> $resources
     * @param array<int, string> $actions
     * @return array<int, string>
     */
    private function permissionsForResources(array $resources, array $actions): array
    {
        $permissions = [];

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $permissions[] = "{$resource}.{$action}";
            }
        }

        return $permissions;
    }
}
