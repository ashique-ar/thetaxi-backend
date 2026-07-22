<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdditionalPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'analytics.agents',
            'analytics.bookings',
            'analytics.customers',
            'analytics.dashboard',
            'analytics.drivers',
            'analytics.revenue',
            'analytics.vehicles',
            'api.external',
            'api.webhooks',
            'bookings.approve',
            'bookings.complete',
            'bookings.complete_repairs',
            'bookings.dispatch',
            'bookings.process_return',
            'bookings.qc_inspect',
            'bookings.tracking_replay',
            'bookings.tracking_export',
            'customers.analytics',
            'customers.bookings',
            'customers.export',
            'customers.feedback',
            'customers.loyalty',
            'exports.create',
            'faq-categories.create',
            'faq-categories.delete',
            'faq-categories.edit',
            'faq-categories.view',
            'footer-links.create',
            'footer-links.delete',
            'footer-links.edit',
            'footer-links.view',
            'faqs.create',
            'faqs.delete',
            'faqs.edit',
            'faqs.view',
            'gamification.bulk-give-points',
            'gamification.give-points',
            'gamification.reset-points',
            'gamification.stats',
            'gamification.undo-points',
            'loyalty.redeem',
            'navigation-menus.create',
            'navigation-menus.delete',
            'navigation-menus.edit',
            'navigation-menus.view',
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
            'service-packages.create',
            'service-packages.delete',
            'service-packages.edit',
            'service-packages.view',
            'terms.create',
            'terms.delete',
            'terms.edit',
            'terms.view',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $webAdmin = Role::where(['name' => 'admin', 'guard_name' => 'web'])->first();
        if ($webAdmin) {
            $webAdmin->givePermissionTo($permissions);
        }

        $apiAdmin = Role::where(['name' => 'admin', 'guard_name' => 'api'])->first();
        if ($apiAdmin) {
            $apiAdmin->givePermissionTo($permissions);
        }
    }
}
