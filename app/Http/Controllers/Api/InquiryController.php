<?php
// app/Http/Controllers/Api/InquiryController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\InquiryForm;
use App\Models\Website\CmsContent;
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
        $this->middleware('permission:inquiries.view')->only(['index', 'show', 'filterOptions']);
        $this->middleware('permission:inquiries.create')->only(['store']);
        $this->middleware('permission:inquiries.edit')->only(['update', 'assign', 'updateStatus', 'respond', 'markRead']);
        $this->middleware('permission:inquiries.delete')->only(['destroy']);
    }

    public function filterOptions(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => [
            'services' => CmsContent::withInactive()->withTrashed()
                ->whereHas('inquiries')->orderBy('title')->get(['id', 'title']),
            'forms' => InquiryForm::withInactive()->withTrashed()
                ->whereHas('cmsServices')->orderBy('name')->get(['id', 'name']),
            'workflows' => Inquiry::distinct()->whereNotNull('inquiry_type')->pluck('inquiry_type'),
            'statuses' => Inquiry::distinct()->whereNotNull('status')->pluck('status'),
            'assignees' => Inquiry::distinct()->whereNotNull('assigned_to')->pluck('assigned_to'),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'service_id' => ['nullable', 'uuid'],
            'form_id' => ['nullable', 'uuid'],
            'workflow' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'assigned_to' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $q = Inquiry::with(['cmsContent', 'inquiryServicePage']);
        if ($request->filled('search')) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($query) => $query->where('subject', 'like', $term)
                ->orWhere('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('inquiry_number', 'like', $term));
        }
        foreach (['service_id' => 'cms_content_id', 'workflow' => 'inquiry_type', 'status' => 'status', 'assigned_to' => 'assigned_to'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $q->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['form_id'])) {
            $q->where('payload->form_id', $filters['form_id']);
        }
        if (! empty($filters['date_from'])) {
            $q->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->whereDate('created_at', '<=', $filters['date_to']);
        }

        return InquiryResource::collection($q->latest()->paginate($filters['per_page'] ?? 15));
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
        $inquiry->load(['cmsContent', 'inquiryServicePage']);
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
            'assigned_to' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $inquiry->update([
            'assigned_to' => $data['assigned_to'],
            'status' => $inquiry->status ?: 'open',
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
            'status' => ['required', 'in:open,in_progress,closed,archived'],
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
            'status' => ['sometimes', 'nullable', 'in:open,in_progress,closed,archived'],
        ]);

        $inquiry->update([
            'response' => $data['message'],
            'status' => $data['status'] ?? 'in_progress',
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
