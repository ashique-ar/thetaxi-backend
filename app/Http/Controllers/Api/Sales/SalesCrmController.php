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
use Illuminate\Support\Facades\Schema;

class SalesCrmController extends Controller
{
    public function __construct(
        private readonly SalesAccessScope $scope,
        private readonly SalesPolicySettingsService $policySettings,
        private readonly SalesCrmService $crm,
    ) {}

    public function opportunities(Request $request): JsonResponse
    {
        $data = $request->validate(['stage' => ['nullable', Rule::in(['new', 'contacted', 'qualified', 'quotation', 'negotiation', 'won', 'lost'])], 'search' => ['nullable', 'string', 'max:100'], 'needs_follow_up' => ['nullable', 'boolean'], 'needs_owner_review' => ['nullable', 'boolean'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        abort_unless(! ($data['needs_owner_review'] ?? false) || $ids === null, 403,
            'Sales-wide access is required to review opportunities with an inactive owner.');
        $activeOwner = fn () => $this->activeSalesOwnerExists('sales_opportunities.owner_sales_profile_id');
        $query = SalesOpportunity::query()->with(['owner.staff:id,code,user_id,employment_ended_at,deleted_at', 'owner.staff.user:id,first_name,last_name,is_active,deleted_at'])
            ->where(fn ($owners) => $owners->whereExists($activeOwner())->when($ids === null, fn ($all) => $all->orWhereNotExists($activeOwner())))
            ->where(fn ($booking) => $booking->whereNull('sales_opportunities.won_booking_id')
                ->orWhereExists(function ($linked) {
                    $linked->selectRaw('1')->from('bookings as won_booking')
                        ->whereColumn('won_booking.id', 'sales_opportunities.won_booking_id');
                    $this->constrainBookingOwnerToOpportunity($linked, 'won_booking', 'sales_opportunities');
                }));
        if ($ids !== null) $query->whereIn('owner_sales_profile_id', $ids);
        $rows = $query
            ->when($data['stage'] ?? null, fn ($q, $stage) => $q->where('stage', $stage))
            ->when($data['needs_owner_review'] ?? false, fn ($q) => $q->whereNotExists($activeOwner()))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where(function ($match) use ($search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $match->where('opportunity_number', 'like', $term)
                    ->orWhere('name', 'like', $term)->orWhere('prospect_name', 'like', $term);
            }))
            ->when($request->boolean('needs_follow_up'), fn ($q) => $q->whereNotIn('sales_opportunities.stage', ['won', 'lost'])
                ->whereNotExists(fn ($tasks) => $tasks->selectRaw('1')->from('sales_tasks as task')
                    ->whereColumn('task.opportunity_id', 'sales_opportunities.id')
                    ->whereIn('task.status', ['open', 'in_progress'])))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->whereDate('expected_close_date', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->whereDate('expected_close_date', '<=', $to))
            ->latest()->paginate($this->paginationSize($request, 25));
        $stageLabels = $this->opportunityStageLabels($rows->getCollection());
        $rows->setCollection($rows->getCollection()->map(fn ($row) => $this->opportunityPayload($row, false, $stageLabels[(string) $row->id] ?? SalesPolicySettingsService::STAGE_LABEL_DEFAULTS)));
        return response()->json(['status' => 'success', 'stage_labels' => $this->policySettings->stageLabels(), 'data' => $rows]);
    }

    public function showOpportunity(Request $request, SalesOpportunity $opportunity): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity);
        $opportunity->load(['owner.staff:id,code,user_id,employment_ended_at,deleted_at',
            'owner.staff.user:id,first_name,last_name,is_active,deleted_at', 'stageEvents']);
        $labels = $this->opportunityStageLabels(collect([$opportunity]));
        return response()->json(['status' => 'success', 'data' => $this->opportunityPayload($opportunity, true, $labels[(string) $opportunity->id] ?? SalesPolicySettingsService::STAGE_LABEL_DEFAULTS)]);
    }

    public function opportunityOwnerOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'exclude_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        $query = DB::table('sales_profiles as profile')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->whereNull('staff.deleted_at')->whereNull('users.deleted_at')->where('users.is_active', true)
            ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts as staff_context')
                ->whereColumn('staff_context.user_id', 'users.id')->whereColumn('staff_context.context_id', 'staff.id')
                ->where('staff_context.context_type', 'staff')->where('staff_context.is_active', true)->whereNull('staff_context.deleted_at'))
            ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
            ->where('profile.status', 'active')->where('profile.effective_from', '<=', now())
            ->where(fn ($effective) => $effective->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
            ->whereNotNull('profile.reporting_currency')->whereNotNull('profile.staff_category_snapshot')
            ->where(fn ($eligible) => $eligible->where('profile.acquisition_eligible', true)
                ->orWhere('profile.collection_eligible', true)->orWhere('profile.commission_eligible', true))
            ->when($ids !== null, fn ($profiles) => $profiles->whereIn('profile.id', $ids));
        if (! $this->policySettings->featureEnabled('crm')) $query->whereRaw('1 = 0');
        if (! empty($data['selected_id'])) $query->where('profile.id', $data['selected_id']);
        else {
            $query->when($data['search'] ?? null, function ($profiles, $search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $profiles->where(fn ($match) => $match->where('profile.sales_code', 'like', $term)
                    ->orWhere('staff.code', 'like', $term));
            });
        }
        $query->when($data['exclude_id'] ?? null, fn ($profiles, $id) => $profiles->where('profile.id', '!=', $id));
        $rows = $query->orderBy('profile.sales_code')->orderBy('profile.id')->select([
            'profile.id', 'profile.sales_code', 'staff.code as staff_code',
        ])->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($row) => [
            'value' => (string) $row->id,
            'label' => $row->sales_code.' - '.$row->staff_code,
            'status' => 'active',
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
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
            ->orderByDesc('booking.created_at')->paginate($this->paginationSize($request, 25));
        $rows->getCollection()->transform(fn ($booking) => [
            'value' => (string) $booking->id,
            'label' => $booking->booking_number ?: ($booking->log_code ? 'Draft '.$booking->log_code : 'Draft booking '.substr((string) $booking->created_at, 0, 10)),
            'metadata' => ['log_code' => $booking->log_code, 'created_at' => $booking->created_at],
            'status' => 'draft',
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function opportunitySourceOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['inquiry', 'phone_call'])],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        abort_unless($this->policySettings->featureEnabled('crm'), 422,
            'CRM source selection is not enabled.');
        $userIds = $this->sourceUserIds($request);
        $selected = ! empty($data['selected_id']);

        if ($data['source_type'] === 'inquiry') {
            $query = DB::table('inquiries as source')->leftJoin('sales_opportunities as opportunity', 'opportunity.inquiry_id', '=', 'source.id')
                ->whereNull('source.deleted_at')->whereNull('opportunity.id')
                ->where(fn ($scope) => $scope
                    ->where(fn ($assigned) => $assigned->whereNotNull('source.assigned_to')->whereIn('source.assigned_to', $userIds))
                    ->orWhere(fn ($created) => $created->whereNull('source.assigned_to')->whereIn('source.created_user_id', $userIds)));
            if ($selected) $query->where('source.id', $data['selected_id']);
            else $query->when($data['search'] ?? null, function ($rows, $search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $rows->where(fn ($match) => $match->where('source.inquiry_number', 'like', $term)
                    ->orWhere('source.name', 'like', $term)->orWhere('source.subject', 'like', $term));
            });
            $columns = ['source.id', 'source.inquiry_number', 'source.name', 'source.subject', 'source.status', 'source.created_at'];
            if ($selected) array_push($columns, 'source.email', 'source.phone');
            $rows = $query->orderByDesc('source.created_at')->select($columns)->paginate($data['per_page'] ?? 25);
            $rows->getCollection()->transform(fn ($row) => [
                'value' => (string) $row->id,
                'label' => trim(($row->inquiry_number ?: 'Inquiry').' · '.($row->name ?: $row->subject ?: 'No name')),
                'metadata' => ['type' => 'Inquiry'],
                'record' => $selected ? ['name' => $row->name, 'email' => $row->email, 'phone' => $row->phone,
                    'subject' => $row->subject, 'inquiry_number' => $row->inquiry_number] : null,
                'status' => $row->status,
            ]);
        } else {
            $query = DB::table('phone_calls as source')->leftJoin('sales_opportunities as opportunity', function ($join): void {
                    $join->on('opportunity.source_phone_call_id', '=', 'source.id')
                        ->whereNull('opportunity.deleted_at');
                })
                ->whereNull('source.deleted_at')->whereNull('opportunity.id')
                ->whereIn('source.created_user_id', $userIds);
            if ($selected) $query->where('source.id', $data['selected_id']);
            else $query->when($data['search'] ?? null, function ($rows, $search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $rows->where(fn ($match) => $match->where('source.client_name', 'like', $term)
                    ->orWhere('source.phone', 'like', $term)->orWhere('source.summary', 'like', $term));
            });
            $columns = ['source.id', 'source.client_name', 'source.phone', 'source.call_time'];
            if ($selected) $columns[] = 'source.summary';
            $rows = $query->orderByDesc('source.call_time')->select($columns)->paginate($data['per_page'] ?? 25);
            $rows->getCollection()->transform(fn ($row) => [
                'value' => (string) $row->id,
                'label' => ($row->call_time ? substr((string) $row->call_time, 0, 10).' · ' : '').($row->client_name ?: $row->phone ?: 'Phone Call'),
                'metadata' => ['type' => 'Phone Call'],
                'record' => $selected ? ['client_name' => $row->client_name, 'phone' => $row->phone, 'summary' => $row->summary] : null,
                'status' => 'logged',
            ]);
        }

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function createOpportunity(Request $request, SalesCrmService $crm): JsonResponse
    {
        $data = $request->validate([
            'owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'], 'inquiry_id' => ['nullable', 'uuid', 'exists:inquiries,id'],
            'source_phone_call_id' => ['nullable', 'uuid', 'exists:phone_calls,id'], 'name' => ['required', 'string', 'max:255'],
            'prospect_name' => ['nullable', 'string', 'max:255'], 'prospect_company' => ['nullable', 'string', 'max:255'],
            'prospect_email' => ['nullable', 'email', 'max:255'], 'prospect_phone' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'required_without_all:inquiry_id,source_phone_call_id', 'string', 'max:80'], 'campaign' => ['nullable', 'string', 'max:160'], 'referral' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'], 'expected_value_source' => ['required', 'numeric', 'min:0'],
            'source_currency' => ['required', 'string', 'size:3'], 'expected_value_lkr' => ['nullable', 'required_if:source_currency,LKR', 'numeric', 'min:0'],
            'probability_percent' => ['required', 'integer', 'min:0', 'max:100'], 'expected_close_date' => ['nullable', 'date'],
            'services' => ['nullable', 'array'], 'customer_needs' => ['nullable', 'string', 'max:5000'],
            'competitor_notes' => ['nullable', 'string', 'max:5000'],
            'confidentiality' => ['nullable', Rule::in(['owner', 'team', 'management'])],
            'follow_up_task' => ['required', 'array'],
            'follow_up_task.title' => ['required', 'string', 'max:255'],
            'follow_up_task.description' => ['nullable', 'string', 'max:5000'],
            'follow_up_task.priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'follow_up_task.due_at' => ['required', 'date', 'after:now'],
            'follow_up_task.remind_at' => ['nullable', 'date', 'before_or_equal:follow_up_task.due_at'],
        ]);
        $profile = SalesProfile::query()->findOrFail($data['owner_sales_profile_id']);
        abort_unless(SalesProfile::query()->whereKey($profile->id)->activeAt(now())->exists(), 422, 'Opportunity owner must have an active Sales Profile.');
        $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $authorizedProfileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->createOpportunity(
            $data, (string) $request->user()->id, $this->sourceUserIds($request), $authorizedProfileIds,
        ))], 201);
    }

    public function transitionOpportunity(Request $request, SalesOpportunity $opportunity, SalesCrmService $crm): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity, true);
        $data = $request->validate([
            'to_stage' => ['required', Rule::in(['new', 'contacted', 'qualified', 'quotation', 'negotiation', 'lost'])],
            'expected_version' => ['required', 'integer', 'min:1'], 'reason_code' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160'],
            'follow_up_task' => ['required_unless:to_stage,lost', 'array'],
            'follow_up_task.title' => ['required_with:follow_up_task', 'required_unless:to_stage,lost', 'string', 'max:255'],
            'follow_up_task.description' => ['nullable', 'string', 'max:5000'],
            'follow_up_task.priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'follow_up_task.due_at' => ['required_with:follow_up_task', 'required_unless:to_stage,lost', 'date', 'after:now'],
            'follow_up_task.remind_at' => ['nullable', 'date', 'before_or_equal:follow_up_task.due_at'],
        ]);
        $authorizedProfileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->transition(
            $opportunity, $data['to_stage'], $data['expected_version'], $data['reason_code'] ?? null, $data['reason'] ?? null,
            $data['idempotency_key'], (string) $request->user()->id, $data['follow_up_task'] ?? null,
            $authorizedProfileIds, $this->sourceUserIds($request),
        ))]);
    }

    public function transferOpportunity(Request $request, SalesOpportunity $opportunity, SalesCrmService $crm): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity, true);
        $data = $request->validate(['owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $owner = SalesProfile::query()->findOrFail($data['owner_sales_profile_id']);
        abort_unless(SalesProfile::query()->whereKey($owner->id)->activeAt(now())->exists(), 422, 'The new opportunity owner must have an active Sales Profile.');
        $this->scope->assertProfile($request->user(), $owner, 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->transfer($opportunity, $owner, $data['expected_version'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id))]);
    }

    public function linkBooking(Request $request, SalesOpportunity $opportunity, SalesCrmService $crm): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity, true);
        $data = $request->validate(['booking_id' => ['required', 'uuid', 'exists:bookings,id'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->linkBooking($opportunity, Booking::query()->findOrFail($data['booking_id']), $data['idempotency_key'], (string) $request->user()->id))]);
    }

    private function linkableBookingQuery(SalesOpportunity $opportunity)
    {
        $ownerStaffId = SalesProfile::query()->whereKey($opportunity->owner_sales_profile_id)
            ->activeAt(now())->configured()->whereHas('staff', fn ($staff) => $staff->whereNull('deleted_at')
                ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
                ->whereHas('user', fn ($user) => $user->where('is_active', true)->whereNull('deleted_at')
                    ->whereHas('contexts', fn ($context) => $context->where('context_type', 'staff')
                        ->whereColumn('context_id', 'staff.id')->where('is_active', true)->whereNull('deleted_at'))))
            ->value('staff_id');
        abort_unless($ownerStaffId, 422, 'Opportunity owner must have an active Sales Profile.');
        return DB::table('bookings as booking')
            ->leftJoin('staff as owner_staff', 'owner_staff.id', '=', 'booking.commission_owner_staff_id')
            ->leftJoin('staff as creator_staff', 'creator_staff.user_id', '=', 'booking.created_user_id')
            ->whereNull('booking.deleted_at')->whereNull('booking.sales_opportunity_id')
            ->where(fn ($query) => $query->whereNull('booking.confirmed')->orWhere('booking.confirmed', false))
            ->whereNull('booking.confirmed_at')->where(fn ($query) => $query->whereNull('booking.status')->orWhere('booking.status', '!=', 'confirmed'))
            ->whereRaw('COALESCE(owner_staff.id, creator_staff.id) = ?', [$ownerStaffId])
            ->when($opportunity->customer_id, fn ($query, $customerId) => $query->where(fn ($customer) => $customer->whereNull('booking.customer_id')->orWhere('booking.customer_id', $customerId)))
            ->select(['booking.id', 'booking.booking_number', 'booking.log_code', 'booking.created_at']);
    }

    public function activities(Request $request): JsonResponse
    {
        $data = $request->validate(['sales_profile_id' => ['nullable', 'uuid'], 'opportunity_id' => ['nullable', 'uuid'], 'activity_type' => ['nullable', Rule::in(['call', 'email', 'sms', 'whatsapp', 'meeting', 'site_visit', 'note', 'quotation', 'follow_up', 'collection_follow_up'])], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $query = SalesActivity::query()
            ->whereExists(fn ($profile) => $profile->selectRaw('1')->from('sales_profiles as profile')
                ->whereColumn('profile.id', 'sales_activities.sales_profile_id')
                ->whereNull('profile.deleted_at')
                ->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as activity_staff')
                    ->whereColumn('activity_staff.id', 'profile.staff_id')
                    ->whereNull('activity_staff.deleted_at')))
            ->where(fn ($opportunity) => $opportunity->whereNull('sales_activities.opportunity_id')
                ->whereNull('sales_activities.customer_id')->whereNull('sales_activities.inquiry_id')
                ->whereNull('sales_activities.phone_call_id')->whereNull('sales_activities.booking_id')
                ->whereNull('sales_activities.booking_activity_id')
                ->orWhereExists(fn ($linked) => $linked->selectRaw('1')->from('sales_opportunities as opportunity')
                    ->whereColumn('opportunity.id', 'sales_activities.opportunity_id')
                    ->where(fn ($reference) => $reference->whereNull('sales_activities.customer_id')
                        ->orWhereColumn('sales_activities.customer_id', 'opportunity.customer_id'))
                    ->where(fn ($reference) => $reference->whereNull('sales_activities.inquiry_id')
                        ->orWhereColumn('sales_activities.inquiry_id', 'opportunity.inquiry_id'))
                    ->where(fn ($reference) => $reference->whereNull('sales_activities.phone_call_id')
                        ->orWhereColumn('sales_activities.phone_call_id', 'opportunity.source_phone_call_id'))
                    ->whereExists(fn ($owner) => $owner->selectRaw('1')->from('sales_profiles as opportunity_owner')
                        ->whereColumn('opportunity_owner.id', 'opportunity.owner_sales_profile_id')
                        ->whereNull('opportunity_owner.deleted_at')
                        ->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as opportunity_staff')
                            ->whereColumn('opportunity_staff.id', 'opportunity_owner.staff_id')
                            ->whereNull('opportunity_staff.deleted_at'))
                    ->where(fn ($booking) => $booking->whereNull('sales_activities.booking_id')
                        ->orWhereExists(function ($linkedBooking) {
                            $linkedBooking->selectRaw('1')->from('bookings as activity_booking')
                                ->whereColumn('activity_booking.id', 'sales_activities.booking_id')->whereNull('activity_booking.deleted_at')
                                ->where(fn ($bookingLink) => $bookingLink
                                    ->whereColumn('activity_booking.sales_opportunity_id', 'opportunity.id')
                                    ->orWhereColumn('opportunity.won_booking_id', 'activity_booking.id'));
                            $this->constrainBookingOwnerToOpportunity($linkedBooking, 'activity_booking', 'opportunity');
                        }))
                    ->where(fn ($event) => $event->whereNull('sales_activities.booking_activity_id')
                        ->orWhereExists(fn ($bookingEvent) => $bookingEvent->selectRaw('1')->from('booking_activities as activity_event')
                            ->whereColumn('activity_event.id', 'sales_activities.booking_activity_id')
                            ->whereNotNull('activity_event.booking_id')->whereNull('activity_event.deleted_at')
                            ->where(fn ($sameBooking) => $sameBooking->whereNull('sales_activities.booking_id')
                                ->orWhereColumn('sales_activities.booking_id', 'activity_event.booking_id'))
                            ->whereExists(function ($linkedBooking) {
                                $linkedBooking->selectRaw('1')->from('bookings as event_booking')
                                    ->whereColumn('event_booking.id', 'activity_event.booking_id')->whereNull('event_booking.deleted_at')
                                    ->where(fn ($bookingLink) => $bookingLink
                                        ->whereColumn('event_booking.sales_opportunity_id', 'opportunity.id')
                                        ->orWhereColumn('opportunity.won_booking_id', 'event_booking.id'));
                                $this->constrainBookingOwnerToOpportunity($linkedBooking, 'event_booking', 'opportunity');
                            })))))
                ->orWhereExists(fn ($directInquiry) => $directInquiry->selectRaw('1')->from('inquiries as activity_inquiry')
                    ->whereColumn('activity_inquiry.id', 'sales_activities.inquiry_id')
                    ->whereNull('activity_inquiry.deleted_at')
                    ->where(fn ($customer) => $customer->whereNull('sales_activities.customer_id')
                        ->orWhereColumn('sales_activities.customer_id', 'activity_inquiry.customer_id')))
                ->orWhereExists(fn ($directBooking) => $directBooking->selectRaw('1')->from('bookings as activity_booking')
                    ->whereColumn('activity_booking.id', 'sales_activities.booking_id')->whereNull('activity_booking.deleted_at')
                    ->where(fn ($company) => $company->whereExists(fn ($attribution) => $attribution->selectRaw('1')
                        ->from('sales_booking_attributions as direct_attribution')->whereColumn('direct_attribution.booking_id', 'activity_booking.id')
                        )
                        ->orWhereExists(fn ($owner) => $owner->selectRaw('1')->from('staff as booking_owner')
                            ->whereColumn('booking_owner.user_id', 'activity_booking.responsible_user_id')
                            ->whereNull('booking_owner.deleted_at')))
                    ->where(fn ($customer) => $customer->whereNull('sales_activities.customer_id')
                        ->orWhereColumn('sales_activities.customer_id', 'activity_booking.customer_id')))
                ->orWhereExists(fn ($directCustomer) => $directCustomer->selectRaw('1')->from('customer_relationship_owner_assignments as activity_customer_owner')
                    ->whereColumn('activity_customer_owner.customer_id', 'sales_activities.customer_id')));
        if ($ids !== null) $query->whereIn('sales_profile_id', $ids);
        $rows = $query->when($data['sales_profile_id'] ?? null, fn ($q, $id) => $q->where('sales_profile_id', $id))
            ->when($data['opportunity_id'] ?? null, fn ($q, $id) => $q->where('opportunity_id', $id))
            ->when($data['activity_type'] ?? null, fn ($q, $type) => $q->where('activity_type', $type))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date))
            ->addSelect([
                'opportunity_reference' => DB::table('sales_opportunities')->select('opportunity_number')->whereColumn('sales_opportunities.id', 'sales_activities.opportunity_id'),
                'booking_reference' => DB::table('bookings')->selectRaw('COALESCE(booking_number, log_code)')->whereColumn('bookings.id', 'sales_activities.booking_id'),
                'inquiry_reference' => DB::table('inquiries')->select('inquiry_number')->whereColumn('inquiries.id', 'sales_activities.inquiry_id'),
                'customer_reference' => DB::table('customers')->select('code')->whereColumn('customers.id', 'sales_activities.customer_id'),
            ])
            ->latest('occurred_at')->paginate($this->paginationSize($request, 25));
        $rows->setCollection($rows->getCollection()->map(fn (SalesActivity $activity): array => [
            'activity_type' => $activity->activity_type, 'subject' => $activity->subject,
            'outcome' => $activity->outcome, 'next_action' => $activity->next_action,
            'record_label' => $activity->opportunity_id ? 'Opportunity ' . $activity->opportunity_reference
                : ($activity->booking_id ? 'Booking ' . ($activity->booking_reference ?: '—')
                    : ($activity->inquiry_id ? 'Inquiry ' . $activity->inquiry_reference
                        : ($activity->customer_id ? 'Customer ' . ($activity->customer_reference ?: '—') : ''))),
            'occurred_at' => $activity->occurred_at,
        ]));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function recordActivity(Request $request, SalesCrmService $crm): JsonResponse
    {
        $data = $request->validate([
            'sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'opportunity_id' => ['nullable', 'required_without_all:booking_id,inquiry_id,customer_id', 'uuid', 'exists:sales_opportunities,id'], 'customer_id' => ['nullable', 'required_without_all:opportunity_id,booking_id,inquiry_id', 'uuid', 'exists:customers,id'],
            'inquiry_id' => ['nullable', 'required_without_all:opportunity_id,booking_id,customer_id', 'uuid', 'exists:inquiries,id'], 'booking_id' => ['nullable', 'required_without_all:opportunity_id,inquiry_id,customer_id', 'uuid', 'exists:bookings,id'],
            'phone_call_id' => ['nullable', 'uuid', 'exists:phone_calls,id'], 'booking_activity_id' => ['nullable', 'uuid', 'exists:booking_activities,id'],
            'activity_type' => ['required', Rule::in(['call', 'email', 'sms', 'whatsapp', 'meeting', 'site_visit', 'note', 'quotation', 'follow_up', 'collection_follow_up'])],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])], 'subject' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:80'], 'next_action' => ['nullable', 'string', 'max:2000'], 'next_action_at' => ['nullable', 'date'],
            'follow_up_task' => ['sometimes', 'array'],
            'follow_up_task.title' => ['required_with:follow_up_task', 'string', 'max:255'],
            'follow_up_task.description' => ['nullable', 'string', 'max:5000'],
            'follow_up_task.priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'follow_up_task.due_at' => ['required_with:follow_up_task', 'date'],
            'follow_up_task.remind_at' => ['nullable', 'date', 'before_or_equal:follow_up_task.due_at'],
            'source_system' => ['required', 'string', 'max:60'], 'source_reference' => ['required', 'string', 'max:160'],
            'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'], 'occurred_at' => ['required', 'date', 'before_or_equal:now'],
        ]);
        $profile = SalesProfile::query()->findOrFail($data['sales_profile_id']);
        $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $authorizedProfileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->recordActivity(
            $data, (string) $request->user()->id, $authorizedProfileIds, $this->sourceUserIds($request),
        ))], 201);
    }

    public function tasks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sales_profile_id' => ['nullable', 'uuid'], 'opportunity_id' => ['nullable', 'uuid'], 'task_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'overdue_only' => ['nullable', 'boolean'],
            'actionable_only' => ['nullable', 'boolean'],
            'needs_owner_review' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        if ($request->boolean('needs_owner_review')) {
            abort_unless($ids === null || $this->scope->hasPermission($request->user(), 'sales.crm.manage-all'), 403,
                'Sales-wide access is required to review follow-ups with an inactive owner.');
            $ids = null;
        }
        $nowUtc = now('UTC');
        $workdayEndUtc = now(config('app.timezone'))->endOfDay()->utc();
        $activeTaskOwner = fn () => $this->activeSalesOwnerExists('sales_tasks.owner_sales_profile_id');
        $query = SalesTask::query()
            ->with([
                'owner',
                'opportunity:id,opportunity_number,name,prospect_name',
                'booking:id,booking_number,log_code',
                'inquiry:id,inquiry_number,subject,name',
                'customer:id,code,user_id',
                'customer.user:id,first_name,last_name',
            ])
            ->addSelect(['owner_active' => $activeTaskOwner()])
            ->whereExists(fn ($profile) => $profile->selectRaw('1')->from('sales_profiles as profile')
                ->whereColumn('profile.id', 'sales_tasks.owner_sales_profile_id')
                ->whereNull('profile.deleted_at')
                ->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as owner_staff')
                    ->whereColumn('owner_staff.id', 'profile.staff_id')
                    ))
            ->where(fn ($linkScope) => $linkScope
                ->whereExists(fn ($linked) => $linked->selectRaw('1')->from('sales_opportunities as opportunity')
                    ->whereColumn('opportunity.id', 'sales_tasks.opportunity_id')
                    ->where(fn ($references) => $references->whereNull('sales_tasks.customer_id')
                        ->orWhereColumn('sales_tasks.customer_id', 'opportunity.customer_id'))
                    ->where(fn ($references) => $references->whereNull('sales_tasks.inquiry_id')
                        ->orWhereColumn('sales_tasks.inquiry_id', 'opportunity.inquiry_id'))
                    ->where(fn ($booking) => $booking->whereNull('sales_tasks.booking_id')
                        ->orWhereExists(function ($linkedBooking) {
                            $linkedBooking->selectRaw('1')->from('bookings as task_booking')
                                ->whereColumn('task_booking.id', 'sales_tasks.booking_id')->whereNull('task_booking.deleted_at')
                                ->where(fn ($bookingLink) => $bookingLink
                                    ->whereColumn('task_booking.sales_opportunity_id', 'opportunity.id')
                                    ->orWhereColumn('opportunity.won_booking_id', 'task_booking.id'));
                            $this->constrainBookingOwnerToOpportunity($linkedBooking, 'task_booking', 'opportunity');
                        })))
                ->orWhereExists(fn ($direct) => $direct->selectRaw('1')->from('bookings as task_booking')
                    ->whereColumn('task_booking.id', 'sales_tasks.booking_id')
                    ->whereNull('task_booking.deleted_at')
                    ->whereNull('sales_tasks.opportunity_id')
                    ->whereNull('sales_tasks.inquiry_id')
                    ->where(fn ($company) => $company
                        ->whereExists(fn ($attribution) => $attribution->selectRaw('1')->from('sales_booking_attributions as attribution')
                            ->whereColumn('attribution.booking_id', 'task_booking.id'))
                        ->orWhereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as booking_owner')
                            ->whereColumn('booking_owner.user_id', 'task_booking.responsible_user_id')))
                    ->where(fn ($customer) => $customer->whereNull('sales_tasks.customer_id')
                        ->orWhereColumn('sales_tasks.customer_id', 'task_booking.customer_id')))
                ->orWhereExists(fn ($inquiry) => $inquiry->selectRaw('1')->from('inquiries as task_inquiry')
                    ->whereColumn('task_inquiry.id', 'sales_tasks.inquiry_id')->whereNull('task_inquiry.deleted_at')
                    ->whereNull('sales_tasks.opportunity_id')->whereNull('sales_tasks.booking_id')
                    ->where(function ($source) {
                        $source->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as inquiry_owner')
                            ->whereColumn('inquiry_owner.user_id', 'task_inquiry.assigned_to'))
                            ->orWhere(function ($unassigned) {
                                $unassigned->whereNull('task_inquiry.assigned_to')->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as inquiry_creator')
                                    ->whereColumn('inquiry_creator.user_id', 'task_inquiry.created_user_id'));
                            });
                    })
                    ->where(fn ($customer) => $customer->whereNull('sales_tasks.customer_id')
                        ->orWhereColumn('sales_tasks.customer_id', 'task_inquiry.customer_id')))
                ->orWhereExists(fn ($customer) => $customer->selectRaw('1')->from('customer_relationship_owner_assignments as customer_owner')
                    ->whereColumn('customer_owner.customer_id', 'sales_tasks.customer_id')
                    ->whereNull('sales_tasks.opportunity_id')->whereNull('sales_tasks.booking_id')->whereNull('sales_tasks.inquiry_id')));
        if ($ids !== null) $query->whereIn('owner_sales_profile_id', $ids);
        $rows = $query
            ->when($data['sales_profile_id'] ?? null, fn ($q, $id) => $q->where('owner_sales_profile_id', $id))
            ->when($data['opportunity_id'] ?? null, fn ($q, $id) => $q->where('opportunity_id', $id))
            ->when($data['task_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->boolean('needs_owner_review'), fn ($q) => $q->whereIn('status', ['open', 'in_progress'])->whereNotExists($activeTaskOwner()))
            ->when($request->boolean('overdue_only'), fn ($q) => $q->whereIn('status', ['open', 'in_progress'])->where('due_at', '<', $nowUtc))
            ->when($request->boolean('actionable_only'), fn ($q) => $q->whereIn('status', ['open', 'in_progress'])->where('due_at', '<=', $workdayEndUtc))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('due_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('due_at', '<=', $date))
            ->orderByRaw("CASE WHEN status IN ('completed','cancelled') THEN 1 ELSE 0 END")
            ->orderBy('due_at')->paginate($this->paginationSize($request, 25));
        $rows->setCollection($rows->getCollection()->map(function (SalesTask $task) use ($request, $nowUtc): array {
            [$sourceType, $sourceId, $sourceLabel] = match (true) {
                (bool) $task->opportunity_id => ['opportunity', (string) $task->opportunity_id,
                    trim(($task->opportunity?->opportunity_number ?: 'Opportunity').' · '.($task->opportunity?->prospect_name ?: $task->opportunity?->name))],
                (bool) $task->inquiry_id => ['inquiry', (string) $task->inquiry_id,
                    trim(($task->inquiry?->inquiry_number ?: 'Inquiry').' · '.($task->inquiry?->subject ?: $task->inquiry?->name))],
                (bool) $task->booking_id => ['booking', (string) $task->booking_id,
                    $task->booking?->booking_number ?: ($task->booking?->log_code ? 'Draft '.$task->booking->log_code : 'Booking')],
                (bool) $task->customer_id => ['customer', (string) $task->customer_id,
                    trim(($task->customer?->code ? $task->customer->code.' · ' : 'Customer · ')
                        .trim(($task->customer?->user?->first_name ?? '').' '.($task->customer?->user?->last_name ?? '')))],
                default => [null, null, null],
            };
            $canViewSource = match ($sourceType) {
                'inquiry' => $request->user()->can('inquiries.view'),
                'booking' => $request->user()->can('bookings.view'),
                'customer' => $request->user()->can('customers.view'),
                default => true,
            };
            if (! $canViewSource && $sourceType) {
                [$sourceType, $sourceId, $sourceLabel] = [null, null, 'Linked record (access restricted)'];
            }

            return [
                'id' => (string) $task->id,
                'title' => $task->title,
                'priority' => $task->priority,
                'status' => $task->status,
                'owner_sales_profile_id' => (string) $task->owner_sales_profile_id,
                'owner_label' => $task->owner?->staff?->user?->full_name ?: ($task->owner?->sales_code ?: 'Former employee'),
                'owner_active' => (bool) $task->owner_active,
                'due_at' => $task->due_at,
                'is_overdue' => (bool) (in_array($task->status, ['open', 'in_progress'], true)
                    && $task->due_at?->utc()->lt($nowUtc)),
                'state_version' => (int) $task->state_version,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_label' => $sourceLabel,
            ];
        }));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function taskBookingOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $userId = (string) $request->user()->id;
        $profileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        $sourceUsers = $this->sourceUserIds($request);

        $query = DB::table('bookings as booking')->whereNull('booking.deleted_at')
            ->where(function ($scope) use ($userId, $profileIds, $sourceUsers) {
                $scope->whereExists(fn ($attribution) => $attribution->selectRaw('1')->from('sales_booking_attributions as attribution')
                    ->whereColumn('attribution.booking_id', 'booking.id')
                    ->when($profileIds !== null, fn ($owners) => $owners->where(fn ($eligible) => $eligible
                        ->whereIn('acquisition_sales_profile_id', $profileIds)
                        ->orWhereIn('collection_sales_profile_id', $profileIds))))
                    ->orWhereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as booking_owner')
                        ->whereColumn('booking_owner.user_id', 'booking.responsible_user_id')
                        ->whereNull('booking_owner.deleted_at')->whereIn('booking_owner.user_id', $sourceUsers))
                    ->orWhere(fn ($quote) => $quote->where('booking.status', 'quotation_requested')
                        ->whereNull('booking.responsible_user_id')
                        ->whereRaw("booking.workflow_data->>'quotation_requested_by' = ?", [$userId])
                        ->whereExists(fn ($sent) => $sent->selectRaw('1')->from('booking_activities as sent_quote')
                            ->whereColumn('sent_quote.booking_id', 'booking.id')
                            ->whereNull('sent_quote.deleted_at')->where('sent_quote.event_key', 'quotation_email_sent')
                            ->where('sent_quote.result_status', 'sent')));
            })
            ->when($data['selected_id'] ?? null, fn ($q, $id) => $q->where('booking.id', $id))
            ->when(empty($data['selected_id']) && ! empty($data['search']), function ($q) use ($data) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($data['search'])).'%';
                $q->where(fn ($match) => $match->where('booking.booking_number', 'like', $term)
                    ->orWhere('booking.log_code', 'like', $term));
            })
            ->orderByDesc('booking.created_at')
            ->select(['booking.id', 'booking.booking_number', 'booking.log_code', 'booking.created_at']);

        $rows = $query->paginate($this->paginationSize($request, 25));
        $rows->getCollection()->transform(fn ($booking) => [
            'value' => (string) $booking->id,
            'label' => $booking->booking_number ?: ($booking->log_code ? 'Draft '.$booking->log_code : 'Booking '.substr((string) $booking->created_at, 0, 10)),
            'metadata' => ['created_at' => $booking->created_at],
            'status' => 'available',
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function taskInquiryOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $sourceUsers = $this->sourceUserIds($request);
        $query = DB::table('inquiries as inquiry')->whereNull('inquiry.deleted_at')
            ->where(function ($scope) use ($sourceUsers) {
                $scope->whereIn('inquiry.assigned_to', $sourceUsers)
                    ->orWhere(function ($created) use ($sourceUsers) {
                        $created->whereNull('inquiry.assigned_to')->whereIn('inquiry.created_user_id', $sourceUsers);
                    });
            })
            ->when($data['selected_id'] ?? null, fn ($q, $id) => $q->where('inquiry.id', $id))
            ->when(empty($data['selected_id']) && ! empty($data['search']), function ($q) use ($data) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($data['search'])).'%';
                $q->where(fn ($match) => $match->where('inquiry.inquiry_number', 'like', $term)
                    ->orWhere('inquiry.name', 'like', $term)->orWhere('inquiry.subject', 'like', $term)
                    ->orWhere('inquiry.phone', 'like', $term)->orWhere('inquiry.email', 'like', $term));
            })
            ->orderByDesc('inquiry.created_at')
            ->select(['inquiry.id', 'inquiry.inquiry_number', 'inquiry.name', 'inquiry.subject', 'inquiry.status', 'inquiry.created_at']);
        $rows = $query->paginate($this->paginationSize($request, 25));
        $rows->getCollection()->transform(fn ($inquiry) => [
            'value' => (string) $inquiry->id,
            'label' => trim(($inquiry->inquiry_number ?: 'Inquiry').' - '.($inquiry->name ?: $inquiry->subject ?: 'New lead')),
            'metadata' => ['status' => $inquiry->status, 'created_at' => $inquiry->created_at],
            'status' => 'available',
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function taskCustomerOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $profileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        $query = DB::table('customers as customer')
            ->join('users as contact', 'contact.id', '=', 'customer.user_id')
            ->join('customer_relationship_owner_assignments as assignment', function ($join): void {
                $join->on('assignment.customer_id', '=', 'customer.id')
                    ->whereNull('assignment.ended_at')->whereNotNull('assignment.owner_sales_profile_id');
            })
            ->join('sales_profiles as owner_profile', function ($join): void {
                $join->on('owner_profile.id', '=', 'assignment.owner_sales_profile_id')
                    ->where('owner_profile.status', '=', 'active')->where('owner_profile.effective_from', '<=', now())
                    ->whereNotNull('owner_profile.reporting_currency')->whereNotNull('owner_profile.staff_category_snapshot')
                    ->where(fn ($eligible) => $eligible->where('owner_profile.acquisition_eligible', true)
                        ->orWhere('owner_profile.collection_eligible', true)->orWhere('owner_profile.commission_eligible', true))
                    ->where(fn ($effective) => $effective->whereNull('owner_profile.effective_until')->orWhere('owner_profile.effective_until', '>', now()));
            })
            ->join('staff as owner_staff', function ($join): void {
                $join->on('owner_staff.id', '=', 'owner_profile.staff_id')
                    ->whereNull('owner_staff.deleted_at')
                    ->where(fn ($employment) => $employment->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()));
            })
            ->join('users as owner_user', function ($join): void {
                $join->on('owner_user.id', '=', 'owner_staff.user_id')->where('owner_user.is_active', '=', true)->whereNull('owner_user.deleted_at');
            })
            ->whereNull('customer.deleted_at')
            ->when($profileIds !== null, fn ($q) => $q->whereIn('assignment.owner_sales_profile_id', $profileIds))
            ->when($data['selected_id'] ?? null, fn ($q, $id) => $q->where('customer.id', $id))
            ->when(empty($data['selected_id']) && ! empty($data['search']), function ($q) use ($data) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($data['search'])).'%';
                $q->where(fn ($match) => $match->where('contact.first_name', 'like', $term)
                    ->orWhere('contact.last_name', 'like', $term)->orWhere('contact.email', 'like', $term)
                    ->orWhere('contact.phone', 'like', $term)->orWhere('customer.code', 'like', $term));
            })
            ->orderBy('contact.first_name')->orderBy('contact.last_name')
            ->select(['customer.id', 'customer.code', 'contact.first_name', 'contact.last_name', 'contact.phone', 'contact.email'])
            ->distinct();
        $rows = $query->paginate($this->paginationSize($request, 25));
        $rows->getCollection()->transform(fn ($customer) => [
            'value' => (string) $customer->id,
            'label' => trim(($customer->code ? $customer->code.' - ' : '').trim($customer->first_name.' '.$customer->last_name)),
            'metadata' => ['phone' => $customer->phone, 'email' => $customer->email],
            'status' => 'available',
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    private function constrainBookingOwnerToOpportunity($query, string $bookingAlias, string $opportunityAlias): void
    {
        $query->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as linked_booking_staff')
            ->where(fn ($owner) => $owner->whereColumn('linked_booking_staff.id', "{$bookingAlias}.commission_owner_staff_id")
                ->orWhere(fn ($creator) => $creator->whereNull("{$bookingAlias}.commission_owner_staff_id")
                    ->whereColumn('linked_booking_staff.user_id', "{$bookingAlias}.created_user_id")))
            ->whereExists(fn ($profile) => $profile->selectRaw('1')->from('sales_profiles as linked_booking_profile')
                ->whereColumn('linked_booking_profile.id', "{$opportunityAlias}.owner_sales_profile_id")
                ->whereColumn('linked_booking_profile.staff_id', 'linked_booking_staff.id')
                ->whereNull('linked_booking_profile.deleted_at')));
    }

    public function createTask(Request $request, SalesCrmService $crm): JsonResponse
    {
        $explicitInstant = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';
        $data = $request->validate([
            'owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'opportunity_id' => ['nullable', 'uuid', 'exists:sales_opportunities,id'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'inquiry_id' => ['nullable', 'uuid', 'exists:inquiries,id'],
            'booking_id' => ['nullable', 'uuid', 'exists:bookings,id'],
            'creation_idempotency_key' => ['required', 'string', 'max:160'],
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['required', 'date', $explicitInstant],
            'remind_at' => ['nullable', 'date', $explicitInstant, 'before_or_equal:due_at'],
            'escalate_at' => ['nullable', 'date', $explicitInstant, 'after_or_equal:due_at'],
        ]);
        $profile = SalesProfile::query()->findOrFail($data['owner_sales_profile_id']); $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $authorizedProfileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        $linkedSourceCount = count(array_filter([$data['opportunity_id'] ?? null, $data['booking_id'] ?? null, $data['inquiry_id'] ?? null]));
        abort_unless($linkedSourceCount === 1 || ($linkedSourceCount === 0 && ! empty($data['customer_id'])),
            422, 'Choose one opportunity, booking, inquiry, or owned customer for this follow-up.');
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->createTask(
            $data, (string) $request->user()->id, $authorizedProfileIds, $this->sourceUserIds($request),
        ))], 201);
    }

    public function transitionTask(Request $request, SalesTask $task, SalesCrmService $crm): JsonResponse
    {
        $profile = SalesProfile::query()->findOrFail($task->owner_sales_profile_id); $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $data = $request->validate(['to_status' => ['required', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->transitionTask($task, $data, (string) $request->user()->id))]);
    }

    public function transferTask(Request $request, SalesTask $task, SalesCrmService $crm): JsonResponse
    {
        $current = SalesProfile::query()->findOrFail($task->owner_sales_profile_id);
        $this->scope->assertProfile($request->user(), $current, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $data = $request->validate(['owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $newOwner = SalesProfile::query()->findOrFail($data['owner_sales_profile_id']);
        $this->scope->assertProfile($request->user(), $newOwner, 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->transferTask($task, $newOwner, $data['expected_version'], $data['reason'], $data['idempotency_key'], (string) $request->user()->id))]);
    }

    private function assertOpportunityScope(Request $request, SalesOpportunity $opportunity, bool $manage = false): void
    {
        $profile = SalesProfile::query()->findOrFail($opportunity->owner_sales_profile_id);
        abort_unless(DB::table('staff')->where('id', $profile->staff_id)->whereNull('deleted_at')->exists(),
            409, 'Opportunity owner is no longer linked to an active staff record.');
        $this->scope->assertProfile($request->user(), $profile, $manage ? 'sales.crm.manage-all' : 'sales.crm.view-all', $manage ? 'sales.crm.manage-team' : 'sales.crm.view-team');
    }

    private function activeSalesOwnerExists(string $profileIdColumn): \Illuminate\Database\Query\Builder
    {
        return DB::table('sales_profiles as profile')->selectRaw('1')
            ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
            ->join('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
            ->whereColumn('profile.id', $profileIdColumn)
            ->where('profile.status', 'active')->whereNull('profile.deleted_at')->where('profile.effective_from', '<=', now())
            ->where(fn ($effective) => $effective->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
            ->whereNotNull('profile.reporting_currency')->whereNotNull('profile.staff_category_snapshot')
            ->where(fn ($eligible) => $eligible->where('profile.acquisition_eligible', true)
                ->orWhere('profile.collection_eligible', true)->orWhere('profile.commission_eligible', true))
            ->whereNull('owner_staff.deleted_at')
            ->where(fn ($employment) => $employment->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
            ->where('owner_user.is_active', true)->whereNull('owner_user.deleted_at')
            ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts')
                ->whereColumn('user_contexts.user_id', 'owner_staff.user_id')->whereColumn('user_contexts.context_id', 'owner_staff.id')
                ->where('user_contexts.context_type', 'staff')->where('user_contexts.is_active', true)->whereNull('user_contexts.deleted_at'));
    }

    private function writeConfirmation(object $row): array
    {
        $confirmation = ['id' => (string) $row->id];
        if (isset($row->status)) $confirmation['status'] = (string) $row->status;
        if (isset($row->state_version)) $confirmation['version'] = (int) $row->state_version;

        return $confirmation;
    }

    private function opportunityPayload(SalesOpportunity $row, bool $detail = false, ?array $stageLabels = null): array
    {
        $owner = $row->owner;
        $ownerStaff = $owner?->staff;
        $ownerUser = $ownerStaff?->user;
        $employmentEndedAt = $ownerStaff?->employment_ended_at;
        $employmentEnded = $employmentEndedAt && \Illuminate\Support\Carbon::parse($employmentEndedAt)->lte(now());
        $ownerNeedsReview = ! $owner || $owner->status !== 'active' || ! $owner->effective_from || $owner->effective_from->gt(now())
            || ($owner->effective_until && $owner->effective_until->lte(now())) || ! $owner->reporting_currency || ! $owner->staff_category_snapshot
            || ! ($owner->acquisition_eligible || $owner->collection_eligible || $owner->commission_eligible)
            || ! $ownerStaff || $ownerStaff->trashed()
            || $employmentEnded || ! $ownerUser || ! $ownerUser->is_active || $ownerUser->trashed();
        $payload = [
            'id' => (string) $row->id,
            'opportunity_number' => $row->opportunity_number,
            'name' => $row->name,
            'stage' => $row->stage,
            'stage_label' => ($stageLabels ?? SalesPolicySettingsService::STAGE_LABEL_DEFAULTS)[$row->stage] ?? $row->stage,
            'stage_labels' => $stageLabels ?? SalesPolicySettingsService::STAGE_LABEL_DEFAULTS,
            'owner' => $owner ? ['sales_code' => $owner->sales_code, 'staff_code' => $ownerStaff?->code,
                'name' => $ownerUser ? trim($ownerUser->first_name.' '.$ownerUser->last_name) : null] : null,
            'owner_needs_review' => $ownerNeedsReview,
            'expected_value_lkr' => $row->expected_value_lkr,
            'probability_percent' => $row->probability_percent,
            'next_action' => $row->next_action,
            'next_action_at' => $row->next_action_at,
        ];
        if (! $detail) return $payload;
        $wonBookingNumber = null;
        $wonBookingCancelled = false;
        if ($row->won_booking_id) {
            $booking = DB::table('sales_opportunities as won_opportunity')
                ->join('bookings as won_booking', 'won_booking.id', '=', 'won_opportunity.won_booking_id')
                ->where('won_opportunity.id', $row->id)->where('won_booking.id', $row->won_booking_id);
            $this->constrainBookingOwnerToOpportunity($booking, 'won_booking', 'won_opportunity');
            $wonBooking = $booking->first(['won_booking.booking_number', 'won_booking.status']);
            $wonBookingNumber = $wonBooking?->booking_number;
            $wonBookingCancelled = in_array(strtolower((string) ($wonBooking?->status ?? '')), ['cancelled', 'canceled', 'booking_cancelled'], true);
        }
        return $payload + [
            'owner_sales_profile_id' => (string) $row->owner_sales_profile_id,
            'state_version' => $row->state_version,
            'crm_enabled' => $this->policySettings->featureEnabled('crm'),
            'prospect_name' => $row->prospect_name,
            'prospect_email' => $row->prospect_email,
            'prospect_phone' => $row->prospect_phone,
            'won_booking' => $row->won_booking_id
                ? ['booking_number' => $wonBookingNumber, 'is_cancelled' => $wonBookingCancelled]
                : null,
            'stage_events' => $row->stageEvents->sortByDesc('occurred_at')->map(fn ($event) => [
                'from_stage' => $event->from_stage,
                'from_stage_label' => $event->from_stage ? (data_get($event->snapshot, 'stage_labels.'.$event->from_stage) ?? SalesPolicySettingsService::STAGE_LABEL_DEFAULTS[$event->from_stage] ?? $event->from_stage) : 'Created',
                'to_stage' => $event->to_stage,
                'to_stage_label' => data_get($event->snapshot, 'stage_labels.'.$event->to_stage) ?? SalesPolicySettingsService::STAGE_LABEL_DEFAULTS[$event->to_stage] ?? $event->to_stage,
                'reason' => $event->reason,
                'occurred_at' => $event->occurred_at,
            ])->values(),
            'available_transitions' => $this->crm->allowedTransitions($row),
        ];
    }

    private function opportunityStageLabels($opportunities): array
    {
        $defaults = SalesPolicySettingsService::STAGE_LABEL_DEFAULTS;
        if (! Schema::hasColumn('sales_feature_settings', 'stage_label')) {
            return $opportunities->mapWithKeys(fn ($row) => [(string) $row->id => $defaults])->all();
        }
        $mapping = [
            'workflow_contact_stage_setting_id' => ['contacted', 'crm_contact_stage'],
            'workflow_qualification_stage_setting_id' => ['qualified', 'crm_qualification_stage'],
            'workflow_quotation_stage_setting_id' => ['quotation', 'crm_quotation_stage'],
            'workflow_negotiation_stage_setting_id' => ['negotiation', 'crm_negotiation_stage'],
        ];
        $ids = $opportunities->flatMap(fn ($row) => collect(array_map(fn ($column) => $row->{$column}, array_keys($mapping)))->filter())->unique();
        $settings = $ids->isEmpty() ? collect() : DB::table('sales_feature_settings')->whereIn('id', $ids)->get(['id', 'feature_key', 'stage_label'])->keyBy('id');
        return $opportunities->mapWithKeys(function ($row) use ($defaults, $mapping, $settings) {
            $labels = $defaults;
            foreach ($mapping as $column => [$stage, $feature]) {
                $setting = $settings->get($row->{$column});
                if ($setting && $setting->feature_key === $feature && trim((string) $setting->stage_label) !== '') {
                    $labels[$stage] = trim((string) $setting->stage_label);
                }
            }
            return [(string) $row->id => $labels];
        })->all();
    }

    private function sourceUserIds(Request $request): array
    {
        $profileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');

        $userIds = SalesProfile::query()->activeAt(now())
            ->when($profileIds !== null, fn ($profiles) => $profiles->whereIn('id', $profileIds))
            ->whereHas('staff', fn ($staff) => $staff->whereNull('deleted_at')
                ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
            ->with('staff:id,user_id')->get()->pluck('staff.user_id')->filter()->unique()->values()->all();

        return $userIds;
    }
}
