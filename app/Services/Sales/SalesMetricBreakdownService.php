<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesBookingAttribution;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SalesMetricBreakdownService
{
    public function __construct(private readonly SalesPolicySettingsService $policySettings) {}

    /**
     * Resolve report-relative cohort and stable commission category without
     * exposing or mutating the raw metric-fact dimensions.
     *
     * @return array<string, array{booking_id: ?string, collection_cohort: ?string, commission_category: ?string}>
     */
    public function resolve(Collection $facts, string $companyId, string $from, string $to): array
    {
        $timezone = $this->policySettings->businessTimezone($companyId);
        abort_unless(is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true),
            409, 'An approved Sales business timezone is required for metric cohort calculations.');
        $fromDate = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $toDate = CarbonImmutable::parse($to, $timezone)->startOfDay();
        $records = $facts->mapWithKeys(function ($fact): array {
            $dimensions = is_string($fact->dimensions)
                ? (json_decode($fact->dimensions, true) ?: []) : ((array) $fact->dimensions);
            $bookingId = data_get($dimensions, 'booking_id');

            return [(string) $fact->id => [
                'booking_id' => is_string($bookingId) && Str::isUuid($bookingId) ? $bookingId : null,
                'commission_category' => in_array(data_get($dimensions, 'commission_category'), ['one_time', 'long_term'], true)
                    ? data_get($dimensions, 'commission_category') : null,
            ]];
        });
        $attributions = SalesBookingAttribution::withTrashed()->where('company_id', $companyId)
            ->whereIn('booking_id', $records->pluck('booking_id')->filter()->unique()->values()->all())
            ->get(['id', 'booking_id', 'root_attribution_id', 'secured_at'])->keyBy('booking_id');
        $roots = SalesBookingAttribution::withTrashed()->where('company_id', $companyId)
            ->whereIn('id', $attributions->pluck('root_attribution_id')->filter()->unique()->values()->all())
            ->get(['id', 'secured_at'])->keyBy('id');

        return $records->map(function (array $record) use ($attributions, $roots, $fromDate, $toDate, $timezone): array {
            $attribution = $record['booking_id'] ? $attributions->get($record['booking_id']) : null;
            $securedAt = $attribution?->root_attribution_id
                ? $roots->get($attribution->root_attribution_id)?->secured_at
                : $attribution?->secured_at;
            $securedDate = $securedAt ? CarbonImmutable::parse($securedAt)->setTimezone($timezone)->startOfDay() : null;
            $cohort = $securedDate && $securedDate->gte($fromDate) && $securedDate->lte($toDate)
                ? 'current_period_booking' : ($securedDate?->lt($fromDate) ? 'prior_period_booking' : null);

            return $record + ['collection_cohort' => $cohort];
        })->all();
    }
}
