<?php
// app/Http/Controllers/Api/Website/WebsiteSettingController.php
namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\WebsiteSetting;
use App\Http\Requests\Website\WebsiteSetting\CreateWebsiteSettingRequest;
use App\Http\Requests\Website\WebsiteSetting\UpdateWebsiteSettingRequest;
use App\Http\Resources\Website\WebsiteSettingResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebsiteSettingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:website-settings.view')->only(['index', 'show']);
        $this->middleware('permission:website-settings.create')->only(['store']);
        $this->middleware('permission:website-settings.edit')->only(['update']);
        $this->middleware('permission:website-settings.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = WebsiteSetting::query();
        if ($request->filled('search')) {
            $q->where('type', 'like', '%' . $request->search . '%');
        }
        return WebsiteSettingResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateWebsiteSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $setting = WebsiteSetting::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Website setting created',
            'data' => ['setting' => new WebsiteSettingResource($setting)]
        ], 201);
    }

    public function show(WebsiteSetting $websiteSetting): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['setting' => new WebsiteSettingResource($websiteSetting)]
        ]);
    }

    public function update(UpdateWebsiteSettingRequest $request, WebsiteSetting $websiteSetting): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $websiteSetting->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Website setting updated',
            'data' => ['setting' => new WebsiteSettingResource($websiteSetting)]
        ]);
    }

    public function destroy(WebsiteSetting $websiteSetting): JsonResponse
    {
        $websiteSetting->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Website setting deleted'
        ]);
    }
}
