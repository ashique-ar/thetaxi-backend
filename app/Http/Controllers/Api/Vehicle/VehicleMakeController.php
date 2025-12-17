<?php
// app/Http/Controllers/Api/Vehicle/VehicleMakeController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleMake;
use App\Http\Requests\Vehicle\VehicleMake\CreateVehicleMakeRequest;
use App\Http\Requests\Vehicle\VehicleMake\UpdateVehicleMakeRequest;
use App\Http\Resources\Vehicle\VehicleMakeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleMakeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-makes.view')->only(['index','show']);
        $this->middleware('permission:vehicle-makes.create')->only(['store']);
        $this->middleware('permission:vehicle-makes.edit')->only(['update']);
        $this->middleware('permission:vehicle-makes.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleMake::query();
        if ($request->filled('search')) {
            // Case-insensitive search for name
            $search = mb_strtolower($request->search);
            $q->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%']);
        }
        return VehicleMakeResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleMakeRequest $request): JsonResponse
    {
        $mk = VehicleMake::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Make created','data'=>['make'=>new VehicleMakeResource($mk)]],201);
    }

    public function show(VehicleMake $vehicleMake): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['make'=>new VehicleMakeResource($vehicleMake)]]);
    }

    public function update(UpdateVehicleMakeRequest $request, VehicleMake $vehicleMake): JsonResponse
    {
        $vehicleMake->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Make updated','data'=>['make'=>new VehicleMakeResource($vehicleMake)]]);
    }

    public function destroy(VehicleMake $vehicleMake): JsonResponse
    {
        $vehicleMake->delete();
        return response()->json(['status'=>'success','message'=>'Make deleted']);
    }
}
