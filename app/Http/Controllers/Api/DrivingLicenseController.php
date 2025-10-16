<?php
// app/Http/Controllers/Api/DrivingLicenseController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrivingLicense;
use App\Http\Requests\DrivingLicense\CreateDrivingLicenseRequest;
use App\Http\Requests\DrivingLicense\UpdateDrivingLicenseRequest;
use App\Http\Resources\DrivingLicenseResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Log;

class DrivingLicenseController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:driving-licenses.view')->only(['index','show']);
        $this->middleware('permission:driving-licenses.create')->only(['store']);
        $this->middleware('permission:driving-licenses.edit')->only(['update']);
        $this->middleware('permission:driving-licenses.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = DrivingLicense::with(['user','licenseType']);
        if ($request->filled('search')) {
            $q->where('license_number','like','%'.$request->search.'%');
        }
        return DrivingLicenseResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateDrivingLicenseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $dl = DrivingLicense::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Driving license created',
            'data'=>['license'=>new DrivingLicenseResource($dl)]
        ],201);
    }

    public function show(DrivingLicense $drivingLicense): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['license'=>new DrivingLicenseResource($drivingLicense)]
        ]);
    }

    public function update(UpdateDrivingLicenseRequest $request, DrivingLicense $drivingLicense): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $drivingLicense->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Driving license updated',
            'data'=>['license'=>new DrivingLicenseResource($drivingLicense)]
        ]);
    }

    public function destroy(DrivingLicense $drivingLicense): JsonResponse
    {
        $drivingLicense->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Driving license deleted'
        ]);
    }
}
