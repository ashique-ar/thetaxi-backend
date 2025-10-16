<?php
// app/Http/Controllers/Api/Website/CmsContentController.php
namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Http\Requests\Website\CmsContent\CreateCmsContentRequest;
use App\Http\Requests\Website\CmsContent\UpdateCmsContentRequest;
use App\Http\Resources\Website\CmsContentResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CmsContentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:cms-contents.view')->only(['index', 'show']);
        $this->middleware('permission:cms-contents.create')->only(['store']);
        $this->middleware('permission:cms-contents.edit')->only(['update']);
        $this->middleware('permission:cms-contents.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = CmsContent::with('contentType');
        if ($request->filled('search')) {
            $q->where('title', 'like', '%' . $request->search . '%');
        }
        return CmsContentResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateCmsContentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $content = CmsContent::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Content created',
            'data' => ['content' => new CmsContentResource($content)]
        ], 201);
    }

    public function show(CmsContent $cms_content): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['content' => new CmsContentResource($cms_content)]
        ]);
    }

    public function update(UpdateCmsContentRequest $request, CmsContent $cms_content): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $cms_content->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Content updated',
            'data' => ['content' => new CmsContentResource($cms_content)]
        ]);
    }

    public function destroy(CmsContent $cms_content): JsonResponse
    {
        $cms_content->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Content deleted'
        ]);
    }
}
