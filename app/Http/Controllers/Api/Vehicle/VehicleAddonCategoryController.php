<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vehicle\VehicleAddonCategoryResource;
use App\Models\Vehicle\VehicleAddonCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleAddonCategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-addons.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-addons.create')->only('store');
        $this->middleware('permission:vehicle-addons.edit')->only('update');
        $this->middleware('permission:vehicle-addons.delete')->only('destroy');
    }

    public function index(Request $request): JsonResponse
    {
        $categories = VehicleAddonCategory::query()
            ->withCount('addons')
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['status' => 'success', 'data' => VehicleAddonCategoryResource::collection($categories)]);
    }

    public function store(Request $request): JsonResponse
    {
        $category = VehicleAddonCategory::create($this->validated($request));

        return response()->json([
            'status' => 'success',
            'message' => 'Add-on category created successfully.',
            'data' => new VehicleAddonCategoryResource($category),
        ], 201);
    }

    public function show(VehicleAddonCategory $vehicle_addon_category): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new VehicleAddonCategoryResource($vehicle_addon_category->loadCount('addons')),
        ]);
    }

    public function update(Request $request, VehicleAddonCategory $vehicle_addon_category): JsonResponse
    {
        $vehicle_addon_category->update($this->validated($request, $vehicle_addon_category));

        return response()->json([
            'status' => 'success',
            'message' => 'Add-on category updated successfully.',
            'data' => new VehicleAddonCategoryResource($vehicle_addon_category),
        ]);
    }

    public function destroy(VehicleAddonCategory $vehicle_addon_category): JsonResponse
    {
        abort_if($vehicle_addon_category->addons()->exists(), 422, 'Move add-ons out of this category before deleting it.');
        $vehicle_addon_category->delete();

        return response()->json(['status' => 'success', 'message' => 'Add-on category deleted successfully.']);
    }

    private function validated(Request $request, ?VehicleAddonCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('vehicle_addon_categories', 'name')->ignore($category)],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
