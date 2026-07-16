<?php
// app/Http/Controllers/Api/Vehicle/VehicleGradeController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleGrade;
use App\Http\Requests\Vehicle\VehicleGrade\CreateVehicleGradeRequest;
use App\Http\Requests\Vehicle\VehicleGrade\UpdateVehicleGradeRequest;
use App\Http\Resources\Vehicle\VehicleGradeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleGradeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-grades.view')->only(['index','show']);
        $this->middleware('permission:vehicle-grades.create')->only(['store']);
        $this->middleware('permission:vehicle-grades.edit')->only(['update']);
        $this->middleware('permission:vehicle-grades.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleGrade::query()->withCount('vehicles');
        if ($request->filled('search')) {
            $q->whereLikeInsensitive('name', $request->search);
        }
        return VehicleGradeResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleGradeRequest $request): JsonResponse
    {
        $vg = VehicleGrade::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Grade created','data'=>['grade'=>new VehicleGradeResource($vg)]],201);
    }

    public function show(VehicleGrade $vehicleGrade): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['grade'=>new VehicleGradeResource($vehicleGrade)]]);
    }

    public function update(UpdateVehicleGradeRequest $request, VehicleGrade $vehicleGrade): JsonResponse
    {
        $vehicleGrade->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Grade updated','data'=>['grade'=>new VehicleGradeResource($vehicleGrade)]]);
    }

    public function destroy(VehicleGrade $vehicleGrade): JsonResponse
    {
        $vehicleGrade->delete();
        return response()->json(['status'=>'success','message'=>'Grade deleted']);
    }
}
