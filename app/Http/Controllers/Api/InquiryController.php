<?php
// app/Http/Controllers/Api/InquiryController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\InquiryForm;
use App\Services\SingleCompanyScope;
use App\Models\Website\CmsContent;
use App\Http\Requests\Inquiry\CreateInquiryRequest;
use App\Http\Requests\Inquiry\UpdateInquiryRequest;
use App\Http\Resources\Inquiry\InquiryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class InquiryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:inquiries.view')->only(['index', 'show', 'filterOptions', 'assigneeOptions']);
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
        $q = Inquiry::with(['cmsContent', 'inquiryServicePage', 'assignedUser.staff']);
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
        $inquiry->load(['cmsContent', 'inquiryServicePage', 'assignedUser.staff']);
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
            'assigned_to' => ['required', 'uuid'],
        ]);
        $companyId = $this->assignmentCompany($request);
        DB::transaction(function () use ($inquiry, $data, $request, $companyId): void {
            abort_unless(app(SingleCompanyScope::class)->defaultCompany()?->id === $companyId, 409, 'Default company changed; retry assignment.');
            $locked = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->eligibleAssignees($companyId)->where('users.id', $data['assigned_to'])->lockForUpdate()->first(['users.id']), 422, 'Select an active Staff assignee.');
            $locked->update([
                'assigned_to' => $data['assigned_to'],
                'status' => $locked->status ?: 'open',
                'updated_user_id' => $request->user()->id,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry assigned',
            'data' => ['inquiry' => new InquiryResource($inquiry->fresh()->load('assignedUser.staff'))]
        ]);
    }

    public function assigneeOptions(Request $request): JsonResponse
    {
        $companyId = $this->assignmentCompany($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $this->eligibleAssignees($companyId);
        if (! empty($data['selected_id'])) {
            $query->where('users.id', $data['selected_id']);
        } elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($q) => $q->where('users.first_name', 'like', $term)
                ->orWhere('users.last_name', 'like', $term)
                ->orWhere('staff.code', 'like', $term));
        }
        $rows = $query->select(['users.id', 'users.first_name', 'users.last_name', 'staff.code'])
            ->orderBy('users.first_name')->orderBy('users.last_name')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($row) => [
            'value' => (string) $row->id,
            'label' => trim($row->first_name . ' ' . $row->last_name) . ' · ' . $row->code,
            'status' => 'active',
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    private function assignmentCompany(Request $request): string
    {
        $company = app(SingleCompanyScope::class)->defaultCompany();
        abort_unless($company, 409, 'Set one active default company before using Inquiry Staff options.');
        abort_unless($this->eligibleAssignees($company->id)->where('users.id', $request->user()->id)->exists(), 403);

        return $company->id;
    }

    private function eligibleAssignees(string $companyId)
    {
        return DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.company_id', $companyId)
            ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')
            ->whereNull('users.deleted_at')->where('users.is_active', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('hr_employment_spells')
                ->whereColumn('hr_employment_spells.staff_id', 'staff.id')
                ->whereColumn('hr_employment_spells.company_id', 'staff.company_id')
                ->where('hr_employment_spells.status', 'active')->whereNull('hr_employment_spells.terminated_at'));
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
