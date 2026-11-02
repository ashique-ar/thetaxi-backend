<?php

namespace App\Http\Controllers\Api\Tours;

use App\Http\Controllers\Controller;
use App\Models\Service\ServicePackage;
use App\Models\Service\ServiceType;
use App\Models\Service\ServiceVehicleClassSetting;
use App\Models\Tours\TourActivity;
use App\Models\Tours\TourDestination;
use App\Models\Tours\TourItinerary;
use App\Models\Tours\TourItineraryDay;
use App\Models\Tours\TourQuote;
use App\Models\Customer;
use App\Models\User;
use App\Services\UserContextService;
use App\Services\Tours\TourBookingConversionService;
use App\Services\WebsiteSettingsService;
use Illuminate\Support\Facades\Validator;
use App\Services\Tours\TourItineraryQuoteService;
use App\Services\Tours\TourFormConfiguration;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TourItineraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->customerPortalProfile($request);
        $drafts = TourItinerary::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['draft', 'quoted', 'accepted'])
            ->with('serviceType:id,name')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'service_type_id', 'start_date', 'duration_days', 'status', 'updated_at'])
            ->map(fn(TourItinerary $itinerary): array => [
                'id' => $itinerary->id,
                'service_type_id' => $itinerary->service_type_id,
                'service_name' => $itinerary->serviceType?->name,
                'start_date' => $itinerary->start_date?->toDateString(),
                'duration_days' => (int) $itinerary->duration_days,
                'status' => $itinerary->status,
                'updated_at' => $itinerary->updated_at?->toIso8601String(),
            ])->values();

        return response()->json(['success' => true, 'data' => $drafts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateItinerary($request);
        $this->customerPortalProfile($request);
        $service = $this->assertConfiguration($data, $request);
        $data['custom_answers'] = TourFormConfiguration::validate($service, $request->input('custom_answers', []));
        $data['custom_fields_snapshot'] = TourFormConfiguration::fields($service);
        $package = !empty($data['service_package_id']) ? ServicePackage::findOrFail($data['service_package_id']) : null;
        $data['service_package_template_version'] = $data['service_package_template_version'] ?? ($package?->tour_itinerary_template ? $package->tour_itinerary_template_version : null);

        $itinerary = DB::transaction(function () use ($request, $data, $service): TourItinerary {
            $itinerary = TourItinerary::create([
                ...$this->itineraryFields($data),
                'user_id' => $request->user()->id,
                'service_type_id' => $service->id,
                'pricing_context' => $request->route('tour_pricing_context') === 'portal' ? 'portal' : 'public',
                'revision' => 1,
                'status' => 'draft',
            ]);
            $this->saveDays($itinerary, $data);

            return $itinerary;
        });

        return response()->json(['success' => true, 'data' => $this->payload($itinerary)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $itinerary = $this->ownedItinerary($request, $id);

        return response()->json(['success' => true, 'data' => $this->payload($itinerary)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validateItinerary($request, true);
        $itinerary = TourItinerary::query()->where('user_id', $request->user()->id)->findOrFail($id);
        if (isset($data['service_type_id']) && $data['service_type_id'] !== $itinerary->service_type_id) {
            throw ValidationException::withMessages(['service_type_id' => 'A draft cannot be moved to a different service type.']);
        }
        abort_unless(in_array($itinerary->status, ['draft', 'quoted'], true), 409, 'An accepted or converted itinerary cannot be edited here.');
        $service = $this->assertConfiguration($data + ['service_type_id' => $itinerary->service_type_id], $request);
        $data['custom_answers'] = TourFormConfiguration::validate($service, $request->input('custom_answers', []));
        $data['custom_fields_snapshot'] = TourFormConfiguration::fields($service);
        if (!array_key_exists('service_package_template_version', $data)) {
            $samePackage = (string) ($data['service_package_id'] ?? '') === (string) ($itinerary->service_package_id ?? '');
            $package = !empty($data['service_package_id']) ? ServicePackage::findOrFail($data['service_package_id']) : null;
            $data['service_package_template_version'] = $samePackage
                ? $itinerary->service_package_template_version
                : ($package?->tour_itinerary_template ? $package->tour_itinerary_template_version : null);
        }

        $updated = DB::transaction(function () use ($request, $data, $itinerary): TourItinerary {
            $locked = TourItinerary::query()->where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($itinerary->id);
            if ($locked->revision !== (int) $data['expected_revision']) {
                abort(409, 'This itinerary changed since it was loaded. Reload it before saving.');
            }

            $locked->quotes()->whereIn('status', ['current', 'accepted'])->update(['status' => 'superseded']);
            $locked->days()->withTrashed()->get()->each(fn(TourItineraryDay $day) => $day->forceDelete());
            $locked->fill($this->itineraryFields($data));
            $locked->revision++;
            $locked->status = 'draft';
            $locked->save();
            $this->saveDays($locked, $data);

            return $locked;
        });

        return response()->json(['success' => true, 'data' => $this->payload($updated)]);
    }

    public function routeOptions(Request $request, string $id, TourItineraryQuoteService $quoteService): JsonResponse
    {
        $data = $request->validate(['expected_revision' => 'required|integer|min:1']);
        $itinerary = $this->ownedItinerary($request, $id)->load('days.stops');
        if ($itinerary->revision !== (int) $data['expected_revision']) {
            abort(409, 'This itinerary changed. Reload it before requesting route options.');
        }

        return response()->json(['success' => true, 'data' => $quoteService->options($itinerary)]);
    }

    public function applyRoute(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'days' => 'required|array',
            'custom_answers' => 'sometimes|array|max:40',
            'days.*.day_number' => 'required|integer|min:1|max:60|distinct:strict',
            'days.*.stop_ids' => 'present|array|max:40',
            'days.*.stop_ids.*' => 'required|uuid|distinct:strict',
        ]);
        $updated = DB::transaction(function () use ($request, $id, $data): TourItinerary {
            $itinerary = TourItinerary::query()->where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
            if ($itinerary->revision !== (int) $data['expected_revision']) {
                abort(409, 'This itinerary changed. Reload it before applying the route.');
            }
            abort_unless(in_array($itinerary->status, ['draft', 'quoted'], true), 409, 'An accepted or converted itinerary cannot be edited here.');
            if (count($data['days']) !== $itinerary->duration_days) {
                throw ValidationException::withMessages(['days' => 'Include the current stop order for every trip day.']);
            }
            $orders = collect($data['days'])->keyBy('day_number');
            if ($orders->count() !== $itinerary->duration_days) {
                throw ValidationException::withMessages(['days' => 'Each trip day must appear exactly once.']);
            }
            foreach ($itinerary->days as $day) {
                $requested = $orders->get($day->day_number);
                if (!$requested) {
                    throw ValidationException::withMessages(['days' => 'Each trip day must appear exactly once.']);
                }
                $stops = $day->stops()->orderBy('sequence')->get();
                $existingIds = $stops->pluck('id')->sort()->values()->all();
                $requestedIds = collect($requested['stop_ids'])->sort()->values()->all();
                if ($existingIds !== $requestedIds) {
                    throw ValidationException::withMessages(['days' => 'A route suggestion cannot add or remove selected visits.']);
                }
                $currentOrder = $stops->pluck('id')->values();
                foreach ($stops as $index => $stop) {
                    if (
                        ($day->is_locked || $stop->is_locked || $stop->arrival_time)
                        && $requested['stop_ids'][$index] !== $currentOrder[$index]
                    ) {
                        throw ValidationException::withMessages(['days' => 'Keep locked or time-constrained visits in their saved position.']);
                    }
                }
                foreach ($stops as $stop) {
                    $stop->sequence += 100;
                    $stop->save();
                }
                foreach ($requested['stop_ids'] as $index => $stopId) {
                    $stops->firstWhere('id', $stopId)->update(['sequence' => $index + 1]);
                }
            }
            $itinerary->quotes()->whereIn('status', ['current', 'accepted'])->update(['status' => 'superseded']);
            $itinerary->revision++;
            $itinerary->status = 'draft';
            $itinerary->save();

            return $itinerary;
        });

        return response()->json(['success' => true, 'data' => $this->payload($updated)]);
    }

    public function quote(Request $request, string $id, TourItineraryQuoteService $quoteService): JsonResponse
    {
        $data = $request->validate(['expected_revision' => 'required|integer|min:1']);
        $itinerary = $this->ownedItinerary($request, $id)->load('days.stops');
        if ($itinerary->revision !== (int) $data['expected_revision']) {
            abort(409, 'This itinerary changed. Reload it before requesting a quote.');
        }
        $quote = $quoteService->createQuote($itinerary, (int) $data['expected_revision']);

        return response()->json(['success' => true, 'data' => $this->publicQuote($quote)]);
    }

    public function acceptQuote(Request $request, string $id, string $quoteId, TourItineraryQuoteService $quoteService): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'accept_terms' => 'required|accepted',
        ]);
        $itinerary = $this->ownedItinerary($request, $id);
        $channel = $request->route('tour_booking_context') === 'internal' ? 'staff' : 'customer';
        $quote = $quoteService->accept($itinerary, $quoteId, (int) $data['expected_revision'], (string) $request->user()->id, $channel);

        return response()->json(['success' => true, 'data' => $this->publicQuote($quote)]);
    }

    public function convertToBooking(Request $request, string $id, TourBookingConversionService $conversion): JsonResponse
    {
        $data = $request->validate([
            'quote_id' => 'required|uuid',
            'expected_revision' => 'required|integer|min:1',
            'customer_id' => [$request->route('tour_booking_context') === 'internal' ? 'required' : 'prohibited', 'uuid', 'exists:customers,id'],
        ]);
        $itinerary = $this->ownedItinerary($request, $id);
        if ($itinerary->revision !== (int) $data['expected_revision']) {
            abort(409, 'This itinerary changed. Reload it before creating the booking.');
        }
        $customerPortal = $request->route('tour_booking_context') === 'customer';
        $publicWebsite = $request->route('tour_booking_context') === 'website';
        $activeCustomer = ($customerPortal || $publicWebsite) ? $this->customerPortalProfile($request) : null;
        $customerId = $data['customer_id'] ?? $activeCustomer?->id ?? Customer::query()->where('user_id', $request->user()->id)->value('id');
        if (!$customerId && DB::table('customer_user_aliases')->where('user_id', $request->user()->id)->exists()) {
            $customerId = DB::table('customer_user_aliases')
                ->where('user_id', $request->user()->id)
                ->value('customer_id');
        }
        if (!$customerId) {
            throw ValidationException::withMessages(['customer_id' => 'Your account is not connected to a customer profile yet.']);
        }

        if ($customerPortal) {
            $payload = ['from_date' => $itinerary->start_date->toDateString(), 'from_time' => '00:00'];
            $validator = Validator::make($payload, []);
            app(WebsiteSettingsService::class)->validatePortalBookingAdvance($validator, $payload, 'customer_portal_booking_advance_hours');
            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'message' => 'The selected pickup time is too soon.', 'errors' => $validator->errors()], 422);
            }
        }
        $booking = $conversion->convert($itinerary, $data['quote_id'], (string) $customerId, $customerPortal, $publicWebsite);

        return response()->json([
            'success' => true,
            'data' => [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'status' => $booking->status,
                'total_estimated' => (float) $booking->total_estimated,
                'currency' => $booking->currency,
            ]
        ], 201);
    }

    private function validateItinerary(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'service_type_id' => [$updating ? 'sometimes' : 'required', 'uuid', 'exists:service_types,id'],
            'service_package_id' => 'nullable|uuid',
            'service_package_template_version' => 'nullable|integer|min:1',
            'vehicle_class_id' => 'required|uuid',
            'start_date' => 'required|date|after_or_equal:today',
            'duration_days' => 'required|integer|min:1|max:60',
            'adult_count' => 'required|integer|min:0|max:100',
            'child_count' => 'required|integer|min:0|max:100',
            'luggage_count' => 'required|integer|min:0|max:100',
            'currency' => 'required|string|size:3|exists:currencies,code',
            'expected_revision' => [$updating ? 'required' : 'prohibited', 'integer', 'min:1'],
            'days' => 'required|array',
            'days.*.day_number' => 'required|integer|min:1|max:60|distinct:strict',
            'days.*.timezone' => 'required|timezone',
            'days.*.start_time' => 'sometimes|date_format:H:i',
            'days.*.start_destination_id' => 'nullable|uuid',
            'days.*.end_destination_id' => 'nullable|uuid',
            'days.*.is_locked' => 'sometimes|boolean',
            'days.*.overnight_destination_id' => 'nullable|uuid',
            'days.*.stops' => 'sometimes|array|max:40',
            'days.*.stops.*.kind' => ['required', Rule::in(['destination', 'activity'])],
            'days.*.stops.*.catalogue_id' => 'required|uuid',
            'days.*.stops.*.is_locked' => 'sometimes|boolean',
            'days.*.stops.*.arrival_time' => 'nullable|date_format:H:i',
        ]);
    }

    private function assertConfiguration(array $data, Request $request): ServiceType
    {
        $service = ($this->isCustomerPortalRequest($request) ? ServiceType::publicContext() : ServiceType::query())
            ->where('is_active', true)->findOrFail($data['service_type_id']);
        abort_unless($service->pricing_basis === 'vehicle_class' && $service->tour_booking_enabled, 404);
        $classId = $data['vehicle_class_id'] ?? null;
        $classSetting = ServiceVehicleClassSetting::query()
            ->where('service_type_id', $service->id)
            ->where('vehicle_class_id', $classId)
            ->where('is_active', true)
            ->where('is_hidden', false)
            ->firstOrFail();
        $partySize = (int) ($data['adult_count'] ?? 0) + (int) ($data['child_count'] ?? 0);
        if ($partySize < 1 || $partySize > $classSetting->max_passengers || (int) ($data['luggage_count'] ?? 0) > $classSetting->max_luggage) {
            throw ValidationException::withMessages(['vehicle_class_id' => 'The selected class does not fit this party and luggage.']);
        }

        $packageId = $data['service_package_id'] ?? null;
        if ($packageId) {
            $package = ServicePackage::query()
                ->where('service_type_id', $service->id)
                ->where('is_active', true)
                ->findOrFail($packageId);
            $defaultSetting = ServiceVehicleClassSetting::query()
                ->where('service_type_id', $service->id)
                ->where('vehicle_class_id', $package->default_vehicle_class_id)
                ->where('is_active', true)
                ->where('is_hidden', false)
                ->first();
            $allowedUpgrades = $package->allowed_upgrade_class_ids ?? [];
            if (!$defaultSetting || array_diff($allowedUpgrades, $defaultSetting->allowed_upgrade_class_ids ?? []) !== []) {
                throw ValidationException::withMessages(['service_package_id' => 'This package’s vehicle upgrades no longer match the current class settings. Ask staff to update the package.']);
            }
            $templateVersion = $data['service_package_template_version'] ?? $package->tour_itinerary_template_version;
            if ($templateVersion && !$package->tourTemplateVersions()->where('version', $templateVersion)->exists()) {
                throw ValidationException::withMessages(['service_package_template_version' => 'Choose a published version of this package itinerary.']);
            }
            if (!in_array($classId, array_merge([$package->default_vehicle_class_id], $allowedUpgrades), true)) {
                throw ValidationException::withMessages(['vehicle_class_id' => 'Choose the package default class or one of its allowed upgrades.']);
            }
        }

        return $service;
    }

    private function itineraryFields(array $data): array
    {
        return collect($data)->only([
            'service_package_id',
            'service_package_template_version',
            'vehicle_class_id',
            'start_date',
            'duration_days',
            'adult_count',
            'child_count',
            'luggage_count',
            'currency',
            'custom_answers',
            'custom_fields_snapshot',
        ])->all();
    }

    private function saveDays(TourItinerary $itinerary, array $data): void
    {
        if (!array_key_exists('days', $data)) {
            return;
        }
        if (count($data['days']) !== (int) $itinerary->duration_days) {
            throw ValidationException::withMessages(['days' => 'Add one itinerary day for each trip day.']);
        }
        $dayNumbers = collect($data['days'])->pluck('day_number')->map(fn($number) => (int) $number)->sort()->values()->all();
        if ($dayNumbers !== range(1, (int) $itinerary->duration_days)) {
            throw ValidationException::withMessages(['days' => 'Each trip day must appear exactly once.']);
        }
        $daysByNumber = collect($data['days'])->keyBy(fn(array $day) => (int) $day['day_number']);
        for ($number = 1; $number < (int) $itinerary->duration_days; $number++) {
            $endId = $daysByNumber[$number]['end_destination_id'] ?? null;
            $nextStartId = $daysByNumber[$number + 1]['start_destination_id'] ?? null;
            if ($endId && $nextStartId && $endId !== $nextStartId) {
                throw ValidationException::withMessages(['days' => 'Each day must continue from the previous day’s overnight destination.']);
            }
        }

        foreach ($data['days'] as $dayData) {
            $start = $this->mappedDestination($dayData['start_destination_id'] ?? null, $itinerary->service_type_id);
            $end = $this->mappedDestination($dayData['end_destination_id'] ?? null, $itinerary->service_type_id);
            $overnight = null;
            if (!empty($dayData['overnight_destination_id'])) {
                $overnight = TourDestination::query()
                    ->where('service_type_id', $itinerary->service_type_id)
                    ->where('is_published', true)
                    ->with('location')
                    ->findOrFail($dayData['overnight_destination_id']);
            }
            if ($overnight && !empty($dayData['end_destination_id']) && $overnight->id !== $dayData['end_destination_id']) {
                throw ValidationException::withMessages(['days' => 'The overnight destination must be the end point for that day.']);
            }
            $day = $itinerary->days()->create([
                'day_number' => $dayData['day_number'],
                'service_date' => Carbon::parse($itinerary->start_date)->addDays($dayData['day_number'] - 1)->toDateString(),
                'timezone' => $dayData['timezone'],
                'start_time' => $dayData['start_time'] ?? '09:00',
                'start_destination_id' => $start?->id,
                'end_destination_id' => $end?->id,
                'start_location_snapshot' => $start ? $this->locationSnapshot($start) : null,
                'end_location_snapshot' => $end ? $this->locationSnapshot($end) : null,
                'overnight_destination_id' => $overnight?->id,
                'is_locked' => $dayData['is_locked'] ?? false,
            ]);
            $destinationIds = collect($dayData['stops'] ?? [])->where('kind', 'destination')->pluck('catalogue_id')->all();

            foreach (($dayData['stops'] ?? []) as $index => $stopData) {
                if ($stopData['kind'] === 'destination') {
                    $destination = TourDestination::query()
                        ->where('service_type_id', $itinerary->service_type_id)
                        ->where('is_published', true)
                        ->with('location')
                        ->findOrFail($stopData['catalogue_id']);
                    $catalogue = $destination;
                    $activity = null;
                    $location = $destination->location;
                    $visitMinutes = 0;
                } else {
                    $activity = TourActivity::query()
                        ->where('is_published', true)
                        ->whereIn('tour_destination_id', $destinationIds)
                        ->whereHas('destination', fn($query) => $query
                            ->where('service_type_id', $itinerary->service_type_id)
                            ->where('is_published', true))
                        ->with(['destination', 'location'])
                        ->findOrFail($stopData['catalogue_id']);
                    $catalogue = $activity->destination;
                    $location = $activity->location;
                    $visitMinutes = $activity->visit_duration_minutes;
                }
                if (!$location || $location->latitude === null || $location->longitude === null) {
                    throw ValidationException::withMessages(['days' => 'A selected Tours stop no longer has a mapped location.']);
                }
                $day->stops()->create([
                    'sequence' => $index + 1,
                    'kind' => $stopData['kind'],
                    'tour_destination_id' => $stopData['kind'] === 'destination' ? $catalogue->id : null,
                    'tour_activity_id' => $activity?->id,
                    'location_snapshot' => [
                        'name' => $location->name,
                        'address' => $location->address,
                        'latitude' => (float) $location->latitude,
                        'longitude' => (float) $location->longitude,
                    ],
                    'visit_duration_minutes' => $visitMinutes,
                    'is_locked' => $stopData['is_locked'] ?? false,
                    'arrival_time' => $stopData['arrival_time'] ?? null,
                ]);
            }
        }
    }

    private function mappedDestination(?string $id, string $serviceTypeId): ?TourDestination
    {
        if (!$id) {
            return null;
        }
        $destination = TourDestination::query()
            ->where('service_type_id', $serviceTypeId)
            ->where('is_published', true)
            ->with('location')
            ->findOrFail($id);
        if (!$destination->location || $destination->location->latitude === null || $destination->location->longitude === null) {
            throw ValidationException::withMessages(['days' => 'A day endpoint no longer has a mapped location.']);
        }

        return $destination;
    }

    private function locationSnapshot(TourDestination $destination): array
    {
        return [
            'destination_id' => $destination->id,
            'name' => $destination->location->name,
            'address' => $destination->location->address,
            'latitude' => (float) $destination->location->latitude,
            'longitude' => (float) $destination->location->longitude,
        ];
    }

    private function payload(TourItinerary $itinerary): array
    {
        $itinerary = $itinerary->fresh()->load(['days.stops', 'package', 'vehicleClass']);
        $quote = $itinerary->quotes()
            ->where(function ($query): void {
                $query->where('status', 'accepted')
                    ->orWhere(fn($current) => $current->where('status', 'current')->where('expires_at', '>', now()));
            })
            ->latest()
            ->first();

        return array_merge($itinerary->toArray(), ['latest_quote' => $quote ? $this->publicQuote($quote) : null]);
    }

    private function ownedItinerary(Request $request, string $id): TourItinerary
    {
        $this->customerPortalProfile($request);
        return TourItinerary::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function isCustomerPortalRequest(Request $request): bool
    {
        return $request->route('tour_booking_context') === 'customer'
            || $request->route('tour_pricing_context') === 'public';
    }

    private function customerPortalProfile(Request $request): ?Customer
    {
        if (!$this->isCustomerPortalRequest($request))
            return null;
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'An active customer profile is required.');
        $active = app(UserContextService::class)->resolveActiveContextFromRequest($user, $request);
        abort_unless($active && ($active['portal_profile'] ?? null) === 'customer', 403, 'An active customer profile is required.');
        abort_if($request->header('X-Active-Context-Id') && $request->header('X-Active-Context-Id') !== (string) $active['id'], 403, 'The selected customer context is unavailable.');
        abort_if($request->header('X-Active-Context-Type') && $request->header('X-Active-Context-Type') !== 'customer', 403, 'A customer context is required.');
        abort_if($request->header('X-Active-Portal-Profile') && $request->header('X-Active-Portal-Profile') !== 'customer', 403, 'A customer profile is required.');
        $context = $user->contexts()->whereKey($active['id'])->where('context_type', 'customer')->where('is_active', true)->first();
        $customer = $context?->context_id ? Customer::query()->find($context->context_id) : null;
        $owns = $customer && ((string) $customer->user_id === (string) $user->id
            || DB::table('customer_user_aliases')->where('user_id', $user->id)->where('customer_id', $customer->id)->exists());
        abort_unless($owns, 403, 'An active customer profile is required.');

        return $customer;
    }

    private function publicQuote(TourQuote $quote): array
    {
        return [
            'id' => $quote->id,
            'status' => $quote->status,
            'itinerary_revision' => $quote->itinerary_revision,
            'expires_at' => $quote->expires_at?->toIso8601String(),
            'currency' => $quote->currency,
            'total_amount' => (float) $quote->total_amount,
            'distance_km' => (float) $quote->distance_km,
            'route' => $quote->route_snapshot,
            'breakdown' => $quote->pricing_snapshot['breakdown'] ?? [],
            'terms_snapshot' => $quote->terms_snapshot ?? [],
            'accepted_at' => $quote->accepted_at?->toIso8601String(),
        ];
    }
}
