<?php
// app/Http/Controllers/Api/DrivingLicenseTypeController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrivingLicenseType;
use App\Http\Requests\DrivingLicenseType\CreateDrivingLicenseTypeRequest;
use App\Http\Requests\DrivingLicenseType\UpdateDrivingLicenseTypeRequest;
use App\Http\Resources\DrivingLicenseTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DrivingLicenseTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:driving-license-types.view')->only(['index','show']);
        $this->middleware('permission:driving-license-types.create')->only(['store']);
        $this->middleware('permission:driving-license-types.edit')->only(['update']);
        $this->middleware('permission:driving-license-types.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = DrivingLicenseType::query();
        if ($request->filled('search')) {
            $q->where('name','like','%'.$request->search.'%');
        }
        return DrivingLicenseTypeResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateDrivingLicenseTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $type = DrivingLicenseType::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'License type created',
            'data'=>['type'=>new DrivingLicenseTypeResource($type)]
        ],201);
    }

    public function show(DrivingLicenseType $drivingLicenseType): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['type'=>new DrivingLicenseTypeResource($drivingLicenseType)]
        ]);
    }

    public function update(UpdateDrivingLicenseTypeRequest $request, DrivingLicenseType $drivingLicenseType): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $drivingLicenseType->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'License type updated',
            'data'=>['type'=>new DrivingLicenseTypeResource($drivingLicenseType)]
        ]);
    }

    public function destroy(DrivingLicenseType $drivingLicenseType): JsonResponse
    {
        $drivingLicenseType->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'License type deleted'
        ]);
    }
}
