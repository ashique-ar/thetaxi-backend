<?php

use App\Services\Sales\SalesCommissionNotificationSubscriber;

return [
    /*
    |--------------------------------------------------------------------------
    | Domain outbox subscribers
    |--------------------------------------------------------------------------
    |
    | Maps domain => event_type => a class implementing
    | App\Contracts\Foundation\DomainOutboxSubscriber. An event whose domain
    | or event_type has no entry here is still relayed to the queue (so
    | delivery is never lost) but is intentionally left unconsumed until the
    | owning domain's integration gate registers a subscriber. Never invent
    | a subscriber binding for a domain rule that has not been approved.
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
