<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\Booking;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Services\SingleCompanyScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingAttributionService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly CommissionPlanResolver $commissionPlans,
        private readonly SalesCrmService $crm,
        private readonly SalesMetricFactService $metricFacts,
        private readonly SalesProfileEligibilityService $profileEligibility,
    ) {}

    public function captureConfirmation(Booking $booking): ?SalesBookingAttribution
    {
        if (! $this->isConfirmed($booking)) {
            return null;
        }

        return DB::transaction(function () use ($booking) {
            $existing = SalesBookingAttribution::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $securedAt = $booking->confirmed_at ?? $booking->updated_at ?? now();
            $resolved = $this->resolveProfiles($booking, $securedAt);
            $rootAttribution = $resolved['root'];
            $acquisitionProfile = $resolved['acquisition_profile'];
            $acquisitionProfileId = $resolved['acquisition_profile_id'];
            $collectionProfile = $resolved['collection_profile'];
            $collectionProfileId = $resolved['collection_profile_id'];
            $companyId = $resolved['company_id'];
            $value = (string) ($booking->total_actual ?? $booking->total_estimated ?? 0);
            $currency = strtoupper((string) ($booking->currency ?: 'LKR'));
            $category = ($booking->is_recurring || $rootAttribution) ? 'long_term' : 'one_time';
            $classification = $rootAttribution ? 'recurring_fulfilment' : 'new_business';
            $newCustomerStatus = $this->newCustomerStatus($booking, $companyId, $securedAt, $rootAttribution);

            $attribution = SalesBookingAttribution::create([
                'booking_id' => $booking->id,
                'root_attribution_id' => $rootAttribution?->root_attribution_id ?? $rootAttribution?->id,
                'company_id' => $companyId,
                'acquisition_sales_profile_id' => $acquisitionProfileId,
                'collection_sales_profile_id' => $collectionProfileId,
                'customer_id' => $booking->customer_id,
                'commission_category' => $category,
                'business_classification' => $classification,
                'classification_source' => $rootAttribution ? 'recurring_series_root' : ($booking->is_recurring ? 'booking.is_recurring' : 'booking.non_recurring'),
                'secured_at' => $securedAt,
                'contract_value_source' => $value,
                'source_currency' => $currency,
                'contract_value_lkr' => $currency === 'LKR' ? $value : null,
                'fx_rate_to_lkr' => $currency === 'LKR' ? 1 : null,
                'fx_rate_at' => $currency === 'LKR' ? $securedAt : null,
                'new_customer_status' => $newCustomerStatus,
                'status' => $acquisitionProfile && $collectionProfile && $companyId ? 'active' : 'held',
                'version' => 1,
                'created_user_id' => $booking->created_user_id,
            ]);

            DB::table('sales_booking_attribution_events')->insert([
                'id' => (string) Str::uuid(),
                'attribution_id' => $attribution->id,
                'version' => 1,
                'event_type' => 'confirmed',
                'to_sales_profile_id' => $collectionProfileId,
                'effective_at' => $securedAt,
                'reason' => 'Canonical booking confirmation attribution.',
                'idempotency_key' => "booking-confirmed:{$booking->id}",
                'actor_user_id' => $booking->updated_user_id ?? $booking->created_user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordExceptions(
                $booking,
                $acquisitionProfileId,
                $acquisitionProfile,
                $collectionProfileId,
                $collectionProfile,
                $companyId,
                $currency,
            );
            $attribution = $this->commissionPlans->freezeFamilyForAttribution($attribution) ?? $attribution;
            $this->events->record(
                'sales',
                $companyId,
                'booking_attribution',
                $attribution->id,
                'sales.booking.confirmed',
                1,
                1,
                [
                    'booking_id' => $booking->id,
                    'attribution_id' => $attribution->id,
                    'acquisition_sales_profile_id' => $acquisitionProfileId,
                    'collection_sales_profile_id' => $collectionProfileId,
                    'commission_category' => $category,
                    'secured_at' => $securedAt->toISOString(),
                ],
                $securedAt,
                "booking:{$booking->id}",
            );

            $this->crm->markWonFromBooking($booking);
            $this->metricFacts->projectBookingConfirmation($attribution);

            return $attribution;
        });
    }

    public function preview(Booking $booking): array
    {
        $securedAt = $booking->confirmed_at ?? $booking->updated_at ?? now();
        $resolved = $this->resolveProfiles($booking, $securedAt);
        $root = $resolved['root'];
        $acquisitionProfile = $resolved['acquisition_profile'];
        $acquisitionProfileId = $resolved['acquisition_profile_id'];
        $collectionProfile = $resolved['collection_profile'];
        $collectionProfileId = $resolved['collection_profile_id'];
        $companyId = $resolved['company_id'];
        $currency = strtoupper((string) ($booking->currency ?: 'LKR'));

        return [
            'booking_id' => $booking->id,
            'booking_label' => $booking->booking_number ?: ($booking->log_code ?: ($booking->created_at ? 'Booking on ' . $booking->created_at->format('Y-m-d') : 'Booking')),
            'eligible' => $this->isConfirmed($booking),
            'root_attribution_id' => $root?->root_attribution_id ?? $root?->id,
            'company_id' => $companyId,
            'acquisition_sales_profile_id' => $acquisitionProfileId,
            'collection_sales_profile_id' => $collectionProfileId,
            'commission_category' => ($booking->is_recurring || $root) ? 'long_term' : 'one_time',
            'business_classification' => $root ? 'recurring_fulfilment' : 'new_business',
            'new_customer_status' => $this->newCustomerStatus($booking, $companyId, $securedAt, $root),
            'source_currency' => $currency,
            'requires_fx' => $currency !== 'LKR',
            'holds' => array_values(array_filter([
                ! $acquisitionProfileId ? 'acquisition_owner_missing' : null,
                $acquisitionProfileId && ! $acquisitionProfile ? 'acquisition_owner_ineligible' : null,
                ! $collectionProfileId ? 'collection_handler_missing' : null,
                $collectionProfileId && ! $collectionProfile ? 'collection_handler_ineligible' : null,
                $companyId ? null : 'legal_entity_missing',
                $currency === 'LKR' ? null : 'fx_snapshot_missing',
            ])),
        ];
    }

    private function isConfirmed(Booking $booking): bool
    {
        return (bool) $booking->confirmed || $booking->status === 'confirmed' || $booking->confirmed_at !== null;
    }

    private function resolveAcquisitionStaff(Booking $booking): ?Staff
    {
        if ($booking->commission_owner_staff_id) {
            return Staff::withTrashed()->find($booking->commission_owner_staff_id);
        }

        if (! $booking->created_user_id) {
            return null;
        }

        $staff = Staff::withTrashed()
            ->where('user_id', $booking->created_user_id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $staff->count() === 1 ? $staff->first() : null;
    }

    private function resolveProfiles(Booking $booking, CarbonInterface $securedAt): array
    {
        $root = $this->rootAttribution($booking);
        if ($root) {
            $acquisitionProfileId = $root->acquisition_sales_profile_id;
            $acquisitionProfile = $this->profileEligibility->byIdAt(
                $acquisitionProfileId,
                ['acquisition'],
                $root->secured_at,
                $root->company_id,
            );
            $collectionProfileId = $this->collectionProfileAt($root, $securedAt);
            $collectionProfile = $this->profileEligibility->byIdAt(
                $collectionProfileId,
                ['collection'],
                $securedAt,
                $root->company_id,
            );

            return [
                'root' => $root,
                'acquisition_profile_id' => $acquisitionProfileId,
                'acquisition_profile' => $acquisitionProfile,
                'collection_profile_id' => $collectionProfileId,
                'collection_profile' => $collectionProfile,
                'company_id' => $root->company_id,
            ];
        }

        $staff = $this->resolveAcquisitionStaff($booking);
        $acquisitionProfile = $staff
            ? $this->profileEligibility->forStaffAt($staff, 'acquisition', $securedAt)
            : null;
        $collectionProfile = $acquisitionProfile
            && $this->profileEligibility->isEligibleAt($acquisitionProfile, ['collection'], $securedAt)
                ? $acquisitionProfile
                : null;

        return [
            'root' => null,
            'acquisition_profile_id' => $acquisitionProfile?->id,
            'acquisition_profile' => $acquisitionProfile,
            'collection_profile_id' => $collectionProfile?->id,
            'collection_profile' => $collectionProfile,
            'company_id' => $acquisitionProfile?->company_id
                ?? $staff?->company_id
                ?? app(SingleCompanyScope::class)->defaultCompany()?->id,
        ];
    }

    private function rootAttribution(Booking $booking): ?SalesBookingAttribution
    {
        return $booking->recurring_series_id
            ? SalesBookingAttribution::query()->where('booking_id', $booking->recurring_series_id)->first()
            : null;
    }

    private function collectionProfileAt(SalesBookingAttribution $attribution, CarbonInterface $at): ?string
    {
        $latest = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('field_name', 'collection_sales_profile_id')
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')
            ->orderByDesc('version')
            ->first();
        if ($latest) {
            return $latest->to_sales_profile_id;
        }

        $confirmed = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('event_type', 'confirmed')
            ->where('effective_at', '<=', $at)
            ->orderBy('effective_at')
            ->orderBy('version')
            ->first();

        return $confirmed ? $confirmed->to_sales_profile_id : $attribution->collection_sales_profile_id;
    }

    private function newCustomerStatus(Booking $booking, ?string $companyId, $securedAt, ?SalesBookingAttribution $root): string
    {
        if ($root) {
            return 'existing';
        }
        if (! $companyId || ! $booking->customer_id) {
            return 'pending';
        }

        return SalesBookingAttribution::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $booking->customer_id)
            ->where('secured_at', '<', $securedAt)
            ->exists() ? 'existing' : 'new';
    }

    private function recordExceptions(
        Booking $booking,
        ?string $acquisitionProfileId,
        ?SalesProfile $acquisitionProfile,
        ?string $collectionProfileId,
        ?SalesProfile $collectionProfile,
        ?string $companyId,
        string $currency,
    ): void {
        $exceptions = array_filter([
            'acquisition_owner_missing' => $acquisitionProfileId ? null : 'No effective Sales Profile was resolved from the frozen booking owner/creator.',
            'acquisition_owner_ineligible' => $acquisitionProfileId && ! $acquisitionProfile
                ? 'The frozen acquisition owner lacked explicit acquisition eligibility or a governed reporting currency at the secured time.' : null,
            'collection_handler_missing' => $collectionProfileId ? null : 'No collection handler was assigned at the secured time.',
            'collection_handler_ineligible' => $collectionProfileId && ! $collectionProfile
                ? 'The collection handler lacked explicit collection eligibility or a governed reporting currency at the secured time.' : null,
            'legal_entity_missing' => $companyId ? null : 'The booking attribution has no immutable legal entity.',
            'fx_snapshot_missing' => $currency === 'LKR' ? null : "No governed LKR FX snapshot exists for {$currency}.",
        ]);

        foreach ($exceptions as $type => $details) {
            DB::table('sales_attribution_exceptions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'booking_id' => $booking->id,
                'exception_type' => $type,
                'status' => 'open',
                'details' => $details,
                'detected_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
