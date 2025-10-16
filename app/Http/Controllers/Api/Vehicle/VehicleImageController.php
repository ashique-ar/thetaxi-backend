<?php
// app/Http/Controllers/Api/Vehicle/VehicleImageController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleImage;
use App\Http\Requests\Vehicle\VehicleImage\CreateVehicleImageRequest;
use App\Http\Requests\Vehicle\VehicleImage\UpdateVehicleImageRequest;
use App\Http\Resources\Vehicle\VehicleImageResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleImageController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-images.view')->only(['index','show']);
        $this->middleware('permission:vehicle-images.create')->only(['store']);
        $this->middleware('permission:vehicle-images.edit')->only(['update']);
        $this->middleware('permission:vehicle-images.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleImage::query();
        return VehicleImageResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleImageRequest $request): JsonResponse
    {
        $img = VehicleImage::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Image created','data'=>['image'=>new VehicleImageResource($img)]],201);
    }

    public function show(VehicleImage $vehicleImage): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['image'=>new VehicleImageResource($vehicleImage)]]);
    }

    public function update(UpdateVehicleImageRequest $request, VehicleImage $vehicleImage): JsonResponse
    {
        $vehicleImage->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Image updated','data'=>['image'=>new VehicleImageResource($vehicleImage)]]);
    }

    public function destroy(VehicleImage $vehicleImage): JsonResponse
    {
        $vehicleImage->delete();
        return response()->json(['status'=>'success','message'=>'Image deleted']);
    }
}
