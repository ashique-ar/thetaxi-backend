<?php

namespace App\Services\Foundation;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DomainOutboxService implements DomainEventPublisher
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
    ): string {
        $id = (string) Str::uuid();
        $canonicalPayload = CanonicalJson::encode($payload);

        DB::table('domain_outbox_events')->insert([
            'id' => $id,
            'domain' => $domain,
            'company_id' => $companyId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'schema_version' => $schemaVersion,
            'payload' => $canonicalPayload,
            'payload_checksum' => hash('sha256', $canonicalPayload),
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
            'occurred_at' => $occurredAt,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Count due, unpublished events without claiming them. Used for
     * dry-run/preview reporting only.
     */
    public function countDuePublications(): int
    {
        return DB::table('domain_outbox_events')
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->count();
    }

    /**
     * Atomically claim up to $limit due, unpublished events and mark them
     * published in the same transaction as the claim lock, so two
     * overlapping relay runs never dispatch the same event twice. The
     * caller is responsible for actually handing each claimed row to the
     * queue transport and calling revertClaim() if that hand-off fails.
     *
     * @return Collection<int, object>
     */
    public function claimDuePublications(int $limit = 200): Collection
    {
        return DB::transaction(function () use ($limit) {
            $rows = DB::table('domain_outbox_events')
                ->whereNull('published_at')
                ->where('available_at', '<=', now())
                ->orderBy('available_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return $rows;
            }

            DB::table('domain_outbox_events')
                ->whereIn('id', $rows->pluck('id'))
                ->update(['published_at' => now(), 'updated_at' => now()]);

            return $rows;
        });
    }

    /**
     * Undo a claim when the hand-off to the queue transport itself failed,
     * so the event remains eligible for a later relay attempt instead of
     * being silently lost.
     */
    public function revertClaim(string $id, string $error): void
    {
        DB::table('domain_outbox_events')->where('id', $id)->update([
            'published_at' => null,
            'attempt_count' => DB::raw('attempt_count + 1'),
            'last_error' => Str::limit($error, 2000),
            'updated_at' => now(),
        ]);
    }
}
