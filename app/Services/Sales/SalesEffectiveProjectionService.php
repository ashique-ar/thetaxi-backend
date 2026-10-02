<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesEffectiveProjectionService
{
    public function __construct(private readonly SalesProfileEligibilityService $profileEligibility) {}

    public function projectDue(int $limit = 500): array
    {
        $attributionEventIds = DB::table('sales_booking_attribution_events')
            ->where('event_type', 'collection_handler_transferred')
            ->whereNull('projected_at')
            ->where('effective_at', '<=', now())
            ->orderBy('effective_at')
            ->orderBy('version')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $attributionCount = 0;
        foreach ($attributionEventIds as $eventId) {
            $attributionCount += DB::transaction(function () use ($eventId): int {
                $candidate = DB::table('sales_booking_attribution_events')->where('id', $eventId)->first();
                if (! $candidate) {
                    return 0;
                }
                $attribution = SalesBookingAttribution::query()->lockForUpdate()->find($candidate->attribution_id);
                $event = DB::table('sales_booking_attribution_events')->where('id', $eventId)->lockForUpdate()->first();
                if (! $attribution || ! $event || $event->projected_at !== null
                    || CarbonImmutable::parse($event->effective_at)->isFuture()) {
                    return 0;
                }
                $this->projectAttributionEvent($event, $attribution);

                return 1;
            });
        }

        $profileEventIds = DB::table('sales_profile_events')
            ->where('event_type', 'portfolio_closed')
            ->whereNull('projected_at')
            ->where('occurred_at', '<=', now())
            ->orderBy('occurred_at')
            ->orderBy('profile_version')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $profileCount = 0;
        foreach ($profileEventIds as $eventId) {
            $profileCount += DB::transaction(function () use ($eventId): int {
                $candidate = DB::table('sales_profile_events')->where('id', $eventId)->first();
                if (! $candidate) {
                    return 0;
                }
                $profile = SalesProfile::query()->withTrashed()->lockForUpdate()->find($candidate->sales_profile_id);
                $event = DB::table('sales_profile_events')->where('id', $eventId)->lockForUpdate()->first();
                if (! $event || $event->projected_at !== null
                    || CarbonImmutable::parse($event->occurred_at)->isFuture()) {
                    return 0;
                }
                if ($profile && $profile->effective_until?->lte(now())) {
                    $profile->update([
                        'status' => 'ended',
                        'updated_user_id' => $event->actor_user_id,
                    ]);
                }
                DB::table('sales_profile_events')->where('id', $event->id)->update([
                    'projected_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            });
        }

        return ['attributions' => $attributionCount, 'profiles' => $profileCount];
    }

    private function projectAttributionEvent(object $event, SalesBookingAttribution $attribution): void
    {
        $effectiveAt = CarbonImmutable::parse($event->effective_at);
        $profileIds = array_values(array_unique(array_filter([
            $event->to_sales_profile_id,
            $attribution->acquisition_sales_profile_id,
        ])));
        sort($profileIds, SORT_STRING);
        $profiles = SalesProfile::query()
            ->withTrashed()
            ->whereIn('id', $profileIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $target = $event->to_sales_profile_id ? $profiles->get($event->to_sales_profile_id) : null;
        $eligibleTarget = $target
            && $target->company_id === $attribution->company_id
            && $this->profileEligibility->isEligibleAt($target, ['collection'], $effectiveAt)
                ? $target
                : null;
        $acquisition = $attribution->acquisition_sales_profile_id
            ? $profiles->get($attribution->acquisition_sales_profile_id)
            : null;
        $active = $acquisition
            && $this->profileEligibility->isEligibleAt($acquisition, ['acquisition'], $attribution->secured_at)
            && $eligibleTarget;

        $attribution->update([
            'collection_sales_profile_id' => $eligibleTarget?->id,
            'status' => $active ? 'active' : 'held',
            'updated_user_id' => $event->actor_user_id,
        ]);
        DB::table('booking_payment_schedules')
            ->where('booking_id', $attribution->booking_id)
            ->whereNull('deleted_at')
            ->whereNull('superseded_at')
            ->whereNotIn('status', ['paid', 'cancelled', 'superseded'])
            ->update([
                'collection_sales_profile_id' => $eligibleTarget?->id,
                'updated_user_id' => $event->actor_user_id,
                'updated_at' => now(),
            ]);
        DB::table('booking_collection_work_items')
            ->where('booking_id', $attribution->booking_id)
            ->whereIn('status', ['open', 'due', 'overdue'])
            ->update([
                'assigned_sales_profile_id' => $eligibleTarget?->id,
                'updated_at' => now(),
            ]);

        if (! $eligibleTarget) {
            DB::table('sales_attribution_exceptions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'booking_id' => $attribution->booking_id,
                'exception_type' => $event->to_sales_profile_id
                    ? 'collection_handler_ineligible'
                    : 'collection_handler_unassigned',
                'status' => 'open',
                'details' => 'The effective collection-handler transfer could not resolve an eligible current owner.',
                'detected_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('sales_attribution_exceptions')
                ->where('booking_id', $attribution->booking_id)
                ->whereIn('exception_type', [
                    'collection_handler_missing',
                    'collection_handler_ineligible',
                    'collection_handler_unassigned',
                ])
                ->where('status', 'open')
                ->update([
                    'status' => 'resolved',
                    'resolved_by' => $event->actor_user_id,
                    'resolved_at' => now(),
                    'resolution' => $event->reason,
                    'updated_at' => now(),
                ]);
        }

        DB::table('sales_booking_attribution_events')->where('id', $event->id)->update([
            'projected_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
