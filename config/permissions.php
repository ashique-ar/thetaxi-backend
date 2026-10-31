<?php

return [
    'canonical_guard' => 'api',
    'legacy_guards' => [],
    'admin_only_permissions' => ['system.*', 'website-settings.*', 'view_audit_log'],

    'modules' => [
        'admin' => ['label' => 'Administration', 'description' => 'Users, roles, permissions, and system controls.'],
        'bookings' => ['label' => 'Bookings', 'description' => 'Booking lifecycle and operational actions.'],
        'vehicles' => ['label' => 'Vehicles', 'description' => 'Fleet, owners, pricing, and vehicle setup.'],
        'customers' => ['label' => 'Customers', 'description' => 'Customer records and customer support workflows.'],
        'companies' => ['label' => 'Locations', 'description' => 'Saved locations used by booking search and dynamic forms.'],
        'corporate' => ['label' => 'Corporate', 'description' => 'Corporate accounts, employees, and transport workflows.'],
        'corporate.audit-logs' => ['label' => 'Corporate Audit Logs', 'description' => 'Corporate account activity history.'],
        'reports' => ['label' => 'Reports', 'description' => 'Reporting and analytics access.'],
        'communication' => ['label' => 'Communication', 'description' => 'SMS, notifications, and messaging tools.'],
        'drivers.onboarding' => ['label' => 'Driver Onboarding', 'description' => 'Driver application review, document checks, and draft visibility.'],
    ],

    'aliases' => [
        'manage-roles' => 'roles.manage',
        'manage-permissions' => 'permissions.manage',
    ],

    'legacy_modules' => [
        'approve_bookings' => 'bookings',
        'create_bookings' => 'bookings',
        'create_bookings_for_others' => 'bookings',
        'view_all_bookings' => 'bookings',
        'manage_departments' => 'corporate',
        'manage_divisions' => 'corporate',
        'manage_employees' => 'corporate',
        'manage_billing' => 'corporate',
        'manage_rate_charts' => 'vehicle-pricing',
        'schedule_reports' => 'reports',
        'view_audit_log' => 'system',
        'view_payments' => 'payments',
        'view_reports' => 'reports',
        'drivers.onboarding-drafts.view' => 'drivers.onboarding',
    ],

    'templates' => [
        'admin' => [
            'label' => 'Admin',
            'description' => 'All portal permissions except system, website settings, and audit-log access.',
            'patterns' => ['*'],
            'exclude' => ['system.*', 'website-settings.*', 'view_audit_log'],
        ],
        'master-admin' => [
            'label' => 'Master Admin',
            'description' => 'Full access to every portal permission.',
            'patterns' => ['*'],
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Broad management access without low-level permission administration.',
            'patterns' => [
                '*.view',
                '*.create',
                '*.edit',
                '*.manage',
                'bookings.*',
                'view_all_bookings',
                'reports.*',
            ],
            'exclude' => ['permissions.*', 'roles.*', 'system.*', 'admin.*', 'users.delete'],
        ],
        'dispatcher' => [
            'label' => 'Dispatcher',
            'description' => 'Booking dispatch, assignment, and fleet visibility.',
            'patterns' => ['bookings.*', 'view_all_bookings', 'driver-assignments.*', 'drivers.view', 'vehicles.view', 'customers.view'],
        ],
        'fleet-manager' => [
            'label' => 'Fleet Manager',
            'description' => 'Vehicle lease and driver onboarding management.',
            'patterns' => [
                'vehicle-leases.view', 'vehicle-leases.create', 'vehicle-leases.edit', 'vehicle-leases.manage', 'vehicle-leases.release',
                'vehicle-makes.view', 'vehicle-models.view',
                'drivers.onboarding.view', 'drivers.onboarding.manage', 'drivers.onboarding-drafts.view',
            ],
        ],
        'driver-coordinator' => [
            'label' => 'Driver Coordinator',
            'description' => 'Driver records and onboarding review.',
            'patterns' => [
                'driver-logs.view', 'driver-logs.create', 'driver-logs.edit', 'driver-logs.delete',
                'driving-licenses.view', 'driving-licenses.create', 'driving-licenses.edit', 'driving-licenses.delete',
                'vehicle-makes.view', 'vehicle-models.view',
                'drivers.onboarding.view', 'drivers.onboarding.manage', 'drivers.onboarding-drafts.view',
            ],
        ],
        'accountant' => [
            'label' => 'Accountant',
            'description' => 'Finance, invoice, settlement, and sales accounting access.',
            'patterns' => [
                'payments.*', 'invoices.*', 'collection-commissions.*', 'financial-settlements.*',
                'vehicle-leases.view', 'vehicle-leases.payments',
                'sales.collections.view', 'sales.collections.view-all', 'sales.collections.verify',
                'sales.payment-finality.manage', 'sales.payment-finality.approve', 'sales.payment-finality.transition',
                'sales.payment-ledger.reconcile', 'sales.commission-config.view',
                'sales.policy-settings.view', 'sales.policy-settings.manage', 'sales.policy-settings.approve',
                'sales.commission-decisions.view', 'sales.commission-decisions.view-all',
                'sales.commission-recoveries.view', 'sales.commission-recoveries.view-all',
                'sales.commission-statements.view', 'sales.commission-statements.view-all', 'sales.commission-statements.export',
                'sales.commission-disputes.resolve', 'sales.commission-payouts.pay', 'sales.commission-payouts.reverse',
                'sales.commission-accounting.acknowledge',
            ],
        ],
        'customer-support' => [
            'label' => 'Customer Support',
            'description' => 'Customer and booking support access.',
            'patterns' => ['customers.view', 'customers.create', 'customers.edit', 'bookings.view', 'bookings.edit', 'view_all_bookings', 'communication.view'],
        ],
        'corporate-admin' => [
            'label' => 'Corporate Admin',
            'description' => 'Corporate account administration.',
            'patterns' => ['corporate.*', 'corporates.*', 'manage_employees', 'approve_bookings', 'view_all_bookings', 'view_payments'],
        ],
    ],
];
