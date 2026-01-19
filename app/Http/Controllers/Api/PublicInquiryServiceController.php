<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inquiry\InquiryServicePageResource;
use App\Models\InquiryServicePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicInquiryServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = InquiryServicePage::query()
            ->where('status', 'published')
            ->orderBy('sort_order')
            ->with(['form.fields', 'serviceType']);

        return InquiryServicePageResource::collection(
            $query->paginate($request->per_page ?? 15)
        );
    }

    public function show(string $slug): JsonResponse
    {
        $page = InquiryServicePage::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->with(['form.fields', 'serviceType'])
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => [
                'page' => new InquiryServicePageResource($page),
            ],
        ]);
    }
}
