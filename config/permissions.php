<?php

return [
    'canonical_guard' => 'api',
    'legacy_guards' => ['web'],

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

    'templates' => [
        'admin' => [
            'label' => 'Admin',
            'description' => 'Full operational administration access.',
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
                'reports.*',
            ],
            'exclude' => ['permissions.*', 'roles.delete', 'users.delete'],
        ],
        'dispatcher' => [
            'label' => 'Dispatcher',
            'description' => 'Booking dispatch, assignment, and fleet visibility.',
            'patterns' => ['bookings.*', 'assignments.*', 'drivers.view', 'vehicles.view', 'customers.view'],
        ],
        'fleet-manager' => [
            'label' => 'Fleet Manager',
            'description' => 'Vehicle and driver management.',
            'patterns' => ['vehicles.*', 'drivers.*', 'vehicle-*', 'reports.view'],
        ],
        'accountant' => [
            'label' => 'Accountant',
            'description' => 'Finance, invoice, settlement, and reporting access.',
            'patterns' => ['reports.*', 'payments.*', 'invoices.*', 'settlements.*', '*.view'],
        ],
        'customer-support' => [
            'label' => 'Customer Support',
            'description' => 'Customer and booking support access.',
            'patterns' => ['customers.*', 'bookings.view', 'bookings.edit', 'communication.view'],
        ],
        'corporate-admin' => [
            'label' => 'Corporate Admin',
            'description' => 'Corporate account administration.',
            'patterns' => ['corporate.*', 'corporates.*', 'manage_employees', 'approve_bookings', 'view_all_bookings', 'view_payments'],
        ],
    ],
];
