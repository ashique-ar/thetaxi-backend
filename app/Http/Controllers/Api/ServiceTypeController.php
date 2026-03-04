<?php
// app/Http/Controllers/Api/ServiceTypeController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceType;
use App\Http\Requests\ServiceType\CreateServiceTypeRequest;
use App\Http\Requests\ServiceType\UpdateServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:service-types.view')->only(['index','show']);
        $this->middleware('permission:service-types.create')->only(['store']);
        $this->middleware('permission:service-types.edit')->only(['update']);
        $this->middleware('permission:service-types.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ServiceType::withInactive();
        if ($request->filled('search')) {
            $q->where('name','like','%'.$request->search.'%')
              ->orWhere('code','like','%'.$request->search.'%');
        }
        
        // Only apply is_active filter if explicitly set
        if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
            $q->where('is_active', $request->boolean('is_active'));
        }
        
        return ServiceTypeResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateServiceTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $svc = ServiceType::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Service type created',
            'data'=>['service_type'=>new ServiceTypeResource($svc)]
        ],201);
    }

    public function show(ServiceType $serviceType): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['service_type'=>new ServiceTypeResource($serviceType)]
        ]);
    }

    public function update(UpdateServiceTypeRequest $request, ServiceType $serviceType): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $serviceType->update($data);

        if (ServiceType::where('code', $serviceType->code)->where('id', '!=', $serviceType->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Service type code must be unique.',
            ], 422);
        }
        // Format the code to be lowercase and replace spaces with underscores
        if (!isset($data['code']) || $data['code'] !== $serviceType->code) {
            $serviceType->code = strtolower(str_replace(' ', '_', $serviceType->code));
        }   
        $serviceType->save();
        return response()->json([
            'status'=>'success',
            'message'=>'Service type updated',
            'data'=>['service_type'=>new ServiceTypeResource($serviceType)]
        ]);
    }

    // public function toggleStatus(Request $request, string $id): JsonResponse
    // {
    //     $serviceType = ServiceType::findOrFail($id);
    //     $serviceType->is_active = !$serviceType->is_active;
    //     $serviceType->save();

    //     return response()->json([
    //         'status'=>'success',
    //         'message'=>'Service type status updated',
    //         'data'=>['service_type'=>new ServiceTypeResource($serviceType)]
    //     ]);
    // }

    public function destroy(ServiceType $serviceType): JsonResponse
    {
        $serviceType->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Service type deleted'
        ]);
    }
}
