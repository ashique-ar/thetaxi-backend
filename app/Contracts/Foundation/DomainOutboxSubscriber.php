<?php

namespace App\Contracts\Foundation;

/**
 * A domain-owned handler for an outbox event. Bound per domain/event-type
 * in config('foundation.outbox_subscribers'); unbound events stay in the
 * outbox and are not relayed until the owning integration gate registers
 * a subscriber.
 */
interface DomainOutboxSubscriber
{
    public function handle(object $event): void;
}
