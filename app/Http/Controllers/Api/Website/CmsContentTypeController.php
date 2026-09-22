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
        $q = CmsContentType::withInactive()
            ->with(['createdBy', 'parent'])
            ->withCount(['contents', 'children']);
            
        if ($request->filled('search')) {
            $q->where(function ($query) use ($request) {
                $query->where('title', 'like', '%' . $request->search . '%')
                      ->orWhere('slug', 'like', '%' . $request->search . '%')
                      ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }
        
        // Only apply is_active filter if explicitly set to true or false
        if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
            $q->where('is_active', $request->boolean('is_active'));
        }

        if ($request->boolean('roots_only')) {
            $q->whereNull('parent_id');
        } elseif ($request->filled('parent_id')) {
            $request->parent_id === 'root' ? $q->whereNull('parent_id') : $q->where('parent_id', $request->parent_id);
        }
        
        $q->orderBy('display_order', 'asc')
          ->orderBy('title', 'asc');
          
        return CmsContentTypeResource::collection(
            $q->paginate(min(max((int) $request->integer('per_page', 15), 1), 100))
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
        $cms_content_type->load(['createdBy', 'updatedBy', 'parent', 'children'])->loadCount(['contents', 'children']);
        
        return response()->json([
            'status' => 'success',
            'data' => ['type' => new CmsContentTypeResource($cms_content_type)]
        ]);
    }

    public function update(UpdateCmsContentTypeRequest $request, CmsContentType $cms_content_type): JsonResponse
    {
        $data = $request->validated();
        if (!empty($data['parent_id']) && $cms_content_type->children()->exists()) {
            return response()->json(['message' => 'A content type with children cannot become a child.'], 422);
        }
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
        if ($cms_content_type->children()->exists()) {
            return response()->json(['message' => 'Move or delete child content types before deleting this parent.'], 422);
        }
        $cms_content_type->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Content type deleted'
        ]);
    }
}
