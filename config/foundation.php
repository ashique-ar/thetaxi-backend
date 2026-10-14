<?php

use App\Services\Sales\SalesCommissionNotificationSubscriber;

return [
    /*
    |--------------------------------------------------------------------------
    | Domain outbox subscribers
    |--------------------------------------------------------------------------
    |
    | Maps domain => event_type => a class implementing
    | App\Contracts\Foundation\DomainOutboxSubscriber. Events without a
    | registered subscriber remain durable and unpublished until the owning
    | domain's integration gate registers a subscriber. Never invent a
    | subscriber binding for a domain rule that has not been approved.
    |
    */
    'outbox_subscribers' => [
        'sales' => [
            // The requirement is approved, but policy/recipient activation is
            // separately default-off and fail-closed in the subscriber.
            'sales.commission.decided' => SalesCommissionNotificationSubscriber::class,
            'sales.commission.hold_released' => SalesCommissionNotificationSubscriber::class,
        ],
    ],
];
