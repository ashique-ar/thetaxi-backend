<?php

// app/Http/Controllers/Api/VehiclePricing/PricingCommonRateDefinitionController.php
namespace App\Http\Controllers\Api\Vehicle\VehiclePricing;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Service\ServiceType;
use App\Http\Requests\Vehicle\VehiclePricingCommonRateDefinition\CreateVehiclePricingCommonRateDefinitionRequest;
use App\Http\Requests\Vehicle\VehiclePricingCommonRateDefinition\UpdateVehiclePricingCommonRateDefinitionRequest;
use App\Http\Resources\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinitionResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller for managing pricing common rate definitions
 * 
 * Handles CRUD operations for baseline pricing rates that serve as the foundation
 * for vehicle pricing calculations. These are distinct from vehicle common_rates which
 * are purchasable extras that customers can add to their bookings.
 */
class VehiclePricingCommonRateDefinitionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-pricing-common-rates.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-pricing-common-rates.create')->only(['store']);
        $this->middleware('permission:vehicle-pricing-common-rates.edit')->only(['update', 'reorder', 'toggleStatus', 'bulkToggleStatus']);
        $this->middleware('permission:vehicle-pricing-common-rates.delete')->only(['destroy', 'bulkDelete']);
        $this->middleware('permission:vehicle-pricing-common-rates.manage')->only(['export', 'import']);
    }

    /**
     * Display a listing of pricing common rate definitions
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = VehiclePricingCommonRateDefinition::withInactive()->with(['serviceType', 'createdBy', 'updatedBy']);

        // Apply filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('service_type_id')) {
            if ($request->service_type_id === 'global') {
                $query->whereNull('service_type_id');
            } else {
                $query->where('service_type_id', $request->service_type_id);
            }
        }

        if ($request->filled('owner_type')) {
            $query->where('owner_type', $request->owner_type)
                ->where('owner_id', $request->owner_id);
        } elseif ($request->boolean('global_only', false)) {
            $query->whereNull('owner_type')->whereNull('owner_id');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('is_mandatory')) {
            $query->where('is_mandatory', $request->boolean('is_mandatory'));
        }

        if ($request->filled('common_rate_type')) {
            $query->where('common_rate_type', $request->common_rate_type);
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortOrder = $request->get('sort_order', 'asc');

        if ($sortBy === 'sort_order') {
            $query->orderBy('sort_order', 'asc')->orderBy('name', 'asc');
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min($request->get('per_page', 15), 100);

        return VehiclePricingCommonRateDefinitionResource::collection(
            $query->paginate($perPage)
        );
    }

    /**
     * Store a newly created common rate definition
     */
    public function store(CreateVehiclePricingCommonRateDefinitionRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $data = $request->validated();
            $data['owner_id'] = ($data['owner_type'] ?? null) ? ($data['owner_id'] ?? null) : null;
            $data['created_user_id'] = $request->user()->id;
            $data['updated_user_id'] = $request->user()->id;

            // Auto-assign sort order if not provided
            if (!isset($data['sort_order'])) {
                $maxOrder = VehiclePricingCommonRateDefinition::where('service_type_id', $data['service_type_id'] ?? null)
                    ->max('sort_order') ?? 0;
                $data['sort_order'] = $maxOrder + 1;
            }

            $commonRate = VehiclePricingCommonRateDefinition::create($data);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Common rate definition created successfully',
                'data' => [
                    'common_rate' => new VehiclePricingCommonRateDefinitionResource($commonRate->load(['serviceType', 'createdBy']))
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create pricing common rate definition', [
                'error' => $e->getMessage(),
                'data' => $request->validated()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create common rate definition: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified common rate definition
     */
    public function show(string $id): JsonResponse
    {
        try {
            $commonRate = VehiclePricingCommonRateDefinition::with(['serviceType', 'createdBy', 'updatedBy'])
                ->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'common_rate' => new VehiclePricingCommonRateDefinitionResource($commonRate)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Common rate definition not found'
            ], 404);
        }
    }

    /**
     * Update the specified common rate definition
     */
    public function update(UpdateVehiclePricingCommonRateDefinitionRequest $request, string $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $commonRate = VehiclePricingCommonRateDefinition::findOrFail($id);

            $data = $request->validated();
            $data['owner_id'] = ($data['owner_type'] ?? null) ? ($data['owner_id'] ?? null) : null;
            $data['updated_user_id'] = $request->user()->id;

            $commonRate->update($data);

            DB::commit();


            return response()->json([
                'status' => 'success',
                'message' => 'Common rate definition updated successfully',
                'data' => [
                    'common_rate' => new VehiclePricingCommonRateDefinitionResource($commonRate->load(['serviceType', 'updatedBy']))
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update pricing common rate definition', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update common rate definition: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified common rate definition
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $commonRate = VehiclePricingCommonRateDefinition::findOrFail($id);

            // Check if the rate is being used in vehicle group pricing
            if ($commonRate->vehicleGroupAddonPricing()->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete common rate definition as it is being used in vehicle group pricing'
                ], 422);
            }

            $commonRate->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Common rate definition deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete pricing common rate definition', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete common rate definition: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the active status of a common rate definition
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        try {
            $commonRate = VehiclePricingCommonRateDefinition::findOrFail($id);

            $commonRate->update([
                'is_active' => !$commonRate->is_active,
                'updated_user_id' => $request->user()->id
            ]);

            $status = $commonRate->is_active ? 'activated' : 'deactivated';

            return response()->json([
                'status' => 'success',
                'message' => "Common rate definition {$status} successfully",
                'data' => [
                    'common_rate' => new VehiclePricingCommonRateDefinitionResource($commonRate)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update common rate definition status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get common rate definitions by service type
     */
    public function getByServiceType(Request $request, string $serviceTypeId): JsonResponse
    {
        try {
            $query = VehiclePricingCommonRateDefinition::active();

            if ($serviceTypeId === 'global') {
                $query->whereNull('service_type_id');
            } else {
                // Verify service type exists
                ServiceType::findOrFail($serviceTypeId);
                $query->where('service_type_id', $serviceTypeId);
            }

            if ($request->has('is_mandatory')) {
                $query->where('is_mandatory', $request->boolean('is_mandatory'));
            }

            if ($request->filled('common_rate_type')) {
                $query->where('common_rate_type', $request->common_rate_type);
            }

            $commonRates = $query->orderBy('sort_order')->orderBy('name')->get();

            return response()->json([
                'status' => 'success',
                'data' => VehiclePricingCommonRateDefinitionResource::collection($commonRates),
                'meta' => [
                    'total' => $commonRates->count(),
                    'service_type_id' => $serviceTypeId === 'global' ? null : $serviceTypeId
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch common rate definitions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reorder common rate definitions
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|string|exists:vehicles,id',
            'items.*.sort_order' => 'required|integer|min:0'
        ]);

        try {
            DB::beginTransaction();

            foreach ($request->items as $item) {
                VehiclePricingCommonRateDefinition::where('id', $item['id'])
                    ->update([
                        'sort_order' => $item['sort_order'],
                        'updated_user_id' => $request->user()->id
                    ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Common rate definitions reordered successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reorder common rate definitions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics about common rate definitions
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $stats = [
                'total' => VehiclePricingCommonRateDefinition::count(),
                'active' => VehiclePricingCommonRateDefinition::where('is_active', true)->count(),
                'inactive' => VehiclePricingCommonRateDefinition::where('is_active', false)->count(),
                'mandatory' => VehiclePricingCommonRateDefinition::where('is_mandatory', true)->count(),
                'by_type' => VehiclePricingCommonRateDefinition::select('common_rate_type')
                    ->selectRaw('count(*) as count')
                    ->groupBy('common_rate_type')
                    ->pluck('count', 'common_rate_type'),
                'by_service_type' => VehiclePricingCommonRateDefinition::with('serviceType')
                    ->get()
                    ->groupBy(function ($item) {
                        return $item->serviceType ? $item->serviceType->name : 'Global';
                    })
                    ->map(function ($items) {
                        return $items->count();
                    })
            ];

            return response()->json([
                'status' => 'success',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk toggle status for multiple common rate definitions
     */
    public function bulkToggleStatus(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string|exists:pricing_common_rates,id',
            'is_active' => 'required|boolean'
        ]);

        try {
            $updated = VehiclePricingCommonRateDefinition::whereIn('id', $request->ids)
                ->update([
                    'is_active' => $request->is_active,
                    'updated_user_id' => $request->user()->id
                ]);

            return response()->json([
                'status' => 'success',
                'message' => "Successfully updated {$updated} common rate definitions",
                'data' => [
                    'updated_count' => $updated,
                    'is_active' => $request->is_active
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update common rate definitions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete multiple common rate definitions
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string|exists:pricing_common_rates,id'
        ]);

        try {
            // Check if any have associated vehicle group pricing
            $hasAssociations = VehiclePricingCommonRateDefinition::whereIn('id', $request->ids)
                ->whereHas('vehicleGroupAddonPricing')
                ->exists();

            if ($hasAssociations) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete common rate definitions with associated vehicle group pricing records'
                ], 422);
            }

            $deleted = VehiclePricingCommonRateDefinition::whereIn('id', $request->ids)->delete();

            return response()->json([
                'status' => 'success',
                'message' => "Successfully deleted {$deleted} common rate definitions",
                'data' => ['deleted_count' => $deleted]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete common rate definitions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate preview for a common rate definition
     */
    public function calculatePreview(Request $request): JsonResponse
    {
        $request->validate([
            'rate_id' => 'required|string|exists:pricing_common_rates,id',
            'base_amount' => 'required|numeric|min:0',
            'hours' => 'integer|min:1',
            'days' => 'integer|min:1',
            'kilometers' => 'numeric|min:0'
        ]);

        try {
            $rate = VehiclePricingCommonRateDefinition::findOrFail($request->rate_id);

            $calculatedAmount = $rate->calculateAmount(
                $request->base_amount,
                $request->hours ?? 1,
                $request->days ?? 1,
                $request->kilometers ?? 0
            );

            return response()->json([
                'status' => 'success',
                'data' => [
                    'rate' => new VehiclePricingCommonRateDefinitionResource($rate),
                    'calculated_amount' => $calculatedAmount,
                    'calculation_details' => [
                        'base_amount' => $request->base_amount,
                        'hours' => $request->hours ?? 1,
                        'days' => $request->days ?? 1,
                        'kilometers' => $request->kilometers ?? 0,
                        'rate_type' => $rate->common_rate_type,
                        'formula' => $this->getCalculationFormula(
                            $rate->common_rate_type,
                            $rate->value,
                            $request->base_amount,
                            $request->hours ?? 1,
                            $request->days ?? 1,
                            $request->kilometers ?? 0
                        )
                    ]
                ],
                'message' => 'Rate calculation completed successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to calculate rate: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper method to get calculation formula description
     */
    private function getCalculationFormula(
        string $rateType,
        float $rateValue,
        float $baseAmount,
        int $hours,
        int $days,
        float $kilometers
    ): string {
        $formatted = fn($num) => number_format($num, 2);

        return match ($rateType) {
            'percentage' => "(LKR {$formatted($baseAmount)} × {$formatted($rateValue)}%) = LKR {$formatted(($baseAmount * $rateValue) / 100)}",
            'per_hour' => "(LKR {$formatted($rateValue)} × {$hours} hours) = LKR {$formatted($rateValue * $hours)}",
            'per_day' => "(LKR {$formatted($rateValue)} × {$days} days) = LKR {$formatted($rateValue * $days)}",
            'per_km' => "(LKR {$formatted($rateValue)} × {$formatted($kilometers)} km) = LKR {$formatted($rateValue * $kilometers)}",
            'fixed_amount' => "Fixed amount = LKR {$formatted($rateValue)}",
            default => "LKR {$formatted($rateValue)}"
        };
    }

    public function validateName(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['name' => 'required|string|max:255']);

        $query = VehiclePricingCommonRateDefinition::where('name', $request->input('name'));
        if ($request->filled('exclude_id')) {
            $query->where('id', '!=', $request->input('exclude_id'));
        }

        return response()->json([
            'status' => 'success',
            'data'   => ['is_unique' => !$query->exists()],
        ]);
    }

    public function export(\Illuminate\Http\Request $request)
    {
        $definitions = VehiclePricingCommonRateDefinition::with('serviceType')
            ->when($request->filled('service_type_id'), fn($q) => $q->forServiceType($request->input('service_type_id')))
            ->get();

        if ($definitions->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'No records to export'], 404);
        }

        $rows   = $definitions->map(fn($d) => [
            '"' . $d->id . '"',
            '"' . str_replace('"', '""', $d->name) . '"',
            '"' . str_replace('"', '""', $d->serviceType?->name ?? '') . '"',
            '"' . $d->common_rate_type . '"',
            $d->rate_value,
            $d->is_active ? '"yes"' : '"no"',
        ]);
        $header = '"id","name","service_type","common_rate_type","rate_value","is_active"';
        $csv    = $header . "\n" . $rows->map(fn($r) => implode(',', $r))->implode("\n");

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="common-rate-definitions.csv"',
        ]);
    }

    public function import(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        $lines   = file($request->file('file')->getRealPath());
        $headers = array_map('trim', str_getcsv(array_shift($lines)));
        $created = 0;

        DB::beginTransaction();
        try {
            foreach ($lines as $line) {
                $row  = array_combine($headers, array_map('trim', str_getcsv($line)));
                VehiclePricingCommonRateDefinition::updateOrCreate(
                    ['name' => $row['name'] ?? ''],
                    [
                        'common_rate_type' => $row['common_rate_type'] ?? 'fixed_amount',
                        'rate_value'       => (float) ($row['rate_value'] ?? 0),
                        'is_active'        => strtolower($row['is_active'] ?? 'yes') === 'yes',
                    ]
                );
                $created++;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'data' => ['imported' => $created]]);
    }

    public function downloadTemplate()
    {
        $csv = "name,service_type_id,common_rate_type,rate_value,is_active\n"
             . "\"Example Rate\",\"\",\"fixed_amount\",\"10.00\",\"yes\"\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="common-rate-definitions-template.csv"',
        ]);
    }
}
