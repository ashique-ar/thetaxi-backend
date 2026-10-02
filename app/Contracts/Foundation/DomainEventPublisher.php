<?php

namespace App\Contracts\Foundation;

use Carbon\CarbonInterface;

interface DomainEventPublisher
{
    public function record(
        string $domain,
        ?string $companyId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        int $schemaVersion,
        array $payload,
        CarbonInterface $occurredAt,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): string;
}
