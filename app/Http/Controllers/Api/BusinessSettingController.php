<?php
// app/Http/Controllers/Api/BusinessSettingController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Http\Requests\BusinessSetting\CreateBusinessSettingRequest;
use App\Http\Requests\BusinessSetting\UpdateBusinessSettingRequest;
use App\Http\Resources\BusinessSettingResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BusinessSettingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:business-settings.view')->only(['index','show']);
        $this->middleware('permission:business-settings.create')->only(['store']);
        $this->middleware('permission:business-settings.edit')->only(['update']);
        $this->middleware('permission:business-settings.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = BusinessSetting::query();
        if ($request->filled('search')) {
            $q->where('type','like','%'.$request->search.'%');
        }
        return BusinessSettingResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateBusinessSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $setting = BusinessSetting::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Business setting created',
            'data'=>['business_setting'=>new BusinessSettingResource($setting)]
        ], 201);
    }

    public function show(BusinessSetting $businessSetting): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['business_setting'=>new BusinessSettingResource($businessSetting)]
        ]);
    }

    public function update(UpdateBusinessSettingRequest $request, BusinessSetting $businessSetting): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $businessSetting->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Business setting updated',
            'data'=>['business_setting'=>new BusinessSettingResource($businessSetting)]
        ]);
    }

    public function destroy(BusinessSetting $businessSetting): JsonResponse
    {
        $businessSetting->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Business setting deleted'
        ]);
    }
}
