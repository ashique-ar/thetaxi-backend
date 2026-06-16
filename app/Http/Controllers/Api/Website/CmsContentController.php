<?php
// app/Http/Controllers/Api/Website/CmsContentController.php
namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Http\Requests\Website\CmsContent\CreateCmsContentRequest;
use App\Http\Requests\Website\CmsContent\UpdateCmsContentRequest;
use App\Http\Resources\Website\CmsContentResource;
use App\Models\User;
use App\Models\Website\CmsContentType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CmsContentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:cms-contents.view')->only(['index', 'show', 'filterUsers', 'search']);
        $this->middleware('permission:cms-contents.create')->only(['store']);
        $this->middleware('permission:cms-contents.edit')->only(['update', 'publish', 'unpublish']);
        $this->middleware('permission:cms-contents.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = CmsContent::withInactive()->with(['contentType', 'createdBy', 'updatedBy']);
        $contentTable = $q->getModel()->getTable();

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

        if ($request->filled('created_user_id')) {
            $q->where('created_user_id', $request->created_user_id);
        }

        if ($request->filled('updated_user_id')) {
            $q->where('updated_user_id', $request->updated_user_id);
        }

        // Only apply is_active filter if explicitly set (not 'all')
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $q->where('is_active', $request->boolean('is_active'));
        }

        // Only apply is_featured filter if explicitly set (not 'all')
        if ($request->filled('is_featured') && $request->is_featured !== 'all') {
            $q->where('is_featured', $request->boolean('is_featured'));
        }

        if ($request->filled('content_type_slug')) {
            $q->byType($request->content_type_slug);
        }

        $sortBy = $request->string('sort_by')->toString();
        $sortDirection = strtolower($request->string('sort_direction', 'desc')->toString()) === 'asc' ? 'asc' : 'desc';

        switch ($sortBy) {
            case 'title':
            case 'status':
            case 'published_at':
            case 'views_count':
            case 'is_featured':
            case 'is_active':
            case 'created_at':
            case 'updated_at':
            case 'display_order':
                $q->orderBy("{$contentTable}.{$sortBy}", $sortDirection);
                break;
            case 'content_type':
                $q->orderBy(
                    CmsContentType::select('title')
                        ->whereColumn('cms_content_types.id', "{$contentTable}.cms_content_type_id")
                        ->limit(1),
                    $sortDirection
                );
                break;
            case 'created_by':
                $q->orderBy(
                    User::selectRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))")
                        ->whereColumn('users.id', "{$contentTable}.created_user_id")
                        ->limit(1),
                    $sortDirection
                );
                break;
            case 'updated_by':
                $q->orderBy(
                    User::selectRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))")
                        ->whereColumn('users.id', "{$contentTable}.updated_user_id")
                        ->limit(1),
                    $sortDirection
                );
                break;
            default:
                $q->orderBy("{$contentTable}.display_order", 'asc')
                    ->orderBy("{$contentTable}.published_at", 'desc')
                    ->orderBy("{$contentTable}.created_at", 'desc');
                break;
        }

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

    public function search(Request $request): AnonymousResourceCollection
    {
        $request->merge([
            'search' => $request->input('search', $request->input('q')),
            'content_type_slug' => $request->input('content_type_slug', $request->input('content_type')),
        ]);

        return $this->index($request);
    }

    public function publish(Request $request, CmsContent $cms_content): JsonResponse
    {
        $cms_content->update([
            'status' => 'published',
            'published_at' => $cms_content->published_at ?? now(),
            'is_active' => true,
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Content published',
            'data' => ['content' => new CmsContentResource($cms_content->fresh(['contentType', 'createdBy', 'updatedBy']))],
        ]);
    }

    public function unpublish(Request $request, CmsContent $cms_content): JsonResponse
    {
        $cms_content->update([
            'status' => 'draft',
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Content unpublished',
            'data' => ['content' => new CmsContentResource($cms_content->fresh(['contentType', 'createdBy', 'updatedBy']))],
        ]);
    }

    /**
     * Get users used in CMS contents filters.
     */
    public function filterUsers(): JsonResponse
    {
        $createdUserIds = CmsContent::query()
            ->whereNotNull('created_user_id')
            ->distinct()
            ->pluck('created_user_id')
            ->filter()
            ->values();

        $updatedUserIds = CmsContent::query()
            ->whereNotNull('updated_user_id')
            ->distinct()
            ->pluck('updated_user_id')
            ->filter()
            ->values();

        $createdUsers = User::query()
            ->whereIn('id', $createdUserIds)
            ->select('id', 'first_name', 'last_name', 'email')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? 'Unknown'),
                ];
            })
            ->values();

        $updatedUsers = User::query()
            ->whereIn('id', $updatedUserIds)
            ->select('id', 'first_name', 'last_name', 'email')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? 'Unknown'),
                ];
            })
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'created_users' => $createdUsers,
                'updated_users' => $updatedUsers,
            ],
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

        // Ensure min_days has a sensible default (1)
        if (!isset($data['min_days'])) {
            $data['min_days'] = $existing?->min_days ?? 1;
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
