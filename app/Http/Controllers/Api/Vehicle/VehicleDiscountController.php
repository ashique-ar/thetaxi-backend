<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleDiscount;
use App\Http\Requests\Vehicle\VehicleDiscountRequest;
use App\Http\Resources\Vehicle\VehicleDiscountResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Vehicle Discount Controller
 * 
 * Manages discount CRUD operations with precedence-based logic
 * and comprehensive filtering capabilities.
 */
class VehicleDiscountController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-discounts.view')->only(['index', 'show', 'getApplicable', 'calculatePreview', 'getStats', 'history']);
        $this->middleware('permission:vehicle-discounts.create')->only(['store']);
        $this->middleware('permission:vehicle-discounts.edit')->only(['update', 'toggleStatus', 'bulkToggleStatus']);
        $this->middleware('permission:vehicle-discounts.delete')->only(['destroy', 'bulkDestroy']);
    }

    /**
     * Get paginated list of discounts with filtering
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = VehicleDiscount::withInactive()->with(['serviceType', 'vehicleGroup', 'createdBy']);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereLikeInsensitive('code', $search)
                  ->orWhereLikeInsensitive('name', $search)
                  ->orWhereLikeInsensitive('description', $search);
            });
        }

        // Service type filter
        if ($request->filled('service_type_id')) {
            if ($request->service_type_id === 'null') {
                $query->whereNull('service_type_id');
            } else {
                $query->where('service_type_id', $request->service_type_id);
            }
        }

        // Vehicle group filter
        if ($request->filled('vehicle_group_id')) {
            if ($request->vehicle_group_id === 'null') {
                $query->whereNull('vehicle_group_id');
            } else {
                $query->where('vehicle_group_id', $request->vehicle_group_id);
            }
        }

        // Status filter - only if explicitly set (not 'all')
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Validity filter
        if ($request->filled('validity')) {
            $now = Carbon::now();
            switch ($request->validity) {
                case 'valid':
                    $query->valid();
                    break;
                case 'expired':
                    $query->where('valid_to', '<', $now);
                    break;
                case 'future':
                    $query->where('valid_from', '>', $now);
                    break;
            }
        }

        // Precedence filter
        if ($request->filled('precedence')) {
            switch ($request->precedence) {
                case 'service_group':
                    $query->whereNotNull('service_type_id')->whereNotNull('vehicle_group_id');
                    break;
                case 'service_only':
                    $query->whereNotNull('service_type_id')->whereNull('vehicle_group_id');
                    break;
                case 'group_only':
                    $query->whereNull('service_type_id')->whereNotNull('vehicle_group_id');
                    break;
                case 'global':
                    $query->whereNull('service_type_id')->whereNull('vehicle_group_id');
                    break;
            }
        }

        // Type filter
        if ($request->filled('discount_type')) {
            $query->where('is_percentage', $request->discount_type === 'percentage');
        }

        // Sort by precedence by default, then by amount descending
        $query->byPrecedence();

        $perPage = $request->input('per_page', 15);
        $discounts = $query->paginate($perPage);

        return VehicleDiscountResource::collection($discounts);
    }

    /**
     * Create new discount
     */
    public function store(VehicleDiscountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;

        $discount = VehicleDiscount::create($data);
        $discount->load(['serviceType', 'vehicleGroup', 'createdBy']);

        return response()->json([
            'status' => 'success',
            'message' => 'Discount created successfully',
            'data' => new VehicleDiscountResource($discount)
        ], 201);
    }

    /**
     * Get specific discount
     */
    public function show(VehicleDiscount $vehicleDiscount): JsonResponse
    {
        $vehicleDiscount->load(['serviceType', 'vehicleGroup', 'createdBy', 'updatedBy']);

        return response()->json([
            'status' => 'success',
            'data' => new VehicleDiscountResource($vehicleDiscount)
        ]);
    }

    public function history(VehicleDiscount $vehicleDiscount): JsonResponse
    {
        $activities = $vehicleDiscount->activitiesAsSubject()
            ->with('causer:id,first_name,last_name,email')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($activity) => [
                'id' => $activity->id,
                'event' => $activity->event,
                'description' => $activity->description,
                'changes' => $activity->attribute_changes,
                'causer' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'name' => trim($activity->causer->first_name . ' ' . $activity->causer->last_name),
                    'email' => $activity->causer->email,
                ] : null,
                'created_at' => $activity->created_at,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => ['history' => $activities],
        ]);
    }

    /**
     * Update discount
     */
    public function update(VehicleDiscountRequest $request, VehicleDiscount $vehicleDiscount): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;

        $vehicleDiscount->update($data);
        $vehicleDiscount->load(['serviceType', 'vehicleGroup', 'createdBy', 'updatedBy']);

        return response()->json([
            'status' => 'success',
            'message' => 'Discount updated successfully',
            'data' => new VehicleDiscountResource($vehicleDiscount)
        ]);
    }

    /**
     * Delete discount
     */
    public function destroy(VehicleDiscount $vehicleDiscount): JsonResponse
    {
        $vehicleDiscount->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Discount deleted successfully'
        ]);
    }

    /**
     * Toggle discount active status
     */
    public function toggleStatus(VehicleDiscount $vehicleDiscount): JsonResponse
    {
        $vehicleDiscount->update([
            'is_active' => !$vehicleDiscount->is_active,
            'updated_user_id' => request()->user()->id
        ]);

        $vehicleDiscount->load(['serviceType', 'vehicleGroup']);

        return response()->json([
            'status' => 'success',
            'message' => $vehicleDiscount->is_active ? 'Discount activated' : 'Discount deactivated',
            'data' => new VehicleDiscountResource($vehicleDiscount)
        ]);
    }

    /**
     * Get applicable discounts for specific criteria
     */
    public function getApplicable(Request $request): JsonResponse
    {
        $request->validate([
            'service_type_id' => 'nullable|string|exists:service_types,id',
            'vehicle_group_id' => 'nullable|string|exists:vehicle_groups,id',
            'amount' => 'nullable|numeric|min:0'
        ]);

        $discounts = VehicleDiscount::getApplicableDiscounts(
            $request->service_type_id,
            $request->vehicle_group_id,
            $request->amount
        );

        return response()->json([
            'status' => 'success',
            'data' => VehicleDiscountResource::collection($discounts),
            'meta' => [
                'total_applicable' => $discounts->count(),
                'best_discount' => $discounts->first() ? new VehicleDiscountResource($discounts->first()) : null
            ]
        ]);
    }

    /**
     * Calculate discount amount for preview
     */
    public function calculatePreview(Request $request): JsonResponse
    {
        $request->validate([
            'discount_id' => 'required|string|exists:vehicle_discounts,id',
            'base_amount' => 'required|numeric|min:0'
        ]);

        $discount = VehicleDiscount::findOrFail($request->discount_id);
        $discountAmount = $discount->calculateDiscount($request->base_amount);

        return response()->json([
            'status' => 'success',
            'data' => [
                'base_amount' => round($request->base_amount, 2),
                'discount_amount' => $discountAmount,
                'final_amount' => round($request->base_amount - $discountAmount, 2),
                'discount_percentage' => $request->base_amount > 0 
                    ? round(($discountAmount / $request->base_amount) * 100, 2) 
                    : 0,
                'is_applicable' => $discountAmount > 0
            ]
        ]);
    }

    /**
     * Get discount statistics
     */
    public function getStats(): JsonResponse
    {
        $now = Carbon::now();

        $stats = [
            'total_discounts' => VehicleDiscount::count(),
            'active_discounts' => VehicleDiscount::where('is_active', true)->count(),
            'valid_discounts' => VehicleDiscount::valid()->count(),
            'expired_discounts' => VehicleDiscount::where('valid_to', '<', $now)->count(),
            'usage_stats' => [
                'total_usage' => VehicleDiscount::sum('usage_count'),
                'unlimited_discounts' => VehicleDiscount::whereNull('usage_limit')->count(),
                'limited_discounts' => VehicleDiscount::whereNotNull('usage_limit')->count()
            ],
            'by_precedence' => [
                'service_group' => VehicleDiscount::whereNotNull('service_type_id')->whereNotNull('vehicle_group_id')->count(),
                'service_only' => VehicleDiscount::whereNotNull('service_type_id')->whereNull('vehicle_group_id')->count(),
                'group_only' => VehicleDiscount::whereNull('service_type_id')->whereNotNull('vehicle_group_id')->count(),
                'global' => VehicleDiscount::whereNull('service_type_id')->whereNull('vehicle_group_id')->count()
            ],
            'by_type' => [
                'percentage' => VehicleDiscount::where('is_percentage', true)->count(),
                'fixed' => VehicleDiscount::where('is_percentage', false)->count()
            ]
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Bulk update discount status
     */
    public function bulkToggleStatus(Request $request): JsonResponse
    {
        $request->validate([
            'discount_ids' => 'required|array',
            'discount_ids.*' => 'string|exists:vehicle_discounts,id',
            'is_active' => 'required|boolean'
        ]);

        $updatedCount = VehicleDiscount::whereIn('id', $request->discount_ids)
            ->update([
                'is_active' => $request->is_active,
                'updated_user_id' => $request->user()->id,
                'updated_at' => now()
            ]);

        return response()->json([
            'status' => 'success',
            'message' => "Successfully updated {$updatedCount} discounts",
            'data' => [
                'updated_count' => $updatedCount,
                'is_active' => $request->is_active
            ]
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discount_ids' => ['required', 'array', 'min:1'],
            'discount_ids.*' => ['string', 'distinct', 'exists:vehicle_discounts,id'],
        ]);

        $deletedCount = DB::transaction(
            fn () => VehicleDiscount::whereIn('id', $validated['discount_ids'])->delete()
        );

        return response()->json([
            'status' => 'success',
            'message' => "Successfully deleted {$deletedCount} discounts",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }
}
