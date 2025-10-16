<?php
// app/Http/Controllers/Api/VipTypeController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VipType;
use App\Http\Requests\VipType\CreateVipTypeRequest;
use App\Http\Requests\VipType\UpdateVipTypeRequest;
use App\Http\Resources\VipTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VipTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vip-types.view')->only(['index', 'show']);
        $this->middleware('permission:vip-types.create')->only(['store']);
        $this->middleware('permission:vip-types.edit')->only(['update']);
        $this->middleware('permission:vip-types.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = VipType::query();
        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->search . '%');
        }
        return VipTypeResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVipTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $vip = VipType::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'VIP type created',
            'data' => ['vip_type' => new VipTypeResource($vip)]
        ], 201);
    }

    public function show(VipType $vipType): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['vip_type' => new VipTypeResource($vipType)]
        ]);
    }

    public function update(UpdateVipTypeRequest $request, VipType $vipType): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $vipType->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'VIP type updated',
            'data' => ['vip_type' => new VipTypeResource($vipType)]
        ]);
    }

    public function destroy(VipType $vipType): JsonResponse
    {
        $vipType->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'VIP type deleted'
        ]);
    }
}
