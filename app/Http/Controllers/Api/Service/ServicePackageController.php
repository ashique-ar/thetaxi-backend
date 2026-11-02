<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Service\ServicePackage;
use App\Models\Service\ServicePackageReturnRule;
use App\Models\Service\ServiceType;
use App\Models\Tours\TourActivity;
use App\Models\Tours\TourDestination;
use App\Models\Tours\TourPackageTemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Services\DynamicServiceConfigurationService;
use App\Services\Pricing\PricingContextPolicyService;

class ServicePackageController extends Controller
{
    public function __construct(private readonly PricingContextPolicyService $pricingContextPolicy)
    {
        $this->middleware('auth:api')->except(['getPackagesByService']);
        $this->middleware('permission:service-packages.view')->only(['index', 'show']);
        $this->middleware('permission:service-packages.create')->only(['store']);
        $this->middleware('permission:service-packages.edit')->only(['update']);
        $this->middleware('permission:service-packages.delete')->only(['destroy']);
    }

    /**
     * Get all service packages for a specific service type
     */
    public function getPackagesByService(Request $request, string $serviceCode): JsonResponse
    {
        try {
            $serviceType = ServiceType::publicContext()
                ->where('code', $serviceCode)
                ->where('is_active', true)
                ->first();

            if (!$serviceType) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Service type not found',
                ], 404);
            }

            $packages = ServicePackage::where('service_type_id', $serviceType->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($package) {
                    return [
                        'id' => $package->id,
                        'name' => $package->name,
                        'code' => $package->code,
                        'description' => $package->description,
                        'max_km_per_day' => $package->max_km_per_day,
                        'max_km_per_package' => $package->max_km_per_package,
                        'price_multiplier' => $package->price_multiplier,
                        'rate_type' => $package->rate_type,
                        'default_duration_hours' => $package->default_duration_hours,
                        'default_duration_minutes' => $package->default_duration_minutes,
                        'charges_extra_hours' => (bool) $package->charges_extra_hours,
                        'charges_extra_km' => (bool) $package->charges_extra_km,
                        'sort_order' => $package->sort_order,
                    ];
                });

            $dynamicService = new DynamicServiceConfigurationService();
            $formConfig = $dynamicService->getServiceFormConfiguration($serviceType->code);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'service_type' => [
                        'id' => $serviceType->id,
                        'code' => $serviceType->code,
                        'name' => $serviceType->name,
                        'pricing_mode' => $serviceType->pricing_mode,
                        'uses_dropoff_time' => (bool) $serviceType->uses_dropoff_time,
                        'allow_return_trip' => (bool) $serviceType->allow_return_trip,
                        'frontend_category' => $serviceType->frontend_category,
                    ],
                    'packages' => $packages,
                    'configuration' => [
                        'form' => $formConfig,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve service packages',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all service packages (for admin interface)
     */
    public function index(Request $request): JsonResponse
    {
        $query = ServicePackage::with(['serviceType'])
            ->withCount(['returnRules' => function ($q) {
                $q->where('is_active', true);
            }])
            ->orderBy('service_type_id')
            ->orderBy('sort_order');

        if ($this->pricingContextPolicy->internalUsesWebsitePricing()) {
            $query->whereHas(
                'serviceType',
                fn ($serviceQuery) => $serviceQuery->where('context', '!=', 'portal')
            );
        }

        if ($request->filled('context') && $request->context !== 'all') {
            $query->whereHas('serviceType', fn ($serviceQuery) => $serviceQuery->where('context', $request->context));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('service_type_id')) {
            $query->where('service_type_id', $request->service_type_id);
        }

        $packages = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'status' => 'success',
            'data' => $packages,
        ]);
    }

    /**
     * Store a new service package
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type_id' => 'required|exists:service_types,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:service_packages,code',
            'description' => 'nullable|string',
            'max_km_per_day' => 'nullable|numeric|min:0',
            'max_km_per_package' => 'nullable|numeric|min:0',
            'price_multiplier' => 'required|numeric|min:0',
            'base_rate_package_id' => ['nullable', Rule::exists('service_packages', 'id')->where('service_type_id', $request->input('service_type_id'))->where('is_active', true)->whereNull('deleted_at')],
            'rate_type' => 'required|string|in:flat,hourly,daily',
            'default_duration_hours' => 'required|integer|min:0',
            'default_duration_minutes' => 'required|integer|min:0|max:59',
            'charges_extra_hours' => 'boolean',
            'charges_extra_km' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'supports_return_trip' => 'boolean',
            'default_vehicle_class_id' => 'nullable|uuid|exists:vehicle_classes,id',
            'allowed_upgrade_class_ids' => 'nullable|array',
            'allowed_upgrade_class_ids.*' => 'uuid|distinct|exists:vehicle_classes,id',
            'tour_itinerary_template' => 'nullable|array:days',
            'tour_itinerary_template.days' => 'required_with:tour_itinerary_template|array|min:1|max:60',
            'tour_itinerary_template.days.*' => 'array:day_number,timezone,start_time,start_destination_id,end_destination_id,overnight_destination_id,is_locked,stops',
            'tour_itinerary_template.days.*.day_number' => 'required|integer|min:1|max:60|distinct:strict',
            'tour_itinerary_template.days.*.timezone' => 'required|timezone',
            'tour_itinerary_template.days.*.start_time' => 'sometimes|date_format:H:i',
            'tour_itinerary_template.days.*.start_destination_id' => 'required|uuid',
            'tour_itinerary_template.days.*.end_destination_id' => 'required|uuid',
            'tour_itinerary_template.days.*.overnight_destination_id' => 'nullable|uuid',
            'tour_itinerary_template.days.*.is_locked' => 'sometimes|boolean',
            'tour_itinerary_template.days.*.stops' => 'sometimes|array|max:40',
            'tour_itinerary_template.days.*.stops.*' => 'array:kind,catalogue_id,is_locked,arrival_time',
            'tour_itinerary_template.days.*.stops.*.kind' => 'required|in:destination,activity',
            'tour_itinerary_template.days.*.stops.*.catalogue_id' => 'required|uuid',
            'tour_itinerary_template.days.*.stops.*.is_locked' => 'sometimes|boolean',
            'tour_itinerary_template.days.*.stops.*.arrival_time' => 'nullable|date_format:H:i',
        ]);
        $this->assertPositiveDefaultDuration($validated);
        $serviceType = ServiceType::findOrFail($validated['service_type_id']);
        $this->pricingContextPolicy->assertServiceTypeIsWritable($serviceType);
        $this->assertPackageVehicleClasses($serviceType, $validated);
        $this->assertTourItineraryTemplate($serviceType, $validated['tour_itinerary_template'] ?? null);

        $validated['created_user_id'] = auth()->id();
        $validated['is_active'] = true;
        $validated['supports_return_trip'] = $validated['supports_return_trip'] ?? false;

        $package = DB::transaction(function () use ($validated): ServicePackage {
            $package = ServicePackage::create($validated);
            if (!empty($validated['tour_itinerary_template'])) {
                $this->publishTourTemplateVersion($package, $validated['tour_itinerary_template']);
            }
            return $package;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Service package created successfully',
            'data' => ['package' => $package->load('serviceType')],
        ], 201);
    }

    public function show(ServicePackage $servicePackage): JsonResponse
    {
        $servicePackage = $this->pricingContextPolicy
            ->effectiveServicePackage($servicePackage)
            ->load(['serviceType', 'returnRules']);

        return response()->json([
            'status' => 'success',
            'data' => ['package' => $servicePackage],
        ]);
    }

    /**
     * Update an existing service package
     */
    public function update(Request $request, ServicePackage $servicePackage): JsonResponse
    {
        $this->pricingContextPolicy->assertServicePackageIsWritable($servicePackage);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:service_packages,code,' . $servicePackage->id,
            'description' => 'nullable|string',
            'max_km_per_day' => 'nullable|numeric|min:0',
            'max_km_per_package' => 'nullable|numeric|min:0',
            'price_multiplier' => 'required|numeric|min:0',
            'base_rate_package_id' => ['nullable', Rule::exists('service_packages', 'id')->where('service_type_id', $request->input('service_type_id'))->where('is_active', true)->whereNull('deleted_at'), Rule::notIn([$servicePackage->id])],
            'rate_type' => 'required|string|in:flat,hourly,daily',
            'default_duration_hours' => 'required|integer|min:0',
            'default_duration_minutes' => 'required|integer|min:0|max:59',
            'charges_extra_hours' => 'boolean',
            'charges_extra_km' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'service_type_id' => 'required|exists:service_types,id',
            'is_active' => 'boolean',
            'supports_return_trip' => 'boolean',
            'default_vehicle_class_id' => 'nullable|uuid|exists:vehicle_classes,id',
            'allowed_upgrade_class_ids' => 'nullable|array',
            'allowed_upgrade_class_ids.*' => 'uuid|distinct|exists:vehicle_classes,id',
            'tour_itinerary_template' => 'nullable|array:days',
            'tour_itinerary_template.days' => 'required_with:tour_itinerary_template|array|min:1|max:60',
            'tour_itinerary_template.days.*' => 'array:day_number,timezone,start_time,start_destination_id,end_destination_id,overnight_destination_id,is_locked,stops',
            'tour_itinerary_template.days.*.day_number' => 'required|integer|min:1|max:60|distinct:strict',
            'tour_itinerary_template.days.*.timezone' => 'required|timezone',
            'tour_itinerary_template.days.*.start_time' => 'sometimes|date_format:H:i',
            'tour_itinerary_template.days.*.start_destination_id' => 'required|uuid',
            'tour_itinerary_template.days.*.end_destination_id' => 'required|uuid',
            'tour_itinerary_template.days.*.overnight_destination_id' => 'nullable|uuid',
            'tour_itinerary_template.days.*.is_locked' => 'sometimes|boolean',
            'tour_itinerary_template.days.*.stops' => 'sometimes|array|max:40',
            'tour_itinerary_template.days.*.stops.*' => 'array:kind,catalogue_id,is_locked,arrival_time',
            'tour_itinerary_template.days.*.stops.*.kind' => 'required|in:destination,activity',
            'tour_itinerary_template.days.*.stops.*.catalogue_id' => 'required|uuid',
            'tour_itinerary_template.days.*.stops.*.is_locked' => 'sometimes|boolean',
            'tour_itinerary_template.days.*.stops.*.arrival_time' => 'nullable|date_format:H:i',
        ]);
        $this->assertPositiveDefaultDuration($validated);
        $serviceType = ServiceType::findOrFail($validated['service_type_id']);
        $this->pricingContextPolicy->assertServiceTypeIsWritable($serviceType);
        $this->assertPackageVehicleClasses($serviceType, $validated);
        $this->assertTourItineraryTemplate($serviceType, $validated['tour_itinerary_template'] ?? null);

        $validated['updated_user_id'] = auth()->id();

        $previousTemplate = $servicePackage->tour_itinerary_template;
        DB::transaction(function () use ($servicePackage, $validated, $previousTemplate): void {
            $servicePackage->update($validated);
            $newTemplate = $validated['tour_itinerary_template'] ?? null;
            if ($newTemplate !== null && $newTemplate !== $previousTemplate) {
                $this->publishTourTemplateVersion($servicePackage, $newTemplate);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Service package updated successfully',
            'data' => ['package' => $servicePackage->load('serviceType')],
        ]);
    }

    /**
     * Delete a service package
     */
    public function destroy(ServicePackage $servicePackage): JsonResponse
    {
        $this->pricingContextPolicy->assertServicePackageIsWritable($servicePackage);
        $servicePackage->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Service package deleted successfully',
        ]);
    }

    private function assertPositiveDefaultDuration(array $validated): void
    {
        $totalMinutes = ((int) $validated['default_duration_hours'] * 60)
            + (int) $validated['default_duration_minutes'];

        if ($totalMinutes < 1 && ($validated['charges_extra_hours'] ?? false)) {
            throw ValidationException::withMessages([
                'default_duration_minutes' => 'Package duration must be at least one minute.',
            ]);
        }
    }

    private function assertPackageVehicleClasses(ServiceType $serviceType, array $data): void
    {
        $defaultClassId = $data['default_vehicle_class_id'] ?? null;
        $upgradeIds = $data['allowed_upgrade_class_ids'] ?? [];
        if ($serviceType->pricing_basis !== 'vehicle_class') {
            if ($defaultClassId || $upgradeIds !== []) {
                throw ValidationException::withMessages(['default_vehicle_class_id' => 'Vehicle classes can only be set for class-priced services.']);
            }

            return;
        }

        if (!$defaultClassId) {
            throw ValidationException::withMessages(['default_vehicle_class_id' => 'Choose the package’s default vehicle class.']);
        }

        $setting = \App\Models\Service\ServiceVehicleClassSetting::query()
            ->where('service_type_id', $serviceType->id)
            ->where('vehicle_class_id', $defaultClassId)
            ->where('is_active', true)
            ->where('is_hidden', false)
            ->first();
        if (!$setting) {
            throw ValidationException::withMessages(['default_vehicle_class_id' => 'The package default must be an active, visible class for this service.']);
        }

        $configuredUpgrades = $setting->allowed_upgrade_class_ids ?? [];
        if (array_diff($upgradeIds, $configuredUpgrades) !== []) {
            throw ValidationException::withMessages(['allowed_upgrade_class_ids' => 'Package upgrades must be permitted by the selected class settings.']);
        }
        if ($upgradeIds !== []) {
            $eligibleIds = \App\Models\Service\ServiceVehicleClassSetting::query()
                ->where('service_type_id', $serviceType->id)
                ->whereIn('vehicle_class_id', $upgradeIds)
                ->where('is_active', true)
                ->where('is_hidden', false)
                ->pluck('vehicle_class_id')
                ->all();
            if (count($eligibleIds) !== count(array_unique($upgradeIds))) {
                throw ValidationException::withMessages(['allowed_upgrade_class_ids' => 'Every package upgrade must be active and visible for this service.']);
            }
        }
    }

    private function assertTourItineraryTemplate(ServiceType $serviceType, ?array $template): void
    {
        if ($template === null) {
            return;
        }
        if ($serviceType->pricing_basis !== 'vehicle_class' || !$serviceType->tour_booking_enabled) {
            throw ValidationException::withMessages(['tour_itinerary_template' => 'Itinerary templates require an enabled class-priced Tours service.']);
        }

        $days = collect($template['days'] ?? []);
        $dayNumbers = $days->pluck('day_number')->map(fn($number) => (int) $number)->sort()->values()->all();
        if ($dayNumbers !== range(1, $days->count())) {
            throw ValidationException::withMessages(['tour_itinerary_template.days' => 'Template days must be numbered consecutively from one.']);
        }

        foreach ($days as $index => $day) {
            $startId = (string) $day['start_destination_id'];
            $endId = (string) $day['end_destination_id'];
            $overnightId = $day['overnight_destination_id'] ?? null;
            if ($overnightId && (string) $overnightId !== $endId) {
                throw ValidationException::withMessages(['tour_itinerary_template.days' => 'Each overnight destination must be that day’s end point.']);
            }
            if ($index > 0 && (string) $days[$index - 1]['end_destination_id'] !== $startId) {
                throw ValidationException::withMessages(['tour_itinerary_template.days' => 'Each template day must continue from the previous day’s end point.']);
            }

            $destinationIds = collect($day['stops'] ?? [])
                ->where('kind', 'destination')->pluck('catalogue_id')->map(fn($id) => (string) $id)->all();
            foreach ([$startId, $endId, ...array_filter([$overnightId]), ...$destinationIds] as $destinationId) {
                $valid = TourDestination::query()
                    ->where('service_type_id', $serviceType->id)
                    ->where('is_published', true)
                    ->whereKey($destinationId)->exists();
                if (!$valid) {
                    throw ValidationException::withMessages(['tour_itinerary_template.days' => 'Every template location must be a published destination for this service.']);
                }
            }

            foreach (($day['stops'] ?? []) as $stop) {
                if ($stop['kind'] === 'destination') {
                    continue;
                }
                $activity = TourActivity::query()
                    ->where('is_published', true)
                    ->whereKey($stop['catalogue_id'])
                    ->whereHas('destination', fn($query) => $query
                        ->where('service_type_id', $serviceType->id)
                        ->where('is_published', true))
                    ->first();
                if (!$activity || !in_array((string) $activity->tour_destination_id, $destinationIds, true)) {
                    throw ValidationException::withMessages(['tour_itinerary_template.days' => 'Each activity visit needs its published parent destination in that day’s stops.']);
                }
            }
        }
    }

    private function publishTourTemplateVersion(ServicePackage $package, array $template): void
    {
        $version = ((int) $package->tour_itinerary_template_version) + 1;
        $package->tour_itinerary_template_version = $version;
        $package->save();
        $package->tourTemplateVersions()->create([
            'version' => $version,
            'template' => $template,
            'created_user_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    // ==========================================
    // Return Trip Rules Management
    // ==========================================

    /**
     * Get all return rules for a service package
     */
    public function getReturnRules(ServicePackage $servicePackage): JsonResponse
    {
        $servicePackage = $this->pricingContextPolicy->effectiveServicePackage($servicePackage);
        $rules = $servicePackage->returnRules()
            ->with('vehicleGroup:id,name')
            ->orderBy('day_offset_min')
            ->orderByDesc('priority')
            ->get()
            ->map(function ($rule) {
                return [
                    'id' => $rule->id,
                    'service_package_id' => $rule->service_package_id,
                    'vehicle_group_id' => $rule->vehicle_group_id,
                    'vehicle_group_name' => $rule->vehicleGroup?->name,
                    'day_offset_min' => $rule->day_offset_min,
                    'day_offset_max' => $rule->day_offset_max,
                    'km_min' => $rule->km_min,
                    'km_max' => $rule->km_max,
                    'charge_percentage' => $rule->charge_percentage,
                    'discount_percentage' => $rule->discount_percentage,
                    'label' => $rule->label,
                    'description' => $rule->description,
                    'day_range_description' => $rule->day_range_description,
                    'km_range_description' => $rule->km_range_description,
                    'same_vehicle_required' => $rule->same_vehicle_required,
                    'same_driver_required' => $rule->same_driver_required,
                    'min_wait_minutes' => $rule->min_wait_minutes,
                    'max_wait_hours' => $rule->max_wait_hours,
                    'is_active' => $rule->is_active,
                    'priority' => $rule->priority,
                    'effective_from' => $rule->effective_from?->format('Y-m-d'),
                    'effective_to' => $rule->effective_to?->format('Y-m-d'),
                    'created_at' => $rule->created_at,
                    'updated_at' => $rule->updated_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'service_package' => [
                    'id' => $servicePackage->id,
                    'name' => $servicePackage->name,
                    'code' => $servicePackage->code,
                ],
                'return_rules' => $rules,
                'supports_return_trip' => $rules->where('is_active', true)->isNotEmpty(),
            ],
        ]);
    }

    /**
     * Store a new return rule for a service package
     */
    public function storeReturnRule(Request $request, ServicePackage $servicePackage): JsonResponse
    {
        $this->pricingContextPolicy->assertServicePackageIsWritable($servicePackage);

        $validated = $request->validate([
            'vehicle_group_id' => 'nullable|exists:vehicle_groups,id',
            'day_offset_min' => 'required|integer|min:0|max:365',
            'day_offset_max' => 'nullable|integer|min:0|max:365|gte:day_offset_min',
            'km_min' => 'nullable|numeric|min:0',
            'km_max' => 'nullable|numeric|min:0|gte:km_min',
            'charge_percentage' => 'required|numeric|min:0|max:200',
            'label' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'same_vehicle_required' => 'boolean',
            'same_driver_required' => 'boolean',
            'min_wait_minutes' => 'nullable|integer|min:0',
            'max_wait_hours' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'priority' => 'integer|min:0|max:1000',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ]);

        $validated['service_package_id'] = $servicePackage->id;
        $validated['created_user_id'] = auth()->id();

        // Set defaults
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['priority'] = $validated['priority'] ?? 0;
        $validated['same_vehicle_required'] = $validated['same_vehicle_required'] ?? false;
        $validated['same_driver_required'] = $validated['same_driver_required'] ?? false;

        $rule = ServicePackageReturnRule::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Return rule created successfully',
            'data' => [
                'rule' => $rule->load('vehicleGroup:id,name'),
            ],
        ], 201);
    }

    /**
     * Update an existing return rule
     */
    public function updateReturnRule(Request $request, ServicePackage $servicePackage, ServicePackageReturnRule $returnRule): JsonResponse
    {
        $this->pricingContextPolicy->assertServicePackageIsWritable($servicePackage);

        // Ensure the rule belongs to this package
        if ($returnRule->service_package_id !== $servicePackage->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Return rule does not belong to this service package',
            ], 404);
        }

        $validated = $request->validate([
            'vehicle_group_id' => 'nullable|exists:vehicle_groups,id',
            'day_offset_min' => 'required|integer|min:0|max:365',
            'day_offset_max' => 'nullable|integer|min:0|max:365|gte:day_offset_min',
            'km_min' => 'nullable|numeric|min:0',
            'km_max' => 'nullable|numeric|min:0|gte:km_min',
            'charge_percentage' => 'required|numeric|min:0|max:200',
            'label' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'same_vehicle_required' => 'boolean',
            'same_driver_required' => 'boolean',
            'min_wait_minutes' => 'nullable|integer|min:0',
            'max_wait_hours' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'priority' => 'integer|min:0|max:1000',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ]);

        $validated['updated_user_id'] = auth()->id();

        $returnRule->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Return rule updated successfully',
            'data' => [
                'rule' => $returnRule->fresh()->load('vehicleGroup:id,name'),
            ],
        ]);
    }

    /**
     * Delete a return rule
     */
    public function destroyReturnRule(ServicePackage $servicePackage, ServicePackageReturnRule $returnRule): JsonResponse
    {
        $this->pricingContextPolicy->assertServicePackageIsWritable($servicePackage);

        // Ensure the rule belongs to this package
        if ($returnRule->service_package_id !== $servicePackage->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Return rule does not belong to this service package',
            ], 404);
        }

        $returnRule->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Return rule deleted successfully',
        ]);
    }

    /**
     * Calculate return trip pricing for a given scenario
     * Public endpoint for booking form
     */
    public function calculateReturnPrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_package_id' => 'required|exists:service_packages,id',
            'vehicle_group_id' => 'nullable|exists:vehicle_groups,id',
            'outbound_date' => 'required|date',
            'return_date' => 'required|date|after_or_equal:outbound_date',
            'one_way_fare' => 'required|numeric|min:0',
            'kilometers' => 'nullable|numeric|min:0',
        ]);

        $servicePackage = $this->pricingContextPolicy->effectiveServicePackage(
            ServicePackage::findOrFail($validated['service_package_id'])
        );

        // Calculate day offset
        $outboundDate = \Carbon\Carbon::parse($validated['outbound_date'])->startOfDay();
        $returnDate = \Carbon\Carbon::parse($validated['return_date'])->startOfDay();
        $dayOffset = $outboundDate->diffInDays($returnDate);

        // Find matching rule
        $rule = $servicePackage->findReturnRule(
            $dayOffset,
            $validated['vehicle_group_id'] ?? null,
            null,
            $validated['kilometers'] ?? null
        );

        if (!$rule) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'has_return_rule' => false,
                    'day_offset' => $dayOffset,
                    'kilometers' => $validated['kilometers'] ?? null,
                    'charge_percentage' => 100,
                    'return_fare' => $validated['one_way_fare'],
                    'total_fare' => $validated['one_way_fare'] * 2,
                    'discount_amount' => 0,
                    'message' => 'No return discount available',
                ],
            ]);
        }

        $returnFare = $rule->calculateReturnFare($validated['one_way_fare']);
        $discountAmount = $validated['one_way_fare'] - $returnFare;

        return response()->json([
            'status' => 'success',
            'data' => [
                'has_return_rule' => true,
                'day_offset' => $dayOffset,
                'kilometers' => $validated['kilometers'] ?? null,
                'rule' => [
                    'id' => $rule->id,
                    'label' => $rule->label ?? $rule->day_range_description,
                    'charge_percentage' => $rule->charge_percentage,
                    'discount_percentage' => $rule->discount_percentage,
                    'km_range' => $rule->km_range_description,
                ],
                'one_way_fare' => round($validated['one_way_fare'], 2),
                'return_fare' => $returnFare,
                'total_fare' => round($validated['one_way_fare'] + $returnFare, 2),
                'discount_amount' => round($discountAmount, 2),
                'message' => $rule->label
                    ? "{$rule->label}: {$rule->discount_percentage}% off return trip"
                    : "{$rule->day_range_description}: {$rule->discount_percentage}% off return trip",
            ],
        ]);
    }
}
