<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainOutboxSubscriber;

class SalesCommissionNotificationSubscriber implements DomainOutboxSubscriber
{
    public function __construct(private readonly SalesCommissionNotificationService $notifications)
    {
    }

    public function handle(object $event): void
    {
        $this->notifications->queueFromDomainEvent($event);
    }
}
