<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inquiry\InquiryServicePageSectionResource;
use App\Http\Requests\StoreInquiryServicePageSectionRequest;
use App\Http\Requests\UpdateInquiryServicePageSectionRequest;
use App\Models\InquiryServicePage;
use App\Models\InquiryServicePageSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InquiryServicePageSectionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:inquiry-service-pages.view')->only(['index', 'show']);
        $this->middleware('permission:inquiry-service-pages.create')->only(['store']);
        $this->middleware('permission:inquiry-service-pages.edit')->only(['update', 'reorder']);
        $this->middleware('permission:inquiry-service-pages.delete')->only(['destroy']);
    }

    public function index(InquiryServicePage $inquiry_service_page)
    {
        $sections = $inquiry_service_page->sections()->orderBy('sort_order')->get();

        return InquiryServicePageSectionResource::collection($sections);
    }

    public function store(StoreInquiryServicePageSectionRequest $request, InquiryServicePage $inquiry_service_page): JsonResponse
    {
        $payload = $request->validated();
        $payload['sort_order'] = $payload['sort_order'] ?? ($inquiry_service_page->sections()->count() + 1);

        $section = $inquiry_service_page->sections()->create($payload);

        // Clear cached page sections so website shows latest
        try {
            \Illuminate\Support\Facades\Cache::forget('inquiry_service_page:' . $inquiry_service_page->slug);
        } catch (\Exception $e) {
            \Log::warning('Failed to clear inquiry page cache after section create: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Section created',
            'data' => ['section' => new InquiryServicePageSectionResource($section->refresh())],
        ], 201);
    }

    public function show(InquiryServicePage $inquiry_service_page, InquiryServicePageSection $section): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['section' => new InquiryServicePageSectionResource($section)],
        ]);
    }

    public function update(UpdateInquiryServicePageSectionRequest $request, InquiryServicePage $inquiry_service_page, InquiryServicePageSection $section): JsonResponse
    {
        $payload = $request->validated();
        $section->update($payload);

        // Clear cache
        try {
            \Illuminate\Support\Facades\Cache::forget('inquiry_service_page:' . $inquiry_service_page->slug);
        } catch (\Exception $e) {
            \Log::warning('Failed to clear inquiry page cache after section update: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Section updated',
            'data' => ['section' => new InquiryServicePageSectionResource($section->refresh())],
        ]);
    }

    public function destroy(InquiryServicePage $inquiry_service_page, InquiryServicePageSection $section): JsonResponse
    {
        $section->delete();

        // Clear cache
        try {
            \Illuminate\Support\Facades\Cache::forget('inquiry_service_page:' . $inquiry_service_page->slug);
        } catch (\Exception $e) {
            \Log::warning('Failed to clear inquiry page cache after section delete: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Section deleted',
        ]);
    }

    public function reorder(Request $request, InquiryServicePage $inquiry_service_page): JsonResponse
    {
        $payload = $request->validate(['ordered_ids' => ['required', 'array']]);
        $ids = $payload['ordered_ids'];

        foreach ($ids as $index => $id) {
            $section = $inquiry_service_page->sections()->where('id', $id)->first();
            if ($section) {
                $section->update(['sort_order' => $index + 1]);
            }
        }

        // Clear cache
        try {
            \Illuminate\Support\Facades\Cache::forget('inquiry_service_page:' . $inquiry_service_page->slug);
        } catch (\Exception $e) {
            \Log::warning('Failed to clear inquiry page cache after sections reorder: ' . $e->getMessage());
        }

        return response()->json(['status' => 'success', 'message' => 'Sections reordered']);
    }
}
