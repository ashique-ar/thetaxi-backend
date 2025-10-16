<?php
// app/Http/Controllers/Api/Website/CmsContentTypeController.php
namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContentType;
use App\Http\Requests\Website\CmsContentType\CreateCmsContentTypeRequest;
use App\Http\Requests\Website\CmsContentType\UpdateCmsContentTypeRequest;
use App\Http\Resources\Website\CmsContentTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CmsContentTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:cms-content-types.view')->only(['index', 'show']);
        $this->middleware('permission:cms-content-types.create')->only(['store']);
        $this->middleware('permission:cms-content-types.edit')->only(['update']);
        $this->middleware('permission:cms-content-types.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = CmsContentType::query();
        if ($request->filled('search')) {
            $q->where('title', 'like', '%' . $request->search . '%');
        }
        return CmsContentTypeResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateCmsContentTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $type = CmsContentType::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Content type created',
            'data' => ['type' => new CmsContentTypeResource($type)]
        ], 201);
    }

    public function show(CmsContentType $cms_content_type): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['type' => new CmsContentTypeResource($cms_content_type)]
        ]);
    }

    public function update(UpdateCmsContentTypeRequest $request, CmsContentType $cms_content_type): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $cms_content_type->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Content type updated',
            'data' => ['type' => new CmsContentTypeResource($cms_content_type)]
        ]);
    }

    public function destroy(CmsContentType $cms_content_type): JsonResponse
    {
        $cms_content_type->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Content type deleted'
        ]);
    }
}
