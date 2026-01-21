<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inquiry\InquiryServicePageResource;
use App\Models\InquiryServicePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InquiryServicePageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = InquiryServicePage::withInactive()->with(['form', 'serviceType']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('slug', 'like', '%' . $request->search . '%')
                    ->orWhere('code', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return InquiryServicePageResource::collection(
            $query->orderBy('sort_order')->paginate($request->per_page ?? 15)
        );
    }

    public function show(InquiryServicePage $inquiry_service_page): JsonResponse
    {
        $inquiry_service_page->load(['form.fields', 'serviceType']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'page' => new InquiryServicePageResource($inquiry_service_page),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePagePayload($request);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }
        if (empty($data['code'])) {
            $data['code'] = $data['slug'];
        }

        $data['created_user_id'] = $request->user()?->id;
        $page = InquiryServicePage::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry service page created',
            'data' => [
                'page' => new InquiryServicePageResource($page->load(['form', 'serviceType'])),
            ],
        ], 201);
    }

    public function update(Request $request, InquiryServicePage $inquiry_service_page): JsonResponse
    {
        $data = $this->validatePagePayload($request, $inquiry_service_page->id);

        if (array_key_exists('name', $data) && empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }
        if (array_key_exists('slug', $data) && empty($data['code'])) {
            $data['code'] = $data['slug'];
        }

        $data['updated_user_id'] = $request->user()?->id;
        $inquiry_service_page->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry service page updated',
            'data' => [
                'page' => new InquiryServicePageResource($inquiry_service_page->load(['form', 'serviceType'])),
            ],
        ]);
    }

    public function destroy(InquiryServicePage $inquiry_service_page): JsonResponse
    {
        $inquiry_service_page->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry service page deleted',
        ]);
    }

    /**
     * Validate request payload for page create/update.
     */
    protected function validatePagePayload(Request $request, ?string $pageId = null): array
    {
        $slugRule = Rule::unique('inquiry_service_pages', 'slug');
        $codeRule = Rule::unique('inquiry_service_pages', 'code');

        if ($pageId) {
            $slugRule = $slugRule->ignore($pageId);
            $codeRule = $codeRule->ignore($pageId);
        }

        return $request->validate([
            'name' => [$pageId ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', $slugRule],
            'code' => ['nullable', 'string', 'max:255', $codeRule],
            'inquiry_type' => ['nullable', 'string', 'max:100'],
            'service_type_id' => ['nullable', 'uuid', 'exists:service_types,id'],
            'inquiry_form_id' => ['nullable', 'uuid', 'exists:inquiry_forms,id'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'content' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'seo_og_image' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'seo_keywords' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
