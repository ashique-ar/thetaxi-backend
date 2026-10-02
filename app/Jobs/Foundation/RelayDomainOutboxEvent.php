<?php

namespace App\Jobs\Foundation;

use App\Contracts\Foundation\DomainOutboxSubscriber;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RelayDomainOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300, 900, 3600];

    public function __construct(public readonly string $outboxEventId)
    {
    }

    public function handle(): void
    {
        $row = DB::table('domain_outbox_events')->where('id', $this->outboxEventId)->first();
        if (! $row) {
            return;
        }

        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $expected = hash('sha256', CanonicalJson::encode($payload));

        if (! hash_equals($row->payload_checksum, $expected)) {
            Log::error('Domain outbox payload checksum mismatch; refusing delivery.', [
                'id' => $row->id,
                'domain' => $row->domain,
                'event_type' => $row->event_type,
            ]);

            return;
        }

        // Event types contain dots, so Laravel's dot-notation config lookup cannot
        // address them as array keys. Resolve the domain map first and then use
        // the exact event type key.
        $domainSubscribers = config("foundation.outbox_subscribers.{$row->domain}", []);
        $subscriberClass = is_array($domainSubscribers) ? ($domainSubscribers[$row->event_type] ?? null) : null;
        if (! $subscriberClass || ! is_string($subscriberClass) || ! is_subclass_of($subscriberClass, DomainOutboxSubscriber::class)) {
            return;
        }

        app($subscriberClass)->handle((object) [
            'id' => $row->id,
            'domain' => $row->domain,
            'companyId' => $row->company_id,
            'aggregateType' => $row->aggregate_type,
            'aggregateId' => $row->aggregate_id,
            'eventType' => $row->event_type,
            'eventVersion' => $row->event_version,
            'schemaVersion' => $row->schema_version,
            'payload' => $payload,
            'occurredAt' => $row->occurred_at,
            'correlationId' => $row->correlation_id,
            'causationId' => $row->causation_id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        DB::table('domain_outbox_events')->where('id', $this->outboxEventId)->update([
            'attempt_count' => DB::raw('attempt_count + 1'),
            'last_error' => Str::limit($exception->getMessage(), 2000),
            'updated_at' => now(),
        ]);
    }
}
