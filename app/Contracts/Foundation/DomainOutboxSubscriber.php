<?php

namespace App\Contracts\Foundation;

/**
 * A domain-owned handler for a published outbox event. Bound per
 * domain/event-type in config('foundation.outbox_subscribers'); an
 * unbound event type is relayed but intentionally left unconsumed
 * until the owning domain's integration gate registers a subscriber.
 */
interface DomainOutboxSubscriber
{
    public function handle(object $event): void;
}
