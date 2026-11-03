<?php

namespace App\Services\Tours;

use App\Models\Service\ServiceVehicleClassSetting;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Tours\TourItinerary;
use App\Models\Tours\TourQuote;
use App\Models\TermsAndCondition;
use App\Models\Vehicle\VehiclePricing\PriceAdjustment;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TourItineraryQuoteService
{
    public function __construct(
        private readonly TourRoutePlanner $planner,
        private readonly BookingFlowService $bookingFlow,
        private readonly CurrencyService $currencyService,
    )
    {
    }

    public function options(TourItinerary $itinerary): array
    {
        $routes = $this->plan($itinerary);
        if ($routes['status'] === 'unavailable') {
            return $routes + ['price_difference' => null];
        }
        if ($routes['status'] !== 'ready') {
            $reason = 'This route has a timing conflict and needs staff review before a transport price can be confirmed.';
            $routes['original']['quote'] = ['status' => 'quotation_required', 'reason' => $reason];
            $routes['suggested']['quote'] = ['status' => 'quotation_required', 'reason' => $reason];

            return $routes + ['price_difference' => null];
        }

        $setting = ServiceVehicleClassSetting::query()
            ->where('service_type_id', $itinerary->service_type_id)
            ->where('vehicle_class_id', $itinerary->vehicle_class_id)
            ->where('is_active', true)
            ->where('is_hidden', false)
            ->first();
        if (!$setting || $setting->is_inquiry_only) {
            $routes['original']['quote'] = ['status' => 'quotation_required', 'reason' => 'This vehicle class requires staff confirmation.'];
            $routes['suggested']['quote'] = ['status' => 'quotation_required', 'reason' => 'This vehicle class requires staff confirmation.'];

            return $routes + ['price_difference' => null];
        }

        $originalPricing = $this->calculatePricing($itinerary, $routes['original']['distance_km']);
        $routes['original']['quote'] = $originalPricing['public'];
        $routes['suggested']['quote'] = $routes['original']['distance_km'] === $routes['suggested']['distance_km']
            ? $routes['original']['quote']
            : $this->calculatePricing($itinerary, $routes['suggested']['distance_km'])['public'];
        $routes['price_difference'] = ($routes['original']['quote']['status'] ?? null) === 'priced'
            && ($routes['suggested']['quote']['status'] ?? null) === 'priced'
            ? round($routes['suggested']['quote']['total_amount'] - $routes['original']['quote']['total_amount'], 2)
            : null;

        return $routes;
    }

    public function createQuote(TourItinerary $itinerary, int $revision): TourQuote
    {
        if ($itinerary->status === 'accepted') {
            return $itinerary->quotes()->where('status', 'accepted')->latest()->firstOrFail();
        }
        abort_unless(in_array($itinerary->status, ['draft', 'quoted'], true), 409, 'This itinerary can no longer be quoted.');
        $existing = $itinerary->quotes()
            ->where('status', 'current')
            ->where('itinerary_revision', $revision)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
        if ($existing) {
            return $existing;
        }

        $routes = $this->plan($itinerary);
        if ($routes['status'] !== 'ready') {
            throw ValidationException::withMessages(['route' => 'Review the route and its timing before requesting a quote.']);
        }
        $setting = ServiceVehicleClassSetting::query()
            ->where('service_type_id', $itinerary->service_type_id)
            ->where('vehicle_class_id', $itinerary->vehicle_class_id)
            ->where('is_active', true)
            ->where('is_hidden', false)
            ->first();
        if (!$setting || $setting->is_inquiry_only) {
            throw ValidationException::withMessages(['pricing' => 'This vehicle class requires staff confirmation.']);
        }
        $baseDistance = $routes['original']['distance_km'];
        $calculation = $this->calculatePricing($itinerary, $baseDistance, true);
        if (($calculation['public']['status'] ?? null) !== 'priced') {
            throw ValidationException::withMessages(['pricing' => $calculation['public']['reason'] ?? 'Transport pricing needs staff review.']);
        }
        $pricing = $calculation['public'];
        $fullPricingSnapshot = $calculation['snapshot'];
        $vehicleClass = $itinerary->vehicleClass;
        $fullPricingSnapshot['tour_pricing_lock']['purchased_class'] = [
            'id' => (string) $vehicleClass->id,
            'name' => $vehicleClass->name,
            'description' => $setting->description ?: $vehicleClass->description,
            'max_passengers' => (int) $setting->max_passengers,
            'max_luggage' => (int) $setting->max_luggage,
        ];
        $fullPricingSnapshot['tour_pricing_lock']['branches'] = $fullPricingSnapshot['_tour_pricing_branches'] ?? [];
        unset($fullPricingSnapshot['_tour_pricing_branches']);

        return DB::transaction(function () use ($itinerary, $revision, $routes, $fullPricingSnapshot, $pricing, $baseDistance): TourQuote {
            $locked = TourItinerary::query()->whereKey($itinerary->id)->lockForUpdate()->firstOrFail();
            if ($locked->revision !== $revision || $locked->revision !== $itinerary->revision) {
                abort(409, 'This itinerary changed while its quote was being prepared. Request a new route and quote.');
            }
            $currentQuote = $locked->quotes()->where('status', 'current')->where('expires_at', '>', now())->latest()->first();
            if ($currentQuote && $currentQuote->itinerary_revision === $revision) {
                return $currentQuote;
            }
            $locked->quotes()->where('status', 'current')->update(['status' => 'superseded']);
            $quote = $locked->quotes()->create([
                'itinerary_revision' => $revision,
                'status' => 'current',
                'expires_at' => now()->addMinutes(30),
                'currency' => $itinerary->currency,
                'total_amount' => $pricing['total_amount'],
                'distance_km' => $baseDistance,
                'route_snapshot' => $routes['original'],
                'pricing_snapshot' => $fullPricingSnapshot,
                'terms_snapshot' => $this->termsSnapshot($itinerary),
            ]);
            $locked->status = 'quoted';
            $locked->save();

            return $quote;
        });
    }

    public function accept(TourItinerary $itinerary, string $quoteId, int $revision, ?string $userId = null, string $channel = 'customer'): TourQuote
    {
        return DB::transaction(function () use ($itinerary, $quoteId, $revision, $userId, $channel): TourQuote {
            $locked = TourItinerary::query()->whereKey($itinerary->id)->lockForUpdate()->firstOrFail();
            $quote = $locked->quotes()->lockForUpdate()->findOrFail($quoteId);
            if ($quote->status === 'accepted' && $locked->status === 'accepted') {
                return $quote;
            }
            if ($locked->revision !== $revision || $quote->itinerary_revision !== $revision || $quote->status !== 'current') {
                abort(409, 'This quote is no longer current. Recalculate the itinerary before accepting it.');
            }
            if ($quote->expires_at && $quote->expires_at->isPast()) {
                abort(409, 'This quote has expired. Request a new quote.');
            }

            $locked->quotes()->where('status', 'current')->where('id', '!=', $quote->id)->update(['status' => 'superseded']);
            $quote->status = 'accepted';
            $quote->accepted_at = now();
            $quote->accepted_by_user_id = $userId;
            $quote->acceptance_channel = $channel;
            $quote->accepted_terms_hash = hash('sha256', \App\Support\Foundation\CanonicalJson::encode($quote->terms_snapshot ?? []));
            $quote->save();
            $locked->status = 'accepted';
            $locked->save();

            return $quote;
        });
    }

    private function plan(TourItinerary $itinerary): array
    {
        $itinerary->loadMissing('days.stops.activity');
        $dayPlans = [];
        $issues = [];
        $overallStatus = 'ready';

        foreach ($itinerary->days as $day) {
            $start = $day->start_location_snapshot;
            $end = $day->end_location_snapshot;
            if (!$start || !$end) {
                throw ValidationException::withMessages(['days' => "Choose start and end destinations for day {$day->day_number} before requesting a route."]);
            }
            $stops = [
                [
                    'id' => "start-{$day->id}",
                    'kind' => 'endpoint',
                    'name' => $start['name'],
                    'latitude' => (float) $start['latitude'],
                    'longitude' => (float) $start['longitude'],
                    'locked' => true,
                ]
            ];
            foreach ($day->stops as $stop) {
                $stops[] = [
                    'id' => $stop->id,
                    'kind' => $stop->kind,
                    'catalogue_id' => $stop->tour_activity_id ?: $stop->tour_destination_id,
                    'name' => $stop->location_snapshot['name'] ?? '',
                    'latitude' => (float) $stop->location_snapshot['latitude'],
                    'longitude' => (float) $stop->location_snapshot['longitude'],
                    'visit_minutes' => $stop->visit_duration_minutes,
                    'locked' => $day->is_locked || $stop->is_locked,
                    ...($stop->activity?->opens_at ? ['opens_at' => substr((string) $stop->activity->opens_at, 0, 5)] : []),
                    ...($stop->activity?->closes_at ? ['closes_at' => substr((string) $stop->activity->closes_at, 0, 5)] : []),
                    ...($stop->arrival_time ? ['arrival_time' => substr((string) $stop->arrival_time, 0, 5)] : []),
                ];
            }
            $stops[] = [
                'id' => "end-{$day->id}",
                'kind' => 'endpoint',
                'name' => $end['name'],
                'latitude' => (float) $end['latitude'],
                'longitude' => (float) $end['longitude'],
                'locked' => true,
            ];
            $result = $this->planner->suggest($stops, substr((string) $day->start_time, 0, 5));
            if ($result['status'] === 'unavailable') {
                $overallStatus = 'unavailable';
            } elseif ($result['status'] === 'review_required' && $overallStatus === 'ready') {
                $overallStatus = 'review_required';
            }
            foreach ($result['issues'] as $issue) {
                $issues[] = $issue + ['day_number' => $day->day_number];
            }
            $dayPlans[] = [
                'day_number' => $day->day_number,
                'start_time' => substr((string) $day->start_time, 0, 5),
                'original' => $result['original'],
                'suggested' => $result['suggested'],
                'suggested_stop_ids' => collect($result['suggested']['stops'])->where('kind', '!=', 'endpoint')->pluck('id')->values(),
                'changed' => $result['changed'],
                'issues' => $result['issues'],
            ];
        }

        $original = $this->aggregate($dayPlans, 'original');
        $suggested = $this->aggregate($dayPlans, 'suggested');

        return [
            'status' => $overallStatus,
            'days' => $dayPlans,
            'original' => $original,
            'suggested' => $suggested,
            'changed' => collect($dayPlans)->contains(fn(array $day) => $day['changed']),
            'issues' => $issues,
        ];
    }

    private function aggregate(array $dayPlans, string $key): array
    {
        $legs = [];
        $stops = [];
        $distance = 0.0;
        $duration = 0;
        $visitDuration = 0;
        $waitingDuration = 0;
        $distanceAvailable = true;
        $durationAvailable = true;
        foreach ($dayPlans as $day) {
            $route = $day[$key];
            if (!is_numeric($route['distance_km'] ?? null)) {
                $distanceAvailable = false;
            } else {
                $distance += (float) $route['distance_km'];
            }
            if (!is_numeric($route['duration_seconds'] ?? null)) {
                $durationAvailable = false;
            } else {
                $duration += (int) $route['duration_seconds'];
            }
            $visitDuration += (int) ($route['visit_duration_seconds'] ?? 0);
            $waitingDuration += (int) ($route['waiting_duration_seconds'] ?? 0);
            foreach ($route['legs'] as $leg) {
                $legs[] = $leg + ['day_number' => $day['day_number']];
            }
            foreach ($route['stops'] as $stop) {
                $stops[] = $stop + ['day_number' => $day['day_number']];
            }
        }

        return [
            'stops' => $stops,
            'legs' => $legs,
            'distance_km' => $distanceAvailable ? round($distance, 3) : null,
            'duration_seconds' => $durationAvailable ? $duration : null,
            'visit_duration_seconds' => $visitDuration,
            'waiting_duration_seconds' => $waitingDuration,
            'total_duration_seconds' => $durationAvailable ? $duration + $visitDuration + $waitingDuration : null,
            'days' => collect($dayPlans)->map(fn(array $day): array => [
                'day_number' => $day['day_number'],
                'start_time' => $day['start_time'],
                'schedule' => $day[$key]['schedule'] ?? [],
                'timing_issues' => $day[$key]['timing_issues'] ?? [],
            ])->values()->all(),
        ];
    }

    private function calculatePricing(
        TourItinerary $itinerary,
        float $distance,
        bool $captureTourPricingBranches = false
    ): array
    {
        $baseCurrency = $this->currencyService->getBookingBaseCurrency();
        $pricing = $this->bookingFlow->calculateDynamicPricing([
            'service_type_id' => $itinerary->service_type_id,
            'vehicle_class_id' => $itinerary->vehicle_class_id,
            'package_id' => $itinerary->service_package_id,
            'from_date' => $itinerary->start_date->toDateString(),
            'to_date' => Carbon::parse($itinerary->start_date)->addDays($itinerary->duration_days - 1)->toDateString(),
            'from_time' => '00:00',
            'to_time' => '23:59',
            'duration_days' => $itinerary->duration_days,
            'duration_minutes' => $itinerary->duration_days * 1440,
            'number_of_days' => $itinerary->duration_days,
            'journey_distance' => $distance,
            'total_distance' => $distance,
            'pricing_context' => $itinerary->pricing_context,
            'mode' => 'full_calculation',
        ], $captureTourPricingBranches);
        $pricing = $this->currencyService->convertPricingStructure($pricing, $baseCurrency, $itinerary->currency);
        if (!isset($pricing['calculation_metadata']['definition_used']) || !is_numeric($pricing['total_amount'] ?? null)) {
            return [
                'public' => ['status' => 'quotation_required', 'reason' => 'Transport pricing needs staff review because a complete class rate configuration was not available.'],
                'snapshot' => null,
            ];
        }

        $definition = VehiclePricingCalculationDefinition::query()->find($pricing['calculation_metadata']['definition_used']);
        if (!$definition) {
            return [
                'public' => ['status' => 'quotation_required', 'reason' => 'The selected pricing definition could not be saved with this quote. Staff review is required.'],
                'snapshot' => null,
            ];
        }

        $adjustmentIds = collect($pricing['adjustment_details']['adjustments'] ?? [])
            ->pluck('price_adjustment_id')->filter()->unique()->values();
        $adjustments = PriceAdjustment::query()->whereIn('id', $adjustmentIds)
            ->orderByPriority()->get();
        if ($adjustments->count() !== $adjustmentIds->count()) {
            return [
                'public' => ['status' => 'quotation_required', 'reason' => 'A price adjustment changed while the quote was being prepared. Please request a new quote.'],
                'snapshot' => null,
            ];
        }

        $pricing['tour_pricing_lock'] = [
            'definition_id' => (string) $definition->id,
            'definition' => $definition->only([
                'id',
                'service_type_id',
                'name',
                'formula',
                'variables',
                'conditions',
                'owner_type',
                'owner_id',
                'priority',
            ]),
            'resolved_variables' => $pricing['calculation_metadata']['resolved_variables'] ?? [],
            'package_included_km' => $pricing['km_calculations']['allowed_km']
                ?? ($pricing['calculation_metadata']['resolved_variables']['package_included_km'] ?? null),
            'slab_information' => $pricing['slab_information'] ?? null,
            'price_adjustments' => $adjustments->map(fn (PriceAdjustment $adjustment) => $adjustment->only([
                'id', 'name', 'description', 'scope', 'service_type_id', 'vehicle_group_id',
                'owner_type', 'owner_id', 'adjustment_type', 'percentage_change',
                'fixed_amount_change', 'applies_to', 'applicable_contexts',
                'minimum_booking_amount', 'maximum_discount_amount', 'is_active',
                'priority', 'is_cumulative', 'valid_from', 'valid_to', 'usage_limit',
                'usage_count', 'created_at',
            ]))->values()->all(),
        ];

        return [
            'public' => [
                'status' => 'priced',
                'pricing_basis' => 'vehicle_class',
                'total_amount' => (float) $pricing['total_amount'],
                'currency' => $itinerary->currency,
                'breakdown' => $pricing['breakdown'] ?? [],
            ],
            'snapshot' => $pricing,
        ];
    }

    private function termsSnapshot(TourItinerary $itinerary): array
    {
        $service = $itinerary->serviceType;
        $terms = TermsAndCondition::getForCheckout((string) $service->id, 'quotation');
        if ($service->code) {
            $terms = $terms->merge(TermsAndCondition::getForCheckout((string) $service->code, 'quotation'));
        }

        return $terms->merge(TermsAndCondition::getGeneralServiceTerms())
            ->unique('id')->values()->map(fn(TermsAndCondition $term): array => [
                'id' => (string) $term->id,
                'title' => (string) $term->title,
                'version' => (string) ($term->version ?? ''),
                'content' => (string) $term->content,
            ])->all();
    }
}
