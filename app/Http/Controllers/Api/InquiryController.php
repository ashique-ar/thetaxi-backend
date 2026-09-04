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
        $this->middleware('permission:inquiries.edit')->only(['update', 'assign', 'updateStatus', 'respond', 'markRead']);
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
        $inq = Inquiry::createWithUniqueNumber($data);

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

    public function assign(Request $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'string', 'max:255'],
        ]);

        $inquiry->update([
            'assigned_to' => $data['assigned_to'],
            'status' => $inquiry->status ?: 'assigned',
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry assigned',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    public function updateStatus(Request $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $inquiry->update([
            'status' => $data['status'],
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry status updated',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    public function respond(Request $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $inquiry->update([
            'response' => $data['message'],
            'status' => $data['status'] ?? 'responded',
            'responded_at' => now(),
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry response saved',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    public function markRead(Request $request, Inquiry $inquiry): JsonResponse
    {
        $inquiry->update([
            'status' => $inquiry->status ?: 'read',
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry marked as read',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }
}
