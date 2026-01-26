<?php
// app/Http/Controllers/Api/InquiryController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Http\Requests\Inquiry\CreateInquiryRequest;
use App\Http\Requests\Inquiry\UpdateInquiryRequest;
use App\Http\Resources\Inquiry\InquiryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InquiryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:inquiries.view')->only(['index', 'show']);
        $this->middleware('permission:inquiries.create')->only(['store']);
        $this->middleware('permission:inquiries.edit')->only(['update']);
        $this->middleware('permission:inquiries.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Inquiry::query();
        if ($request->filled('search')) {
            $q->where('subject', 'like', '%' . $request->search . '%');
        }
        return InquiryResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateInquiryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $inq = Inquiry::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry created',
            'data' => ['inquiry' => new InquiryResource($inq)]
        ], 201);
    }

    public function show(Inquiry $inquiry): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    public function update(UpdateInquiryRequest $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $inquiry->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry updated',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    public function destroy(Inquiry $inquiry): JsonResponse
    {
        $inquiry->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry deleted'
        ]);
    }
}
