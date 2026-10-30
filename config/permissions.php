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
        'corporate' => ['label' => 'Corporate', 'description' => 'Corporate accounts, employees, and transport workflows.'],
        'reports' => ['label' => 'Reports', 'description' => 'Reporting and analytics access.'],
        'communication' => ['label' => 'Communication', 'description' => 'SMS, notifications, and messaging tools.'],
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
        'manage_rate_charts' => 'vehicle-pricing',
        'schedule_reports' => 'reports',
        'view_audit_log' => 'system',
        'view_payments' => 'payments',
        'view_reports' => 'reports',
    ],

    'templates' => [
        'admin' => [
            'label' => 'Admin',
            'description' => 'All portal permissions except system and website settings administration.',
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
        'accountant' => [
            'label' => 'Accountant',
            'description' => 'Finance, invoice, settlement, and reporting access.',
            'patterns' => ['reports.*', 'payments.*', 'invoices.*', 'settlements.*', '*.view'],
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
