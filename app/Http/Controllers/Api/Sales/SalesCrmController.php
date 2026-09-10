<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesActivity;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesTask;
use App\Models\Booking\Booking;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesCrmService;
use App\Services\Sales\SalesPolicySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\Inquiry;
use App\Models\PhoneCall;

class SalesCrmController extends Controller
{
    public function __construct(
        private readonly SalesAccessScope $scope,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function opportunities(Request $request): JsonResponse
    {
        $data = $request->validate(['stage' => ['nullable', Rule::in(['new', 'contacted', 'qualified', 'quotation', 'negotiation', 'won', 'lost'])], 'company_id' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $query = SalesOpportunity::query()->with(['owner.staff:id,code']);
        if ($ids !== null) $query->whereIn('owner_sales_profile_id', $ids);
        $rows = $query
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['stage'] ?? null, fn ($q, $stage) => $q->where('stage', $stage))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where(function ($match) use ($search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $match->where('opportunity_number', 'like', $term)
                    ->orWhere('name', 'like', $term)->orWhere('prospect_name', 'like', $term);
            }))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->whereDate('expected_close_date', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->whereDate('expected_close_date', '<=', $to))
            ->latest()->paginate($request->integer('per_page', 25));
        $rows->setCollection($rows->getCollection()->map(fn ($row) => $this->opportunityPayload($row)));
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function showOpportunity(Request $request, SalesOpportunity $opportunity): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity);
        $opportunity->load(['owner.staff:id,code', 'stageEvents']);
        return response()->json(['status' => 'success', 'data' => $this->opportunityPayload($opportunity, true)]);
    }

    public function administrationContext(Request $request): JsonResponse
    {
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $profiles = SalesProfile::query()->with('staff:id,code,user_id')->activeAt(now())
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('sales_code')->get()->map(fn ($row) => [
                'id' => $row->id, 'company_id' => $row->company_id, 'sales_code' => $row->sales_code,
                'staff_code' => $row->staff?->code,
            ])->values();
        $companyIds = $profiles->pluck('company_id')->unique()->values();
        $sourceUserIds = $profiles->pluck('staff.user_id')->filter()->unique()->values();
        $inquiries = DB::table('inquiries as inquiry')->leftJoin('sales_opportunities as opportunity', 'opportunity.inquiry_id', '=', 'inquiry.id')
            ->whereNull('inquiry.deleted_at')->whereNull('opportunity.id')
            ->when($ids !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->whereIn('inquiry.assigned_to', $sourceUserIds)->orWhereIn('inquiry.created_user_id', $sourceUserIds)))
            ->select(['inquiry.id', 'inquiry.inquiry_number', 'inquiry.name', 'inquiry.email', 'inquiry.phone', 'inquiry.subject', 'inquiry.source', 'inquiry.created_at'])
            ->orderByDesc('inquiry.created_at')->limit(100)->get();
        $phoneCalls = DB::table('phone_calls as phone')->leftJoin('sales_opportunities as opportunity', 'opportunity.source_phone_call_id', '=', 'phone.id')
            ->whereNull('phone.deleted_at')->whereNull('opportunity.id')
            ->when($ids !== null, fn ($query) => $query->whereIn('phone.created_user_id', $sourceUserIds))
            ->select(['phone.id', 'phone.client_name', 'phone.phone', 'phone.summary', 'phone.call_time'])
            ->orderByDesc('phone.call_time')->limit(100)->get();

        return response()->json(['status' => 'success', 'data' => [
            'profiles' => $profiles,
            'inquiries' => $inquiries, 'phone_calls' => $phoneCalls,
            'crm_enabled_by_company' => $companyIds->mapWithKeys(fn ($companyId) => [
                $companyId => $this->policySettings->featureEnabled((string) $companyId, 'crm'),
            ]),
        ]]);
    }

    public function linkableBookingOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opportunity_id' => ['required', 'uuid', 'exists:sales_opportunities,id'],
            'search' => ['nullable', 'string', 'max:100'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $opportunity = SalesOpportunity::query()->findOrFail($data['opportunity_id']);
        $this->assertOpportunityScope($request, $opportunity, true);
        $rows = $this->linkableBookingQuery($opportunity)
            ->when($data['selected_id'] ?? null, fn ($query, $id) => $query->where('booking.id', $id))
            ->when(empty($data['selected_id']) && ! empty($data['search']), function ($query) use ($data) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($data['search'])).'%';
                $query->where(fn ($match) => $match->where('booking.booking_number', 'like', $term)->orWhere('booking.log_code', 'like', $term));
            })
            ->orderByDesc('booking.created_at')->paginate($request->integer('per_page', 25));
        $rows->getCollection()->transform(fn ($booking) => [
            'value' => (string) $booking->id,
            'label' => $booking->booking_number ?: ($booking->log_code ? 'Draft '.$booking->log_code : 'Draft booking '.substr((string) $booking->created_at, 0, 10)),
            'metadata' => ['log_code' => $booking->log_code, 'created_at' => $booking->created_at],
            'status' => 'draft',
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function createOpportunity(Request $request, SalesCrmService $crm): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'], 'inquiry_id' => ['nullable', 'uuid', 'exists:inquiries,id'],
            'source_phone_call_id' => ['nullable', 'uuid', 'exists:phone_calls,id'], 'name' => ['required', 'string', 'max:255'],
            'prospect_name' => ['nullable', 'string', 'max:255'], 'prospect_company' => ['nullable', 'string', 'max:255'],
            'prospect_email' => ['nullable', 'email', 'max:255'], 'prospect_phone' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'required_without_all:inquiry_id,source_phone_call_id', 'string', 'max:80'], 'campaign' => ['nullable', 'string', 'max:160'], 'referral' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'], 'expected_value_source' => ['required', 'numeric', 'min:0'],
            'source_currency' => ['required', 'string', 'size:3'], 'expected_value_lkr' => ['nullable', 'required_if:source_currency,LKR', 'numeric', 'min:0'],
            'probability_percent' => ['required', 'integer', 'min:0', 'max:100'], 'expected_close_date' => ['nullable', 'date'],
            'services' => ['nullable', 'array'], 'customer_needs' => ['nullable', 'string', 'max:5000'],
            'competitor_notes' => ['nullable', 'string', 'max:5000'], 'next_action' => ['nullable', 'string', 'max:2000'],
            'next_action_at' => ['nullable', 'date'], 'confidentiality' => ['nullable', Rule::in(['owner', 'team', 'management'])],
        ]);
        $profile = SalesProfile::query()->findOrFail($data['owner_sales_profile_id']);
        abort_unless(SalesProfile::query()->whereKey($profile->id)->activeAt(now())->exists(), 422, 'Opportunity owner must have an active Sales Profile.');
        $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $this->assertSourceScope($request, $data['inquiry_id'] ?? null, $data['source_phone_call_id'] ?? null);
        return response()->json(['status' => 'success', 'data' => $crm->createOpportunity($data, (string) $request->user()->id)], 201);
    }

    public function transitionOpportunity(Request $request, SalesOpportunity $opportunity, SalesCrmService $crm): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity, true);
        $data = $request->validate(['to_stage' => ['required', Rule::in(['contacted', 'qualified', 'quotation', 'negotiation', 'lost'])], 'expected_version' => ['required', 'integer', 'min:1'], 'reason_code' => ['nullable', 'string', 'max:80'], 'reason' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        return response()->json(['status' => 'success', 'data' => $crm->transition($opportunity, $data['to_stage'], $data['expected_version'], $data['reason_code'] ?? null, $data['reason'] ?? null, $data['idempotency_key'], (string) $request->user()->id)]);
    }

    public function transferOpportunity(Request $request, SalesOpportunity $opportunity, SalesCrmService $crm): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity, true);
        $data = $request->validate(['owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $owner = SalesProfile::query()->findOrFail($data['owner_sales_profile_id']);
        abort_unless(SalesProfile::query()->whereKey($owner->id)->activeAt(now())->exists(), 422, 'The new opportunity owner must have an active Sales Profile.');
        $this->scope->assertProfile($request->user(), $owner, 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $crm->transfer($opportunity, $owner, $data['expected_version'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id)]);
    }

    public function linkBooking(Request $request, SalesOpportunity $opportunity, SalesCrmService $crm): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity, true);
        $data = $request->validate(['booking_id' => ['required', 'uuid', 'exists:bookings,id'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $bookingId = $this->linkableBookingQuery($opportunity)->where('booking.id', $data['booking_id'])->value('booking.id');
        abort_unless($bookingId, 422, 'The selected draft booking is no longer eligible for this opportunity.');
        return response()->json(['status' => 'success', 'data' => $crm->linkBooking($opportunity, Booking::query()->findOrFail($bookingId), $data['idempotency_key'], (string) $request->user()->id)]);
    }

    private function linkableBookingQuery(SalesOpportunity $opportunity)
    {
        $ownerStaffId = SalesProfile::query()->whereKey($opportunity->owner_sales_profile_id)->activeAt(now())->value('staff_id');
        abort_unless($ownerStaffId, 422, 'Opportunity owner must have an active Sales Profile.');
        return DB::table('bookings as booking')
            ->leftJoin('staff as owner_staff', 'owner_staff.id', '=', 'booking.commission_owner_staff_id')
            ->leftJoin('staff as creator_staff', 'creator_staff.user_id', '=', 'booking.created_user_id')
            ->whereNull('booking.deleted_at')->whereNull('booking.sales_opportunity_id')
            ->where(fn ($query) => $query->whereNull('booking.confirmed')->orWhere('booking.confirmed', false))
            ->whereNull('booking.confirmed_at')->where(fn ($query) => $query->whereNull('booking.status')->orWhere('booking.status', '!=', 'confirmed'))
            ->whereRaw('COALESCE(owner_staff.company_id, creator_staff.company_id) = ?', [$opportunity->company_id])
            ->whereRaw('COALESCE(owner_staff.id, creator_staff.id) = ?', [$ownerStaffId])
            ->when($opportunity->customer_id, fn ($query, $customerId) => $query->where(fn ($customer) => $customer->whereNull('booking.customer_id')->orWhere('booking.customer_id', $customerId)))
            ->select(['booking.id', 'booking.booking_number', 'booking.log_code', 'booking.created_at']);
    }

    public function activities(Request $request): JsonResponse
    {
        $data = $request->validate(['sales_profile_id' => ['nullable', 'uuid'], 'opportunity_id' => ['nullable', 'uuid'], 'activity_type' => ['nullable', Rule::in(['call', 'email', 'sms', 'whatsapp', 'meeting', 'site_visit', 'note', 'quotation', 'follow_up', 'collection_follow_up'])], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $query = SalesActivity::query(); if ($ids !== null) $query->whereIn('sales_profile_id', $ids);
        return response()->json(['status' => 'success', 'data' => $query->when($data['sales_profile_id'] ?? null, fn ($q, $id) => $q->where('sales_profile_id', $id))->when($data['opportunity_id'] ?? null, fn ($q, $id) => $q->where('opportunity_id', $id))->when($data['activity_type'] ?? null, fn ($q, $type) => $q->where('activity_type', $type))->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date))->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date))->latest('occurred_at')->paginate($request->integer('per_page', 25))]);
    }

    public function recordActivity(Request $request, SalesCrmService $crm): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'opportunity_id' => ['nullable', 'uuid', 'exists:sales_opportunities,id'], 'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'inquiry_id' => ['nullable', 'uuid', 'exists:inquiries,id'], 'booking_id' => ['nullable', 'uuid', 'exists:bookings,id'],
            'phone_call_id' => ['nullable', 'uuid', 'exists:phone_calls,id'], 'booking_activity_id' => ['nullable', 'uuid', 'exists:booking_activities,id'],
            'activity_type' => ['required', Rule::in(['call', 'email', 'sms', 'whatsapp', 'meeting', 'site_visit', 'note', 'quotation', 'follow_up', 'collection_follow_up'])],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])], 'subject' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:80'], 'next_action' => ['nullable', 'string', 'max:2000'], 'next_action_at' => ['nullable', 'date'],
            'source_system' => ['nullable', 'string', 'max:60'], 'source_reference' => ['nullable', 'string', 'max:160'],
            'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'], 'occurred_at' => ['required', 'date', 'before_or_equal:now'],
        ]);
        $profile = SalesProfile::query()->findOrFail($data['sales_profile_id']);
        $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $crm->recordActivity($data, (string) $request->user()->id)], 201);
    }

    public function tasks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sales_profile_id' => ['nullable', 'uuid'], 'opportunity_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $query = SalesTask::query(); if ($ids !== null) $query->whereIn('owner_sales_profile_id', $ids);
        return response()->json(['status' => 'success', 'data' => $query
            ->when($data['sales_profile_id'] ?? null, fn ($q, $id) => $q->where('owner_sales_profile_id', $id))
            ->when($data['opportunity_id'] ?? null, fn ($q, $id) => $q->where('opportunity_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('due_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('due_at', '<=', $date))
            ->orderByRaw("CASE WHEN status IN ('completed','cancelled') THEN 1 ELSE 0 END")
            ->orderBy('due_at')->paginate($request->integer('per_page', 25))]);
    }

    public function createTask(Request $request, SalesCrmService $crm): JsonResponse
    {
        $explicitInstant = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';
        $data = $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id'], 'owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'], 'opportunity_id' => ['nullable', 'uuid', 'exists:sales_opportunities,id'], 'customer_id' => ['nullable', 'uuid', 'exists:customers,id'], 'inquiry_id' => ['nullable', 'uuid', 'exists:inquiries,id'], 'booking_id' => ['nullable', 'uuid', 'exists:bookings,id'], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'due_at' => ['required', 'date', $explicitInstant], 'remind_at' => ['nullable', 'date', $explicitInstant, 'before_or_equal:due_at'], 'escalate_at' => ['nullable', 'date', $explicitInstant, 'after_or_equal:due_at']]);
        $profile = SalesProfile::query()->findOrFail($data['owner_sales_profile_id']); $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $crm->createTask($data, (string) $request->user()->id)], 201);
    }

    public function transitionTask(Request $request, SalesTask $task, SalesCrmService $crm): JsonResponse
    {
        $profile = SalesProfile::query()->findOrFail($task->owner_sales_profile_id); $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $data = $request->validate(['to_status' => ['required', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        return response()->json(['status' => 'success', 'data' => $crm->transitionTask($task, $data, (string) $request->user()->id)]);
    }

    public function transferTask(Request $request, SalesTask $task, SalesCrmService $crm): JsonResponse
    {
        $current = SalesProfile::query()->findOrFail($task->owner_sales_profile_id);
        $this->scope->assertProfile($request->user(), $current, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $data = $request->validate(['owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $newOwner = SalesProfile::query()->findOrFail($data['owner_sales_profile_id']);
        $this->scope->assertProfile($request->user(), $newOwner, 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $crm->transferTask($task, $newOwner, $data['expected_version'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id)]);
    }

    public function forecast(Request $request): JsonResponse
    {
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $query = SalesOpportunity::query()->whereNotIn('stage', ['won', 'lost']); if ($ids !== null) $query->whereIn('owner_sales_profile_id', $ids);
        $data = $query->selectRaw('stage, COUNT(*) opportunity_count, SUM(expected_value_lkr) expected_lkr, SUM(expected_value_lkr * probability_percent / 100) weighted_forecast_lkr')->groupBy('stage')->get();
        return response()->json(['status' => 'success', 'data' => $data, 'meta' => ['accounting_or_kpi_fact' => false]]);
    }

    private function assertOpportunityScope(Request $request, SalesOpportunity $opportunity, bool $manage = false): void
    {
        $profile = SalesProfile::query()->findOrFail($opportunity->owner_sales_profile_id);
        $this->scope->assertProfile($request->user(), $profile, $manage ? 'sales.crm.manage-all' : 'sales.crm.view-all', $manage ? 'sales.crm.manage-team' : 'sales.crm.view-team');
    }

    private function opportunityPayload(SalesOpportunity $row, bool $detail = false): array
    {
        $payload = [
            'id' => $row->id, 'company_id' => $row->company_id,
            'owner_sales_profile_id' => $row->owner_sales_profile_id,
            'owner' => $row->owner ? ['sales_code' => $row->owner->sales_code, 'staff_code' => $row->owner->staff?->code] : null,
            'customer_id' => $row->customer_id, 'opportunity_number' => $row->opportunity_number,
            'name' => $row->name, 'prospect_name' => $row->prospect_name,
            'stage' => $row->stage, 'expected_value_source' => $row->expected_value_source,
            'source_currency' => $row->source_currency, 'expected_value_lkr' => $row->expected_value_lkr,
            'probability_percent' => $row->probability_percent, 'expected_close_date' => $row->expected_close_date,
            'next_action' => $row->next_action, 'next_action_at' => $row->next_action_at,
            'lost_reason_code' => $row->lost_reason_code, 'state_version' => $row->state_version,
        ];
        if (! $detail) return $payload;
        return $payload + [
            'prospect_company' => $row->prospect_company, 'prospect_email' => $row->prospect_email,
            'prospect_phone' => $row->prospect_phone, 'source' => $row->source,
            'campaign' => $row->campaign, 'referral' => $row->referral, 'description' => $row->description,
            'services' => $row->services, 'customer_needs' => $row->customer_needs,
            'competitor_notes' => $row->competitor_notes, 'confidentiality' => $row->confidentiality,
            'lost_reason' => $row->lost_reason, 'won_booking_id' => $row->won_booking_id,
            'won_booking' => $row->won_booking_id ? DB::table('bookings')->where('id', $row->won_booking_id)->first(['id', 'booking_number']) : null,
            'won_at' => $row->won_at, 'closed_at' => $row->closed_at,
            'stage_events' => $row->stageEvents->sortByDesc('occurred_at')->map(fn ($event) => [
                'id' => $event->id, 'from_stage' => $event->from_stage, 'to_stage' => $event->to_stage,
                'from_owner_sales_profile_id' => $event->from_owner_sales_profile_id,
                'to_owner_sales_profile_id' => $event->to_owner_sales_profile_id,
                'reason_code' => $event->reason_code, 'reason' => $event->reason, 'occurred_at' => $event->occurred_at,
            ])->values(),
        ];
    }

    private function assertSourceScope(Request $request, ?string $inquiryId, ?string $phoneCallId): void
    {
        if (! $inquiryId && ! $phoneCallId) return;
        if ($request->user()->can('sales.crm.manage-all')) return;
        $profileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        $userIds = DB::table('sales_profiles as profile')->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->whereIn('profile.id', $profileIds ?? [])->pluck('staff.user_id')->filter()->unique();
        if ($inquiryId) {
            abort_unless(Inquiry::query()->whereKey($inquiryId)->where(fn ($query) => $query
                ->whereIn('assigned_to', $userIds)->orWhereIn('created_user_id', $userIds))->exists(),
                403, 'The Inquiry source is outside your authorised Sales scope.');
        }
        if ($phoneCallId) {
            abort_unless(PhoneCall::query()->whereKey($phoneCallId)->whereIn('created_user_id', $userIds)->exists(),
                403, 'The Phone Call source is outside your authorised Sales scope.');
        }
    }
}
