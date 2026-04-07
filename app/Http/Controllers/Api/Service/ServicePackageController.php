<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Service\ServicePackage;
use App\Models\Service\ServicePackageReturnRule;
use App\Models\Service\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\DynamicServiceConfigurationService;

class ServicePackageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except(['getPackagesByService']);
        $this->middleware('permission:service-packages.view')->only(['index']);
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
            'rate_type' => 'required|string|in:flat,per_hour,per_day',
            'default_duration_hours' => 'required|integer|min:1',
            'sort_order' => 'nullable|integer|min:0',
            'supports_return_trip' => 'boolean',
        ]);

        $validated['created_user_id'] = auth()->id();
        $validated['is_active'] = true;
        $validated['supports_return_trip'] = $validated['supports_return_trip'] ?? false;

        $package = ServicePackage::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Service package created successfully',
            'data' => ['package' => $package->load('serviceType')],
        ], 201);
    }

    /**
     * Update an existing service package
     */
    public function update(Request $request, ServicePackage $servicePackage): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:service_packages,code,' . $servicePackage->id,
            'description' => 'nullable|string',
            'max_km_per_day' => 'nullable|numeric|min:0',
            'max_km_per_package' => 'nullable|numeric|min:0',
            'price_multiplier' => 'required|numeric|min:0',
            'rate_type' => 'required|string|in:flat,per_hour,per_day',
            'default_duration_hours' => 'required|integer|min:1',
            'sort_order' => 'nullable|integer|min:0',
            'service_type_id' => 'required|exists:service_types,id',
            'is_active' => 'boolean',
            'supports_return_trip' => 'boolean',
        ]);

        $validated['updated_user_id'] = auth()->id();

        $servicePackage->update($validated);

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
        $servicePackage->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Service package deleted successfully',
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

        $servicePackage = ServicePackage::find($validated['service_package_id']);

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
