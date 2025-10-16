<?php
// app/Http/Controllers/Api/DriverLogController.php
namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Driver\DriverLog;
use App\Http\Requests\DriverLog\CreateDriverLogRequest;
use App\Http\Requests\DriverLog\UpdateDriverLogRequest;
use App\Http\Resources\DriverLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DriverLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:driver-logs.view')->only(['index','show']);
        $this->middleware('permission:driver-logs.create')->only(['store']);
        $this->middleware('permission:driver-logs.edit')->only(['update']);
        $this->middleware('permission:driver-logs.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = DriverLog::with('driver');
        return DriverLogResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateDriverLogRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $log = DriverLog::create($data);
        return response()->json([
            'status'=>'success',
            'message'=>'Driver log created',
            'data'=>['log'=>new DriverLogResource($log)]
        ],201);
    }

    public function show(DriverLog $driverLog): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['log'=>new DriverLogResource($driverLog)]
        ]);
    }

    public function update(UpdateDriverLogRequest $request, DriverLog $driverLog): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $driverLog->update($data);
        return response()->json([
            'status'=>'success',
            'message'=>'Driver log updated',
            'data'=>['log'=>new DriverLogResource($driverLog)]
        ]);
    }

    public function destroy(DriverLog $driverLog): JsonResponse
    {
        $driverLog->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Driver log deleted'
        ]);
    }
}
