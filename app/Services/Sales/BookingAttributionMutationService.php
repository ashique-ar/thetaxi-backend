<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BookingAttributionMutationService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesMetricFactService $metricFacts,
        private readonly SalesProfileEligibilityService $profileEligibility,
    ) {}

    public function transferCollectionHandler(
        SalesBookingAttribution $attribution,
        ?SalesProfile $toProfile,
        CarbonInterface $effectiveAt,
        string $reason,
        string $idempotencyKey,
        string $actorUserId,
        ?CarbonInterface $requestAcceptedAt = null,
    ): SalesBookingAttribution {
        return $this->mutateProfile(
            $attribution,
            'collection_sales_profile_id',
            $toProfile,
            'collection_handler_transferred',
            $effectiveAt,
            $reason,
            $idempotencyKey,
            $actorUserId,
            true,
            $requestAcceptedAt ?? CarbonImmutable::now(),
        );
    }

    public function correctAcquisitionOwner(
        SalesBookingAttribution $attribution,
        SalesProfile $toProfile,
        string $reason,
        string $idempotencyKey,
        string $actorUserId,
    ): SalesBookingAttribution {
        return $this->mutateProfile(
            $attribution,
            'acquisition_sales_profile_id',
            $toProfile,
            'acquisition_owner_corrected',
            $attribution->secured_at,
            $reason,
            $idempotencyKey,
            $actorUserId,
            false,
            null,
        );
    }

    public function closeLeaverPortfolio(
        SalesProfile $leaver,
        ?SalesProfile $replacement,
        CarbonInterface $effectiveAt,
        string $reason,
        string $idempotencyPrefix,
        string $actorUserId,
    ): int {
        $requestAcceptedAt = CarbonImmutable::now();
        if ($effectiveAt->lt($requestAcceptedAt->subSecond())) {
            throw new RuntimeException('Sales Profile closure is prospective and cannot predate the request.');
        }
        if ($replacement && $replacement->company_id !== $leaver->company_id) {
            throw new RuntimeException('A replacement handler must belong to the same legal entity.');
        }

        $count = 0;
        SalesBookingAttribution::query()
            ->where('collection_sales_profile_id', $leaver->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$count, $replacement, $effectiveAt, $reason, $idempotencyPrefix, $actorUserId, $requestAcceptedAt) {
                foreach ($rows as $row) {
                    $this->transferCollectionHandler(
                        $row,
                        $replacement,
                        $effectiveAt,
                        $reason,
                        "{$idempotencyPrefix}:{$row->id}",
                        $actorUserId,
                        $requestAcceptedAt,
                    );
                    $count++;
                }
            });

        DB::transaction(function () use ($leaver, $effectiveAt, $reason, $idempotencyPrefix, $actorUserId): void {
            $profile = SalesProfile::query()->lockForUpdate()->findOrFail($leaver->id);
            $eventKey = "{$idempotencyPrefix}:profile-closed";
            if (DB::table('sales_profile_events')
                ->where('sales_profile_id', $profile->id)
                ->where('idempotency_key', $eventKey)
                ->exists()) {
                return;
            }

            $before = $this->profileConfigurationSnapshot($profile);
            $profile->update([
                'status' => $effectiveAt->lte(now()) ? 'ended' : $profile->status,
                'effective_until' => $effectiveAt,
                'version' => $profile->version + 1,
                'updated_user_id' => $actorUserId,
            ]);
            // The history row represents the state effective at the requested
            // business time even when the current projection remains active
            // until the due-effective projector applies it.
            $after = $this->profileConfigurationSnapshot($profile);
            $after['status'] = 'ended';
            DB::table('sales_profile_events')->insert([
                'id' => (string) Str::uuid(),
                'sales_profile_id' => $profile->id,
                'profile_version' => $profile->version,
                'event_type' => 'portfolio_closed',
                'from_status' => $before['status'],
                'to_status' => $profile->status,
                'reason' => $reason,
                'before_configuration' => CanonicalJson::encode($before),
                'after_configuration' => CanonicalJson::encode($after),
                'idempotency_key' => $eventKey,
                'actor_user_id' => $actorUserId,
                'occurred_at' => $effectiveAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('domain_audit_events')->insert([
                'id' => (string) Str::uuid(),
                'domain' => 'sales',
                'company_id' => $profile->company_id,
                'subject_type' => 'sales_profile',
                'subject_id' => $profile->id,
                'event_type' => 'sales.profile.portfolio_closed',
                'actor_user_id' => $actorUserId,
                'actor_type' => 'user',
                'correlation_id' => null,
                'source_ip' => null,
                'before_checksum' => hash('sha256', CanonicalJson::encode($before)),
                'after_checksum' => hash('sha256', CanonicalJson::encode($after)),
                'reason' => $reason,
                'occurred_at' => $effectiveAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $count;
    }

    private function mutateProfile(
        SalesBookingAttribution $attribution,
        string $field,
        ?SalesProfile $toProfile,
        string $eventType,
        CarbonInterface $effectiveAt,
        string $reason,
        string $idempotencyKey,
        string $actorUserId,
        bool $prospective,
        ?CarbonInterface $requestAcceptedAt,
    ): SalesBookingAttribution {
        if ($prospective) {
            $acceptedAt = $requestAcceptedAt
                ? CarbonImmutable::instance($requestAcceptedAt)
                : CarbonImmutable::now();
            if ($effectiveAt->lt($acceptedAt->subSecond())) {
                throw new RuntimeException('Collection-handler transfers are prospective and cannot predate the request.');
            }
        }

        return DB::transaction(function () use ($attribution, $field, $toProfile, $eventType, $effectiveAt, $reason, $idempotencyKey, $actorUserId) {
            $locked = SalesBookingAttribution::query()->lockForUpdate()->findOrFail($attribution->id);
            $existing = DB::table('sales_booking_attribution_events')
                ->where('attribution_id', $locked->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $sameEffectiveTime = false;
                try {
                    $sameEffectiveTime = CarbonImmutable::parse($existing->effective_at)->getTimestamp()
                        === $effectiveAt->getTimestamp();
                } catch (\Throwable) {
                    // A malformed persisted timestamp cannot be treated as an idempotent replay.
                }
                if ($existing->event_type !== $eventType
                    || $existing->field_name !== $field
                    || (string) ($existing->to_sales_profile_id ?? '') !== (string) ($toProfile?->id ?? '')
                    || (string) ($existing->reason ?? '') !== $reason
                    || ! $sameEffectiveTime) {
                    throw new RuntimeException('The attribution idempotency key was already used for a different mutation payload.');
                }

                return $locked;
            }

            $counterpartId = $field === 'acquisition_sales_profile_id'
                ? $locked->collection_sales_profile_id
                : $locked->acquisition_sales_profile_id;
            $profileIds = array_values(array_unique(array_filter([$toProfile?->id, $counterpartId])));
            sort($profileIds, SORT_STRING);
            $profiles = SalesProfile::query()
                ->withTrashed()
                ->whereIn('id', $profileIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $target = $toProfile ? $profiles->get($toProfile->id) : null;
            if ($toProfile && ! $target) {
                throw new RuntimeException('The target Sales Profile no longer exists.');
            }
            if ($target && $target->company_id !== $locked->company_id) {
                throw new RuntimeException('The target Sales Profile must belong to the attribution legal entity.');
            }
            $requiredEligibility = $field === 'acquisition_sales_profile_id' ? 'acquisition' : 'collection';
            if ($target && ! $this->profileEligibility->isEligibleAt($target, [$requiredEligibility], $effectiveAt)) {
                throw new RuntimeException(
                    "The target Sales Profile must be explicitly {$requiredEligibility}-eligible with a governed reporting currency at the effective time."
                );
            }

            $from = $locked->{$field};
            $version = $locked->version + 1;
            DB::table('sales_booking_attribution_events')->insert([
                'id' => (string) Str::uuid(),
                'attribution_id' => $locked->id,
                'version' => $version,
                'event_type' => $eventType,
                'field_name' => $field,
                'from_value' => $from,
                'to_value' => $target?->id,
                'from_sales_profile_id' => $from,
                'to_sales_profile_id' => $target?->id,
                'effective_at' => $effectiveAt,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actorUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $projection = ['version' => $version, 'updated_user_id' => $actorUserId];
            if ($effectiveAt->lte(now())) {
                $projection[$field] = $target?->id;
                $projection['status'] = $this->projectionStatus($locked, $field, $target, $profiles);
            }
            $locked->update($projection);

            $this->events->record(
                'sales',
                $locked->company_id,
                'booking_attribution',
                $locked->id,
                "sales.attribution.{$eventType}",
                $version,
                1,
                ['booking_id' => $locked->booking_id, 'field' => $field, 'from' => $from, 'to' => $target?->id, 'effective_at' => $effectiveAt->toISOString()],
                $effectiveAt,
                $idempotencyKey,
            );

            if ($field === 'acquisition_sales_profile_id' && $from && $target) {
                $this->metricFacts->projectAcquisitionOwnerCorrection($locked, $from, $target->id, $version);
            }

            if ($effectiveAt->lte(now()) && $target) {
                $this->resolveExceptions(
                    $locked->booking_id,
                    $field === 'acquisition_sales_profile_id'
                        ? ['acquisition_owner_missing', 'acquisition_owner_ineligible']
                        : ['collection_handler_missing', 'collection_handler_ineligible', 'collection_handler_unassigned'],
                    $reason,
                    $actorUserId,
                );
            } elseif ($effectiveAt->lte(now()) && $field === 'collection_sales_profile_id') {
                DB::table('sales_attribution_exceptions')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'booking_id' => $locked->booking_id,
                    'exception_type' => 'collection_handler_unassigned',
                    'status' => 'open',
                    'details' => 'The collection portfolio was explicitly left unassigned by an audited transfer.',
                    'detected_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $locked->fresh();
        });
    }

    private function resolveExceptions(string $bookingId, array $types, string $reason, string $actorUserId): void
    {
        DB::table('sales_attribution_exceptions')
            ->where('booking_id', $bookingId)
            ->whereIn('exception_type', $types)
            ->where('status', 'open')
            ->update([
                'status' => 'resolved',
                'resolved_by' => $actorUserId,
                'resolved_at' => now(),
                'resolution' => $reason,
                'updated_at' => now(),
            ]);
    }

    private function projectionStatus(
        SalesBookingAttribution $attribution,
        string $field,
        ?SalesProfile $target,
        $profiles,
    ): string {
        $acquisitionProfile = $field === 'acquisition_sales_profile_id'
            ? $target
            : $profiles->get($attribution->acquisition_sales_profile_id);
        if ($acquisitionProfile
            && ! $this->profileEligibility->isEligibleAt($acquisitionProfile, ['acquisition'], $attribution->secured_at)) {
            $acquisitionProfile = null;
        }
        $collectionProfile = $field === 'collection_sales_profile_id'
            ? $target
            : $profiles->get($attribution->collection_sales_profile_id);
        if ($collectionProfile
            && ! $this->profileEligibility->isEligibleAt($collectionProfile, ['collection'], now())) {
            $collectionProfile = null;
        }

        return $acquisitionProfile
            && $acquisitionProfile->company_id === $attribution->company_id
            && $collectionProfile
            && $collectionProfile->company_id === $attribution->company_id
                ? 'active'
                : 'held';
    }

    private function profileConfigurationSnapshot(SalesProfile $profile): array
    {
        return $profile->only([
            'company_id',
            'staff_id',
            'sales_code',
            'status',
            'version',
            'acquisition_eligible',
            'collection_eligible',
            'commission_eligible',
            'reporting_currency',
            'effective_from',
            'effective_until',
        ]);
    }
}
