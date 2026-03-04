<?php
// app/Http/Controllers/Api/Vehicle/VehicleAddonController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleAddon;
use App\Http\Requests\Vehicle\VehicleAddon\CreateVehicleAddonRequest;
use App\Http\Requests\Vehicle\VehicleAddon\UpdateVehicleAddonRequest;
use App\Http\Resources\Vehicle\VehicleAddonResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VehicleAddonController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-addons.view')->only(['index', 'show', 'stats', 'available', 'forService']);
        $this->middleware('permission:vehicle-addons.create')->only(['store']);
        $this->middleware('permission:vehicle-addons.edit')->only(['update', 'toggleStatus', 'bulkUpdateStatus']);
        $this->middleware('permission:vehicle-addons.delete')->only(['destroy']);
    }

    /**
     * Display a listing of vehicle addons with filters.
     */
    public function index(Request $request)
    {
        $query = VehicleAddon::withInactive()->with('serviceType');

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by service type
        if ($request->filled('service_type_id')) {
            $query->forServiceType($request->service_type_id);
        }
        
        // Only apply is_active filter if explicitly set
        if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by addon type
        if ($request->filled('addon_type')) {
            $query->where('addon_type', $request->addon_type);
        }

        // Filter by pricing type
        if ($request->filled('pricing_type')) {
            $query->where('pricing_type', $request->pricing_type);
        }

        // Filter by active status - only if explicitly set (not 'all')
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by availability type
        if ($request->filled('availability_type')) {
            $query->where('availability_type', $request->availability_type);
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortDir = $request->input('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        return VehicleAddonResource::collection(
            $query->paginate($request->per_page ?? 15)
        );
    }

    /**
     * Store a newly created vehicle addon.
     */
    public function store(CreateVehicleAddonRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;

        $addon = VehicleAddon::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Addon created successfully',
            'data' => new VehicleAddonResource($addon->load('serviceType'))
        ], 201);
    }

    /**
     * Display the specified vehicle addon.
     */
    public function show(Request $request, VehicleAddon $vehicleAddon): JsonResponse
    {
        $includes = $request->input('include', '');
        $relations = array_filter(explode(',', $includes));

        // Always load serviceType
        if (!in_array('serviceType', $relations)) {
            $relations[] = 'serviceType';
        }

        return response()->json([
            'status' => 'success',
            'data' => new VehicleAddonResource($vehicleAddon->load($relations))
        ]);
    }

    /**
     * Update the specified vehicle addon.
     */
    public function update(UpdateVehicleAddonRequest $request, VehicleAddon $vehicleAddon): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;

        $vehicleAddon->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Addon updated successfully',
            'data' => new VehicleAddonResource($vehicleAddon->load('serviceType'))
        ]);
    }

    /**
     * Remove the specified vehicle addon.
     */
    public function destroy(VehicleAddon $vehicleAddon): JsonResponse
    {
        $vehicleAddon->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Addon deleted successfully'
        ]);
    }

    /**
     * Toggle addon active status.
     */
    public function toggleStatus(Request $request, VehicleAddon $vehicleAddon): JsonResponse
    {
        $request->validate([
            'is_active' => ['required', 'boolean']
        ]);

        $vehicleAddon->update([
            'is_active' => $request->is_active,
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $request->is_active ? 'Addon activated' : 'Addon deactivated',
            'data' => new VehicleAddonResource($vehicleAddon->load('serviceType'))
        ]);
    }

    /**
     * Bulk update addon status.
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $request->validate([
            'addon_ids' => ['required', 'array'],
            'addon_ids.*' => ['uuid', 'exists:vehicle_addons,id'],
            'is_active' => ['required', 'boolean']
        ]);

        VehicleAddon::whereIn('id', $request->addon_ids)->update([
            'is_active' => $request->is_active,
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => count($request->addon_ids) . ' addons updated successfully'
        ]);
    }

    /**
     * Get available addons based on context.
     */
    public function available(Request $request)
    {
        $query = VehicleAddon::query()
            ->with('serviceType')
            ->available();

        // Filter by service type
        if ($request->filled('service_type')) {
            $query->forServiceType($request->service_type);
        }

        // Filter by vehicle category/type
        if ($request->filled('vehicle_category')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('compatible_vehicle_types')
                    ->orWhereJsonContains('compatible_vehicle_types', $request->vehicle_category);
            });
        }

        return response()->json([
            'status' => 'success',
            'data' => VehicleAddonResource::collection($query->orderBy('sort_order')->get())
        ]);
    }

    /**
     * Get addons for a specific service type.
     */
    public function forService(Request $request): JsonResponse
    {
        $request->validate([
            'service_type' => ['required', 'string']
        ]);

        $query = VehicleAddon::query()
            ->with('serviceType')
            ->available()
            ->forServiceType($request->service_type);

        if ($request->filled('vehicle_type')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('compatible_vehicle_types')
                    ->orWhereJsonContains('compatible_vehicle_types', $request->vehicle_type);
            });
        }

        return response()->json([
            'status' => 'success',
            'data' => VehicleAddonResource::collection($query->orderBy('sort_order')->get())
        ]);
    }

    /**
     * Get addon statistics.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'totalAddOns' => VehicleAddon::count(),
            'activeAddOns' => VehicleAddon::where('is_active', true)->count(),
            'categories' => VehicleAddon::whereNotNull('category_id')
                ->distinct('category_id')
                ->count('category_id'),
            'averagePrice' => round(VehicleAddon::avg('amount') ?? 0, 2),
            'byType' => VehicleAddon::select('addon_type', DB::raw('count(*) as count'))
                ->groupBy('addon_type')
                ->pluck('count', 'addon_type'),
            'byPricingType' => VehicleAddon::select('pricing_type', DB::raw('count(*) as count'))
                ->groupBy('pricing_type')
                ->pluck('count', 'pricing_type'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Calculate addon price based on quantity and context.
     */
    public function calculatePrice(Request $request): JsonResponse
    {
        $request->validate([
            'addon_id' => ['required', 'uuid', 'exists:vehicle_addons,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'service_type' => ['nullable', 'string'],
        ]);

        $addon = VehicleAddon::findOrFail($request->addon_id);

        $calculation = [
            'addon_id' => $addon->id,
            'addon' => new VehicleAddonResource($addon),
            'quantity' => $request->quantity,
            'base_price' => (float) $addon->amount,
            'unit_price' => (float) $addon->amount,
            'threshold_applied' => false,
            'threshold_quantity' => null,
            'threshold_price' => null,
            'subtotal' => 0,
            'tax_amount' => 0,
            'final_amount' => 0,
        ];

        // Calculate with threshold if applicable
        if ($addon->threshold_quantity && $addon->threshold_price && $request->quantity > $addon->threshold_quantity) {
            $baseQty = $addon->threshold_quantity;
            $thresholdQty = $request->quantity - $addon->threshold_quantity;
            $calculation['subtotal'] = ($addon->amount * $baseQty) + ($addon->threshold_price * $thresholdQty);
            $calculation['threshold_applied'] = true;
            $calculation['threshold_quantity'] = $addon->threshold_quantity;
            $calculation['threshold_price'] = (float) $addon->threshold_price;
        } else {
            $calculation['subtotal'] = $addon->amount * $request->quantity;
        }

        // Apply tax if applicable
        if ($addon->is_taxable && $addon->tax_rate > 0) {
            $calculation['tax_amount'] = round($calculation['subtotal'] * ($addon->tax_rate / 100), 2);
        }

        $calculation['final_amount'] = round($calculation['subtotal'] + $calculation['tax_amount'], 2);

        return response()->json([
            'status' => 'success',
            'data' => $calculation
        ]);
    }

    /**
     * Calculate prices for multiple addons.
     */
    public function calculateMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'addons' => ['required', 'array'],
            'addons.*.addon_id' => ['required', 'uuid', 'exists:vehicle_addons,id'],
            'addons.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $calculations = [];
        $grandTotal = 0;

        foreach ($request->addons as $addonRequest) {
            $addon = VehicleAddon::find($addonRequest['addon_id']);
            if (!$addon)
                continue;

            $quantity = $addonRequest['quantity'];
            $subtotal = $addon->calculatePrice($quantity);
            $grandTotal += $subtotal;

            $calculations[] = [
                'addon_id' => $addon->id,
                'name' => $addon->name,
                'quantity' => $quantity,
                'unit_price' => (float) $addon->amount,
                'subtotal' => $subtotal,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'calculations' => $calculations,
                'grand_total' => round($grandTotal, 2)
            ]
        ]);
    }

    /**
     * Check addon availability for booking context.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'addon_id' => ['required', 'uuid', 'exists:vehicle_addons,id'],
            'service_type' => ['required', 'string'],
        ]);

        $addon = VehicleAddon::findOrFail($request->addon_id);

        $available = true;
        $reason = null;
        $minQuantity = $addon->min_qty;

        // Check if addon is active
        if (!$addon->is_active) {
            $available = false;
            $reason = 'This addon is currently unavailable';
        }

        // Check validity dates
        if ($available && !$addon->isValid()) {
            $available = false;
            $reason = 'This addon is not available for the selected dates';
        }

        // Check service type compatibility
        if ($available && $addon->service_type_id) {
            $serviceType = $addon->serviceType;
            if ($serviceType && $serviceType->code !== $request->service_type) {
                $available = false;
                $reason = 'This addon is not available for the selected service type';
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'available' => $available,
                'reason' => $reason,
                'min_quantity' => $minQuantity,
            ]
        ]);
    }
}
