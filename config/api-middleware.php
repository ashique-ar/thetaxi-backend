<?php

/**
 * API Middleware Configuration
 * 
 * This file defines the middleware configuration for different API endpoints
 * and user roles/permissions in the Casons Transport Management System.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Role-Based Permissions
    |--------------------------------------------------------------------------
    |
    | Define permissions for different roles in the system
    |
    */

    'roles' => [
        'super-admin' => [
            'permissions' => ['*'], // All permissions
            'description' => 'Super Administrator with full system access'
        ],
        'admin' => [
            'permissions' => [
                'users.*',
                'roles.*', 
                'permissions.*',
                'customers.*',
                'drivers.*',
                'vehicles.*',
                'bookings.*',
                'agents.*',
                'staff.*',
                'analytics.*',
                'reports.*',
                'settings.*',
                'notifications.send',
                'notifications.broadcast',
                'gamification.*',
                'loyalty.*',
                'payments.*',
                'uploads.*'
            ],
            'description' => 'System Administrator'
        ],
        'staff' => [
            'permissions' => [
                'customers.view',
                'customers.create',
                'customers.edit',
                'drivers.view',
                'vehicles.view',
                'bookings.*',
                'notifications.view',
                'gamification.view',
                'loyalty.view',
                'uploads.view',
                'uploads.create'
            ],
            'description' => 'Staff Member'
        ],
        'agent' => [
            'permissions' => [
                'customers.view',
                'customers.create',
                'bookings.view',
                'bookings.create',
                'bookings.edit',
                'notifications.view',
                'gamification.view',
                'loyalty.view',
                'uploads.view',
                'uploads.create'
            ],
            'description' => 'Travel Agent'
        ],
        'driver' => [
            'permissions' => [
                'bookings.view',
                'bookings.update-status',
                'notifications.view',
                'gamification.view',
                'uploads.view',
                'uploads.create'
            ],
            'description' => 'Driver'
        ],
        'customer' => [
            'permissions' => [
                'bookings.view-own',
                'bookings.create',
                'notifications.view-own',
                'gamification.view-own',
                'loyalty.view-own',
                'loyalty.redeem',
                'uploads.view-own',
                'uploads.create-own'
            ],
            'description' => 'Customer'
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | API Endpoint Permissions
    |--------------------------------------------------------------------------
    |
    | Define specific permissions required for each API endpoint
    |
    */

    'endpoints' => [
        
        // Authentication endpoints (public)
        'auth' => [
            'public' => [
                'auth.register',
                'auth.login',
                'auth.refresh',
                'auth.forgot-password',
                'auth.reset-password',
                'auth.social.*',
                'auth.verify-email'
            ],
            'authenticated' => [
                'auth.profile',
                'auth.logout',
                'auth.change-password',
                'auth.2fa.*',
                'auth.sessions.*'
            ]
        ],

        // User Management
        'users' => [
            'middleware' => ['auth:api', 'permission:users.view'],
            'permissions' => [
                'index' => 'users.view',
                'show' => 'users.view',
                'store' => 'users.create',
                'update' => 'users.edit',
                'destroy' => 'users.delete',
                'activate' => 'users.activate',
                'deactivate' => 'users.deactivate',
                'reset-password' => 'users.reset-password',
                'permissions' => 'users.permissions',
                'roles' => 'users.roles'
            ]
        ],

        // Role Management
        'roles' => [
            'middleware' => ['auth:api', 'permission:roles.view'],
            'permissions' => [
                'index' => 'roles.view',
                'show' => 'roles.view',
                'store' => 'roles.create',
                'update' => 'roles.edit',
                'destroy' => 'roles.delete',
                'permissions' => 'roles.permissions',
                'users' => 'roles.users'
            ]
        ],

        // Permission Management
        'permissions' => [
            'middleware' => ['auth:api', 'permission:permissions.view'],
            'permissions' => [
                'index' => 'permissions.view',
                'show' => 'permissions.view',
                'store' => 'permissions.create',
                'update' => 'permissions.edit',
                'destroy' => 'permissions.delete',
                'roles' => 'permissions.roles',
                'users' => 'permissions.users'
            ]
        ],

        // Customer Management
        'customers' => [
            'middleware' => ['auth:api', 'permission:customers.view'],
            'permissions' => [
                'index' => 'customers.view',
                'show' => 'customers.view',
                'store' => 'customers.create',
                'update' => 'customers.edit',
                'destroy' => 'customers.delete',
                'bookings' => 'customers.bookings',
                'loyalty' => 'customers.loyalty',
                'feedback' => 'customers.feedback',
                'analytics' => 'customers.analytics',
                'export' => 'customers.export'
            ]
        ],

        // Driver Management
        'drivers' => [
            'middleware' => ['auth:api', 'permission:drivers.view'],
            'permissions' => [
                'index' => 'drivers.view',
                'show' => 'drivers.view',
                'store' => 'drivers.create',
                'update' => 'drivers.edit',
                'destroy' => 'drivers.delete'
            ]
        ],

        // Vehicle Management
        'vehicles' => [
            'middleware' => ['auth:api', 'permission:vehicles.view'],
            'permissions' => [
                'index' => 'vehicles.view',
                'show' => 'vehicles.view',
                'store' => 'vehicles.create',
                'update' => 'vehicles.edit',
                'destroy' => 'vehicles.delete'
            ]
        ],

        // Booking Management
        'bookings' => [
            'middleware' => ['auth:api', 'permission:bookings.view'],
            'permissions' => [
                'index' => 'bookings.view',
                'show' => 'bookings.view',
                'store' => 'bookings.create',
                'update' => 'bookings.edit',
                'destroy' => 'bookings.delete'
            ]
        ],

        // Agent Management
        'agents' => [
            'middleware' => ['auth:api', 'permission:agents.view'],
            'permissions' => [
                'index' => 'agents.view',
                'show' => 'agents.view',
                'store' => 'agents.create',
                'update' => 'agents.edit',
                'destroy' => 'agents.delete'
            ]
        ],

        // Staff Management
        'staff' => [
            'middleware' => ['auth:api', 'permission:staff.view'],
            'permissions' => [
                'index' => 'staff.view',
                'show' => 'staff.view',
                'store' => 'staff.create',
                'update' => 'staff.edit',
                'destroy' => 'staff.delete'
            ]
        ],

        // Gamification
        'gamification' => [
            'middleware' => ['auth:api'],
            'permissions' => [
                'getUserPoints' => 'gamification.view',
                'getUserReputation' => 'gamification.view',
                'givePoints' => 'gamification.give-points',
                'undoPoints' => 'gamification.undo-points',
                'resetPoints' => 'gamification.reset-points',
                'getUserBadges' => 'gamification.view',
                'getUserRank' => 'gamification.view',
                'getAllBadges' => 'gamification.view',
                'getBadgeDetails' => 'gamification.view',
                'getBadgeStats' => 'gamification.stats',
                'getLeaderboard' => 'gamification.view',
                'getReputationStats' => 'gamification.stats',
                'bulkGivePoints' => 'gamification.bulk-give-points'
            ]
        ],

        // Loyalty Program
        'loyalty' => [
            'middleware' => ['auth:api'],
            'permissions' => [
                'getCustomerLoyaltyPoints' => 'loyalty.view',
                'getCustomerLoyaltyTier' => 'loyalty.view',
                'getCustomerLoyaltyHistory' => 'loyalty.view',
                'redeemPoints' => 'loyalty.redeem',
                'getLoyaltyTiers' => 'loyalty.view',
                'getLoyaltyRewards' => 'loyalty.view',
                'getLoyaltyActivity' => 'loyalty.view'
            ]
        ],

        // Analytics
        'analytics' => [
            'middleware' => ['auth:api', 'permission:analytics.view'],
            'permissions' => [
                'getDashboardStats' => 'analytics.dashboard',
                'getBookingTrends' => 'analytics.bookings',
                'getRevenueStats' => 'analytics.revenue',
                'getCustomerAnalytics' => 'analytics.customers',
                'getDriverPerformance' => 'analytics.drivers',
                'getVehicleUtilization' => 'analytics.vehicles',
                'getAgentPerformance' => 'analytics.agents'
            ]
        ],

        // Notifications
        'notifications' => [
            'middleware' => ['auth:api'],
            'permissions' => [
                'getUserNotifications' => 'notifications.view',
                'markAsRead' => 'notifications.mark-read',
                'markAllAsRead' => 'notifications.mark-read',
                'deleteNotification' => 'notifications.delete',
                'getUnreadCount' => 'notifications.view',
                'sendNotification' => 'notifications.send',
                'broadcastNotification' => 'notifications.broadcast',
                'scheduleNotification' => 'notifications.schedule',
                'sendEmailNotification' => 'notifications.email',
                'getEmailTemplates' => 'notifications.templates',
                'createEmailTemplate' => 'notifications.create-template'
            ]
        ],

        // File Uploads
        'uploads' => [
            'middleware' => ['auth:api'],
            'permissions' => [
                'uploadAvatar' => 'uploads.avatar',
                'uploadDocument' => 'uploads.document',
                'uploadVehicleImage' => 'uploads.vehicle-image',
                'uploadDriverLicense' => 'uploads.driver-license',
                'uploadInsuranceDocument' => 'uploads.insurance-document',
                'deleteFile' => 'uploads.delete',
                'getFileDetails' => 'uploads.view'
            ]
        ],

        // Payments
        'payments' => [
            'middleware' => ['auth:api'],
            'permissions' => [
                'initiatePayment' => 'payments.initiate',
                'paymentCallback' => 'payments.callback',
                'getPaymentStatus' => 'payments.view',
                'refundPayment' => 'payments.refund',
                'getPaymentMethods' => 'payments.methods',
                'getPaymentTransactions' => 'payments.transactions',
                'getTransactionDetails' => 'payments.transactions',
                'refundTransaction' => 'payments.refund'
            ]
        ],

        // System Configuration
        'system' => [
            'middleware' => ['auth:api', 'permission:system.view'],
            'permissions' => [
                'countries' => 'system.countries',
                'states' => 'system.states',
                'currencies' => 'system.currencies',
                'business-settings' => 'system.settings',
                'companies' => 'system.companies',
                'cms-content-types' => 'system.cms',
                'cms-contents' => 'system.cms',
                'website-settings' => 'system.website',
                'service-types' => 'system.service-types',
                'vip-types' => 'system.vip-types'
            ]
        ],

        // Vehicle Sub-resources
        'vehicle-resources' => [
            'middleware' => ['auth:api', 'permission:vehicles.view'],
            'permissions' => [
                'vehicle-categories' => 'vehicles.categories',
                'vehicle-classes' => 'vehicles.classes',
                'vehicle-makes' => 'vehicles.makes',
                'vehicle-models' => 'vehicles.models',
                'vehicle-grades' => 'vehicles.grades',
                'vehicle-groups' => 'vehicles.groups',
                'vehicle-fuel-types' => 'vehicles.fuel-types',
                'vehicle-transmissions' => 'vehicles.transmissions',
                'vehicle-insurances' => 'vehicles.insurances',
                'vehicle-maintenance-records' => 'vehicles.maintenance',
                'vehicle-pricing-slabs' => 'vehicles.pricing',
                'vehicle-addons' => 'vehicles.addons',
                'vehicle-discounts' => 'vehicles.discounts'
            ]
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Define rate limiting for different API endpoints
    |
    */

    'rate_limits' => [
        'default' => '60:1', // 60 requests per minute
        'auth' => '5:1', // 5 requests per minute for auth endpoints
        'upload' => '10:1', // 10 requests per minute for uploads
        'payment' => '20:1', // 20 requests per minute for payments
        'notification' => '30:1', // 30 requests per minute for notifications
        'analytics' => '120:1', // 120 requests per minute for analytics
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Groups
    |--------------------------------------------------------------------------
    |
    | Define middleware groups for different API sections
    |
    */

    'middleware_groups' => [
        'api.public' => [
            'api',
            'throttle:60,1',
            'cors'
        ],
        'api.auth' => [
            'api',
            'throttle:5,1',
            'cors'
        ],
        'api.protected' => [
            'api',
            'auth:api',
            'throttle:60,1',
            'cors',
            'verified'
        ],
        'api.admin' => [
            'api',
            'auth:api',
            'role:admin|super-admin',
            'throttle:120,1',
            'cors',
            'verified'
        ],
        'api.staff' => [
            'api',
            'auth:api',
            'role:staff|admin|super-admin',
            'throttle:60,1',
            'cors',
            'verified'
        ],
        'api.agent' => [
            'api',
            'auth:api',
            'role:agent|staff|admin|super-admin',
            'throttle:60,1',
            'cors',
            'verified'
        ],
        'api.driver' => [
            'api',
            'auth:api',
            'role:driver|staff|admin|super-admin',
            'throttle:60,1',
            'cors',
            'verified'
        ],
        'api.customer' => [
            'api',
            'auth:api',
            'role:customer|staff|admin|super-admin',
            'throttle:60,1',
            'cors',
            'verified'
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Middleware
    |--------------------------------------------------------------------------
    |
    | Custom middleware for specific functionality
    |
    */

    'custom_middleware' => [
        'check.ownership' => \App\Http\Middleware\CheckOwnership::class,
        'verify.user.active' => \App\Http\Middleware\EnsureUserIsActive::class,
        'verify.email.verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        'log.api.activity' => \App\Http\Middleware\LogApiActivity::class,
        'validate.api.version' => \App\Http\Middleware\ValidateApiVersion::class,
    ]
];
