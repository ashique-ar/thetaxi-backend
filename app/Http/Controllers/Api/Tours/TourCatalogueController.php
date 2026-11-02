<?php

namespace App\Http\Controllers\Api\Tours;

use App\Http\Controllers\Controller;
use App\Models\PredefinedLocation;
use App\Models\Customer;
use App\Models\Service\ServicePackage;
use App\Models\Service\ServiceType;
use App\Models\Service\ServiceVehicleClassSetting;
use App\Models\Tours\TourActivity;
use App\Models\Tours\TourDestination;
use App\Models\User;
use App\Services\AIContentService;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\Tours\TourRoutePlanner;
use App\Services\Tours\TourFormConfiguration;
use App\Services\UserContextService;
use App\Services\WebsiteSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class TourCatalogueController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:service-types.view')->only('manageIndex');
        $this->middleware('permission:service-types.edit')->only([
            'storeLocation',
            'updateLocation',
            'storeDestination',
            'updateDestination',
            'storeActivity',
            'updateActivity',
        ]);
    }

    public function publishedServices(): JsonResponse
    {
        $aiItineraryEnabled = filter_var(app(WebsiteSettingsService::class)->get('tour_ai_itinerary_enabled', false), FILTER_VALIDATE_BOOL);
        $services = ServiceType::publicContext()
            ->where('is_active', true)
            ->where('pricing_basis', 'vehicle_class')
            ->where('tour_booking_enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'form_config'])
            ->map(function (ServiceType $service) use ($aiItineraryEnabled): array {
                $classes = ServiceVehicleClassSetting::query()
                    ->with('vehicleClass:id,name,description')
                    ->where('service_type_id', $service->id)
                    ->where('is_active', true)
                    ->where('is_hidden', false)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn(ServiceVehicleClassSetting $setting): array => [
                        'id' => $setting->vehicle_class_id,
                        'name' => $setting->vehicleClass?->name,
                        'description' => $setting->description ?: $setting->vehicleClass?->description,
                        'max_passengers' => $setting->max_passengers,
                        'max_luggage' => $setting->max_luggage,
                        'is_inquiry_only' => $setting->is_inquiry_only,
                    ])->values();
                $packages = ServicePackage::query()
                    ->where('service_type_id', $service->id)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'name', 'description', 'default_vehicle_class_id', 'allowed_upgrade_class_ids', 'tour_itinerary_template', 'tour_itinerary_template_version']);

                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'form_fields' => TourFormConfiguration::fields($service),
                    'ai_itinerary_helper_enabled' => $aiItineraryEnabled,
                    'classes' => $classes,
                    'packages' => $packages,
                ];
            });

        return response()->json(['success' => true, 'data' => ['services' => $services]]);
    }

    public function suggestItinerary(Request $request, AIContentService $ai): JsonResponse
    {
        $this->assertCustomerPortalUser($request);

        return $this->makeItinerarySuggestion($request, $ai);
    }

    public function suggestPublicItinerary(Request $request, AIContentService $ai): JsonResponse
    {
        return $this->makeItinerarySuggestion($request, $ai);
    }

    private function makeItinerarySuggestion(Request $request, AIContentService $ai): JsonResponse
    {
        $data = $request->validate([
            'service_type_id' => 'required|uuid',
            'duration_days' => 'required|integer|min:1|max:14',
            'start_date' => 'nullable|date|after_or_equal:today',
            'interests' => 'nullable|string|max:500',
        ]);
        abort_unless(filter_var(app(WebsiteSettingsService::class)->get('tour_ai_itinerary_enabled', false), FILTER_VALIDATE_BOOL), 404);
        $service = ServiceType::publicContext()->where('is_active', true)
            ->where('pricing_basis', 'vehicle_class')->where('tour_booking_enabled', true)
            ->findOrFail($data['service_type_id']);
        $destinations = TourDestination::query()->with([
            'location:id,name,city,address',
            'activities' => fn($query) => $query->where('is_published', true)->orderBy('sort_order')->orderBy('name'),
        ])->where('service_type_id', $service->id)->where('is_published', true)
            ->orderBy('sort_order')->orderBy('name')->get();
        if ($destinations->isEmpty()) {
            throw ValidationException::withMessages(['service_type_id' => 'No published tour places are available for suggestions.']);
        }

        $catalogue = $destinations->map(fn(TourDestination $destination): array => [
            'id' => (string) $destination->id,
            'name' => $destination->name,
            'description' => $destination->description,
            'city' => $destination->location?->city,
            'activities' => $destination->activities->map(fn(TourActivity $activity): array => [
                'id' => (string) $activity->id,
                'name' => $activity->name,
                'description' => $activity->description,
                'visit_duration_minutes' => (int) $activity->visit_duration_minutes,
            ])->values()->all(),
        ])->values()->all();

        try {
            $suggestion = $ai->suggestTourItinerary([
                'duration_days' => (int) $data['duration_days'],
                'start_date' => $data['start_date'] ?? null,
                'interests' => trim((string) ($data['interests'] ?? '')),
            ], $catalogue);
            $days = $this->validatedAiSuggestionDays($suggestion['days'], (int) $data['duration_days'], $destinations);
        } catch (\Throwable $exception) {
            Log::warning('Tour itinerary suggestion unavailable.', ['service_type_id' => $service->id, 'error' => $exception->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Suggestions are temporarily unavailable. You can continue planning manually.',
            ], 503);
        }

        return response()->json(['success' => true, 'data' => [
            'duration_days' => (int) $data['duration_days'],
            'days' => $days,
            'notice' => 'AI suggestions are an editable draft. Review locations and route; they do not confirm availability, price, tickets or reservations.',
        ]]);
    }

    private function validatedAiSuggestionDays(array $rawDays, int $duration, Collection $destinations): array
    {
        $destinationIds = $destinations->pluck('id')->map(fn($id): string => (string) $id)->all();
        $activityParents = $destinations->flatMap(fn(TourDestination $destination) => $destination->activities->mapWithKeys(
            fn(TourActivity $activity): array => [(string) $activity->id => (string) $destination->id]
        ));
        if (count($rawDays) !== $duration) {
            throw new \RuntimeException('The suggestion did not include the requested trip days.');
        }

        $days = [];
        $destinationDays = [];
        foreach ($rawDays as $day) {
            if (!is_array($day) || !is_numeric($day['day_number'] ?? null) || !is_array($day['stops'] ?? null)) {
                throw new \RuntimeException('The suggestion contains an invalid day.');
            }
            $number = (int) $day['day_number'];
            if ($number < 1 || $number > $duration || isset($days[$number]) || count($day['stops']) > 12) {
                throw new \RuntimeException('The suggestion contains an invalid day order.');
            }
            $stops = [];
            $seen = [];
            foreach ($day['stops'] as $stop) {
                if (!is_array($stop)) {
                    throw new \RuntimeException('The suggestion contains an invalid stop.');
                }
                $kind = $stop['kind'] ?? null;
                $id = (string) ($stop['catalogue_id'] ?? '');
                if (!in_array($kind, ['destination', 'activity'], true)
                    || ($kind === 'destination' && !in_array($id, $destinationIds, true))
                    || ($kind === 'activity' && !$activityParents->has($id))) {
                    throw new \RuntimeException('The suggestion included a place outside the published catalogue.');
                }
                if ($kind === 'activity') {
                    $parentId = $activityParents->get($id);
                    if (isset($destinationDays[$parentId]) && $destinationDays[$parentId] !== $number) {
                        throw new \RuntimeException('The suggestion repeats a place across days that the planner cannot represent yet.');
                    }
                    $destinationDays[$parentId] = $number;
                    if (!isset($seen['destination:'.$parentId])) {
                        $stops[] = ['kind' => 'destination', 'catalogue_id' => $parentId];
                        $seen['destination:'.$parentId] = true;
                    }
                } elseif (isset($destinationDays[$id]) && $destinationDays[$id] !== $number) {
                    throw new \RuntimeException('The suggestion repeats a place across days that the planner cannot represent yet.');
                } else {
                    $destinationDays[$id] = $number;
                }
                $key = $kind.':'.$id;
                if (!isset($seen[$key])) {
                    $stops[] = ['kind' => $kind, 'catalogue_id' => $id];
                    $seen[$key] = true;
                }
            }
            $days[$number] = ['day_number' => $number, 'stops' => $stops];
        }
        ksort($days);
        if (array_keys($days) !== range(1, $duration)) {
            throw new \RuntimeException('The suggestion did not include each trip day exactly once.');
        }

        return array_values($days);
    }

    private function assertCustomerPortalUser(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'An active customer profile is required.');
        $active = app(UserContextService::class)->resolveActiveContextFromRequest($user, $request);
        abort_unless($active && ($active['portal_profile'] ?? null) === 'customer', 403, 'An active customer profile is required.');
        abort_if($request->header('X-Active-Context-Id') && $request->header('X-Active-Context-Id') !== (string) $active['id'], 403);
        abort_if($request->header('X-Active-Context-Type') && $request->header('X-Active-Context-Type') !== 'customer', 403);
        abort_if($request->header('X-Active-Portal-Profile') && $request->header('X-Active-Portal-Profile') !== 'customer', 403);
        $context = $user->contexts()->whereKey($active['id'])->where('context_type', 'customer')->where('is_active', true)->first();
        $customer = $context?->context_id ? Customer::query()->find($context->context_id) : null;
        $owns = $customer && ((string) $customer->user_id === (string) $user->id
            || DB::table('customer_user_aliases')->where('user_id', $user->id)->where('customer_id', $customer->id)->exists());
        abort_unless($owns, 403, 'An active customer profile is required.');
    }

    public function published(Request $request): JsonResponse
    {
        $data = $request->validate(['service_type_id' => 'required|uuid|exists:service_types,id']);
        $service = ServiceType::publicContext()->where('is_active', true)->findOrFail($data['service_type_id']);
        abort_unless($service->pricing_basis === 'vehicle_class' && $service->tour_booking_enabled, 404);

        $destinations = TourDestination::query()
            ->with([
                'location:id,name,address,city,country,latitude,longitude',
                'activities' => fn($query) => $query
                    ->where('is_published', true)
                    ->with('location:id,name,address,city,country,latitude,longitude')
            ])
            ->where('service_type_id', $service->id)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn(TourDestination $destination) => [
                'id' => $destination->id,
                'code' => $destination->code,
                'name' => $destination->name,
                'description' => $destination->description,
                'timezone' => $destination->timezone,
                'location' => $this->locationPayload($destination->location),
                'activities' => $destination->activities->map(fn(TourActivity $activity) => [
                    'id' => $activity->id,
                    'tour_destination_id' => $activity->tour_destination_id,
                    'code' => $activity->code,
                    'name' => $activity->name,
                    'description' => $activity->description,
                    'visit_duration_minutes' => $activity->visit_duration_minutes,
                    'opens_at' => $activity->opens_at,
                    'closes_at' => $activity->closes_at,
                    'location' => $this->locationPayload($activity->location),
                ])->values(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'service_type_id' => $service->id,
                'form_fields' => TourFormConfiguration::fields($service),
                'destinations' => $destinations,
            ],
            'activity_visit_note' => 'Activity visits are included in your route only. Tickets, entry fees and activity reservations are not included. Please arrange these separately. Activity booking will be introduced in a future release.',
        ]);
    }

    public function suggestRoute(Request $request, TourRoutePlanner $planner, BookingFlowService $bookingFlow, CurrencyService $currencyService): JsonResponse
    {
        $data = $request->validate([
            'service_type_id' => 'required|uuid|exists:service_types,id',
            'vehicle_class_id' => 'required|uuid',
            'package_id' => 'nullable|uuid',
            'from_date' => 'required|date',
            'start_time' => 'sometimes|date_format:H:i',
            'duration_days' => 'required|integer|min:1|max:60',
            'adult_count' => 'required|integer|min:0|max:100',
            'child_count' => 'required|integer|min:0|max:100',
            'luggage_count' => 'required|integer|min:0|max:100',
            'currency' => 'nullable|string|size:3|exists:currencies,code',
            'stops' => 'required|array|min:2|max:40',
            'stops.*.visit_id' => 'required|string|max:100|distinct',
            'stops.*.kind' => ['required', Rule::in(['destination', 'activity'])],
            'stops.*.catalogue_id' => 'required|uuid',
            'stops.*.locked' => 'sometimes|boolean',
            'stops.*.arrival_time' => 'sometimes|date_format:H:i',
        ]);
        $service = ServiceType::publicContext()->where('is_active', true)->findOrFail($data['service_type_id']);
        abort_unless($service->pricing_basis === 'vehicle_class' && $service->tour_booking_enabled, 404);
        $classSetting = ServiceVehicleClassSetting::query()
            ->where('service_type_id', $service->id)
            ->where('vehicle_class_id', $data['vehicle_class_id'])
            ->where('is_active', true)
            ->where('is_hidden', false)
            ->firstOrFail();
        $partySize = $data['adult_count'] + $data['child_count'];
        if ($partySize < 1 || $partySize > $classSetting->max_passengers || $data['luggage_count'] > $classSetting->max_luggage) {
            throw ValidationException::withMessages(['vehicle_class_id' => 'The selected class does not fit this party and luggage.']);
        }
        if (!empty($data['package_id'])) {
            $package = ServicePackage::query()
                ->where('service_type_id', $service->id)
                ->where('is_active', true)
                ->findOrFail($data['package_id']);
            $packageClasses = array_merge(
                [$package->default_vehicle_class_id],
                $package->allowed_upgrade_class_ids ?? []
            );
            if (!in_array($data['vehicle_class_id'], $packageClasses, true)) {
                throw ValidationException::withMessages(['vehicle_class_id' => 'Choose the package default class or one of its allowed upgrades.']);
            }
        }

        $destinationIds = collect($data['stops'])
            ->where('kind', 'destination')
            ->pluck('catalogue_id')
            ->all();
        $visits = [];
        foreach ($data['stops'] as $stop) {
            if ($stop['kind'] === 'destination') {
                $destination = TourDestination::query()
                    ->where('service_type_id', $service->id)
                    ->where('is_published', true)
                    ->with('location:id,latitude,longitude')
                    ->findOrFail($stop['catalogue_id']);
                $location = $destination->location;
                $activity = null;
                $visitMinutes = 0;
                $name = $destination->name;
            } else {
                $activity = TourActivity::query()
                    ->where('is_published', true)
                    ->whereIn('tour_destination_id', $destinationIds)
                    ->whereHas('destination', fn($query) => $query
                        ->where('service_type_id', $service->id)
                        ->where('is_published', true))
                    ->with(['location:id,latitude,longitude', 'destination:id,name'])
                    ->findOrFail($stop['catalogue_id']);
                $location = $activity->location;
                $visitMinutes = $activity->visit_duration_minutes;
                $name = $activity->name;
            }

            $visits[] = [
                'id' => $stop['visit_id'],
                'catalogue_id' => $stop['catalogue_id'],
                'kind' => $stop['kind'],
                'name' => $name,
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'visit_minutes' => $visitMinutes,
                'locked' => $stop['locked'] ?? false,
                ...($activity?->opens_at ? ['opens_at' => substr((string) $activity->opens_at, 0, 5)] : []),
                ...($activity?->closes_at ? ['closes_at' => substr((string) $activity->closes_at, 0, 5)] : []),
                ...(isset($stop['arrival_time']) ? ['arrival_time' => $stop['arrival_time']] : []),
            ];
        }

        $suggestion = $planner->suggest($visits, $data['start_time'] ?? '09:00');
        $baseCurrency = $currencyService->getBookingBaseCurrency();
        $targetCurrency = strtoupper($data['currency'] ?? $baseCurrency);
        $quote = function (?float $distance) use ($bookingFlow, $currencyService, $data, $service, $baseCurrency, $targetCurrency): ?array {
            if ($distance === null) {
                return null;
            }
            $pricing = $bookingFlow->calculateDynamicPricing([
                'service_type_id' => $service->id,
                'vehicle_class_id' => $data['vehicle_class_id'],
                'package_id' => $data['package_id'] ?? null,
                'from_date' => $data['from_date'],
                'to_date' => Carbon::parse($data['from_date'])->addDays($data['duration_days'] - 1)->toDateString(),
                'from_time' => '00:00',
                'to_time' => '23:59',
                'duration_days' => $data['duration_days'],
                'duration_minutes' => $data['duration_days'] * 1440,
                'number_of_days' => $data['duration_days'],
                'journey_distance' => $distance,
                'total_distance' => $distance,
                'pricing_context' => 'public',
                'mode' => 'full_calculation',
            ]);
            $pricing = $currencyService->convertPricingStructure($pricing, $baseCurrency, $targetCurrency);
            if (
                !isset($pricing['calculation_metadata']['definition_used'])
                || !is_numeric($pricing['total_amount'] ?? null)
                || !is_finite((float) $pricing['total_amount'])
                || (float) $pricing['total_amount'] < 0
            ) {
                return ['status' => 'quotation_required', 'reason' => 'Transport pricing needs staff review because a complete class rate configuration was not available.'];
            }

            return [
                'status' => 'priced',
                'pricing_basis' => 'vehicle_class',
                'currency' => $targetCurrency,
                'total_amount' => (float) $pricing['total_amount'],
                'breakdown' => $pricing['breakdown'] ?? [],
            ];
        };
        $suggestion['original']['quote'] = $quote($suggestion['original']['distance_km']);
        $suggestion['suggested']['quote'] = $suggestion['original']['distance_km'] === $suggestion['suggested']['distance_km']
            ? $suggestion['original']['quote']
            : $quote($suggestion['suggested']['distance_km']);
        if (
            ($suggestion['original']['quote']['status'] ?? null) === 'priced'
            && ($suggestion['suggested']['quote']['status'] ?? null) === 'priced'
        ) {
            $suggestion['price_difference'] = round(
                $suggestion['suggested']['quote']['total_amount'] - $suggestion['original']['quote']['total_amount'],
                2
            );
        } else {
            $suggestion['price_difference'] = null;
        }

        return response()->json(['success' => true, 'data' => $suggestion]);
    }

    public function manageIndex(Request $request): JsonResponse
    {
        $data = $request->validate(['service_type_id' => 'required|uuid|exists:service_types,id']);
        $service = ServiceType::findOrFail($data['service_type_id']);
        $this->assertClassPriced($service);

        return response()->json([
            'success' => true,
            'data' => [
                'form_fields' => TourFormConfiguration::fields($service),
                'locations' => PredefinedLocation::query()->where('type', 'tour')->orderBy('sort_order')->orderBy('name')->get(),
                'destinations' => TourDestination::withInactive()
                    ->with(['location', 'activities' => fn($query) => $query->withInactive()->with('location')])
                    ->where('service_type_id', $service->id)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(),
            ],
        ]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $location = PredefinedLocation::create($this->validateLocation($request));

        return response()->json(['success' => true, 'data' => $location], 201);
    }

    public function updateLocation(Request $request, PredefinedLocation $location): JsonResponse
    {
        abort_unless($location->type === 'tour' && $location->google_place_id, 404);
        $location->update($this->validateLocation($request, true));

        return response()->json(['success' => true, 'data' => $location->fresh()]);
    }

    public function storeDestination(Request $request): JsonResponse
    {
        $data = $this->validateDestination($request);
        $service = ServiceType::findOrFail($data['service_type_id']);
        $this->assertClassPriced($service);
        $this->assertMappedTourLocation($data['predefined_location_id']);
        $destination = TourDestination::create($data);

        return response()->json(['success' => true, 'data' => $destination->load('location')], 201);
    }

    public function updateDestination(Request $request, TourDestination $destination): JsonResponse
    {
        $data = $this->validateDestination($request, true);
        $service = ServiceType::findOrFail($data['service_type_id'] ?? $destination->service_type_id);
        $this->assertClassPriced($service);
        $locationId = $data['predefined_location_id'] ?? $destination->predefined_location_id;
        $this->assertMappedTourLocation($locationId);
        $destination->update($data);

        return response()->json(['success' => true, 'data' => $destination->fresh()->load('location')]);
    }

    public function storeActivity(Request $request): JsonResponse
    {
        $data = $this->validateActivity($request);
        $destination = TourDestination::findOrFail($data['tour_destination_id']);
        $this->assertClassPriced($destination->serviceType);
        $this->assertMappedTourLocation($data['predefined_location_id']);
        $activity = $destination->activities()->create($data);

        return response()->json(['success' => true, 'data' => $activity->load('location')], 201);
    }

    public function updateActivity(Request $request, TourActivity $activity): JsonResponse
    {
        $data = $this->validateActivity($request, true);
        $destination = TourDestination::findOrFail($data['tour_destination_id'] ?? $activity->tour_destination_id);
        $this->assertClassPriced($destination->serviceType);
        $this->assertMappedTourLocation($data['predefined_location_id'] ?? $activity->predefined_location_id);
        $activity->update($data);

        return response()->json(['success' => true, 'data' => $activity->fresh()->load('location', 'destination')]);
    }

    private function validateLocation(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'code' => [$required, 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('predefined_locations', 'code')->ignore($request->route('location'))],
            'google_place_id' => [$required, 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'latitude' => [$required, 'numeric', 'between:-90,90'],
            'longitude' => [$required, 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $data['type'] = 'tour';
        $data['is_active'] = true;

        return $data;
    }

    private function validateDestination(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $destination = $request->route('destination');
        $serviceTypeId = $request->input('service_type_id') ?? $destination?->service_type_id;

        return $request->validate([
            'service_type_id' => [$required, 'uuid', 'exists:service_types,id'],
            'predefined_location_id' => [$required, 'uuid', 'exists:predefined_locations,id'],
            'code' => [$required, 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('tour_destinations', 'code')->where('service_type_id', $serviceTypeId)->ignore($destination)],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'timezone' => [$required, 'string', 'timezone'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateActivity(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $activity = $request->route('activity');
        $destinationId = $request->input('tour_destination_id') ?? $activity?->tour_destination_id;

        $data = $request->validate([
            'tour_destination_id' => [$required, 'uuid', 'exists:tour_destinations,id'],
            'predefined_location_id' => [$required, 'uuid', 'exists:predefined_locations,id'],
            'code' => [$required, 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('tour_activities', 'code')->where('tour_destination_id', $destinationId)->ignore($activity)],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visit_duration_minutes' => [$required, 'integer', 'min:1', 'max:1440'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
        if (!empty($data['opens_at']) && !empty($data['closes_at']) && $data['closes_at'] <= $data['opens_at']) {
            throw ValidationException::withMessages(['closes_at' => 'Closing time must be later than opening time.']);
        }

        return $data;
    }

    private function assertClassPriced(?ServiceType $service): void
    {
        if (!$service || $service->pricing_basis !== 'vehicle_class') {
            throw ValidationException::withMessages(['service_type_id' => 'Tours catalogue entries require a class-priced service.']);
        }
    }

    private function assertMappedTourLocation(string $locationId): void
    {
        $location = PredefinedLocation::query()->findOrFail($locationId);
        if ($location->type !== 'tour' || !$location->google_place_id || $location->latitude === null || $location->longitude === null) {
            throw ValidationException::withMessages(['predefined_location_id' => 'Choose a Tours location mapped and verified with Google Places.']);
        }
    }

    private function locationPayload(?PredefinedLocation $location): ?array
    {
        if (!$location) {
            return null;
        }

        return [
            'id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'city' => $location->city,
            'country' => $location->country,
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
        ];
    }
}
