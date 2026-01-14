<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Service\ServicePackage;
use App\Models\Service\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            $serviceType = ServiceType::where('code', $serviceCode)
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

            return response()->json([
                'status' => 'success',
                'data' => [
                    'service_type' => [
                        'id' => $serviceType->id,
                        'code' => $serviceType->code,
                        'name' => $serviceType->name,
                    ],
                    'packages' => $packages,
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
        ]);

        $validated['created_user_id'] = auth()->id();
        $validated['is_active'] = true;

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
}
