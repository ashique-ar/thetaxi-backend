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
        $q = CmsContent::with(['contentType', 'createdBy', 'updatedBy']);

        if ($request->filled('search')) {
            $q->where(function ($query) use ($request) {
                $query->where('title', 'like', '%' . $request->search . '%')
                    ->orWhere('slug', 'like', '%' . $request->search . '%')
                    ->orWhere('author', 'like', '%' . $request->search . '%')
                    ->orWhere('excerpt', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('cms_content_type_id')) {
            $q->where('cms_content_type_id', $request->cms_content_type_id);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('is_featured')) {
            $q->where('is_featured', $request->boolean('is_featured'));
        }

        if ($request->filled('content_type_slug')) {
            $q->byType($request->content_type_slug);
        }

        $q->orderBy('display_order', 'asc')
            ->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc');

        return CmsContentResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateCmsContentRequest $request): JsonResponse
    {
        $data = $this->prepareContentData($request->validated());
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
        $cms_content->load(['contentType', 'createdBy', 'updatedBy']);

        return response()->json([
            'status' => 'success',
            'data' => ['content' => new CmsContentResource($cms_content)]
        ]);
    }

    public function update(UpdateCmsContentRequest $request, CmsContent $cms_content): JsonResponse
    {
        $data = $this->prepareContentData($request->validated(), $cms_content);
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

    /**
     * Get published content for public display
     */
    public function published(Request $request): AnonymousResourceCollection
    {
        $q = CmsContent::published()
            ->with(['contentType', 'createdBy']);

        if ($request->filled('content_type_slug')) {
            $q->byType($request->content_type_slug);
        }

        if ($request->filled('is_featured')) {
            $q->featured();
        }

        $q->orderBy('is_featured', 'desc')
            ->orderBy('published_at', 'desc');

        return CmsContentResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    /**
     * Get content by slug for public display
     */
    public function getBySlug(string $contentTypeSlug, string $contentSlug): JsonResponse
    {
        $content = CmsContent::published()
            ->with(['contentType', 'createdBy'])
            ->byType($contentTypeSlug)
            ->where('slug', $contentSlug)
            ->firstOrFail();

        // Increment view count
        $content->incrementViews();

        return response()->json([
            'status' => 'success',
            'data' => ['content' => new CmsContentResource($content)]
        ]);
    }

    /**
     * Normalize meta, publishing, availability and ETA data.
     */
    private function prepareContentData(array $data, ?CmsContent $existing = null): array
    {
        // Auto timestamp for published items
        if (($data['status'] ?? null) === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        // Merge custom_fields read_time into existing array
        $customFields = $existing?->custom_fields ?? [];
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            $customFields = array_merge($customFields, $data['custom_fields']);
        }

        // Allow manual read_time override; otherwise calculate from body
        if (!empty($data['read_time'])) {
            $customFields['read_time'] = $data['read_time'];
        } elseif (!empty($data['body'])) {
            $customFields['read_time'] = $this->calculateReadTime($data['body']);
        }

        if (!empty($customFields)) {
            $data['custom_fields'] = $customFields;
        }

        // Default availability
        if (!isset($data['availability_status']) && $existing?->availability_status) {
            $data['availability_status'] = $existing->availability_status;
        } elseif (!isset($data['availability_status'])) {
            $data['availability_status'] = 'available';
        }

        // Clean up helper-only fields
        unset($data['read_time']);

        return $data;
    }

    private function calculateReadTime(string $body): string
    {
        $text = strip_tags($body);
        $wordCount = str_word_count($text);
        $minutes = max(1, (int) ceil($wordCount / 200));
        return "{$minutes} min read";
    }
}
