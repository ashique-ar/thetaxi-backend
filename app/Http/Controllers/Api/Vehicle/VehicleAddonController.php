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

class VehicleAddonController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-addons.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-addons.create')->only(['store']);
        $this->middleware('permission:vehicle-addons.edit')->only(['update']);
        $this->middleware('permission:vehicle-addons.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleAddon::query();
        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->search . '%');
        }
        return VehicleAddonResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleAddonRequest $request): JsonResponse
    {
        $data = $request->validated() + ['created_user_id' => $request->user()->id];
        $addon = VehicleAddon::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Addon created',
            'data' => ['addon' => new VehicleAddonResource($addon)]
        ], 201);
    }

    public function show(VehicleAddon $vehicleAddon): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['addon' => new VehicleAddonResource($vehicleAddon)]
        ]);
    }

    public function update(UpdateVehicleAddonRequest $request, VehicleAddon $vehicleAddon): JsonResponse
    {
        $vehicleAddon->update($request->validated() + ['updated_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Addon updated',
            'data' => ['addon' => new VehicleAddonResource($vehicleAddon)]
        ]);
    }

    public function destroy(VehicleAddon $vehicleAddon): JsonResponse
    {
        $vehicleAddon->delete();

        return response()->json(['status' => 'success', 'message' => 'Addon deleted']);
    }
}
