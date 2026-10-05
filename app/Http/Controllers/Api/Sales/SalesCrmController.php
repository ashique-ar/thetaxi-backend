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
    ) {}

    public function opportunities(Request $request): JsonResponse
    {
        $data = $request->validate(['stage' => ['nullable', Rule::in(['new', 'contacted', 'qualified', 'quotation', 'negotiation', 'won', 'lost'])], 'company_id' => ['required', 'uuid', 'exists:companies,id'], 'search' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        abort_unless(DB::table('companies')->where('id', $data['company_id'])->where('is_active', true)
            ->whereNull('deleted_at')->exists(), 422, 'Select an active legal entity.');
        abort_unless($ids === null || SalesProfile::query()->whereIn('id', $ids)
            ->where('company_id', $data['company_id'])->exists(), 403,
            'The selected legal entity is outside your Sales scope.');
        $query = SalesOpportunity::query()->with(['owner.staff:id,code'])
            ->whereExists(fn ($owner) => $owner->selectRaw('1')->from('sales_profiles as profile')
                ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->whereColumn('profile.id', 'sales_opportunities.owner_sales_profile_id')
                ->whereColumn('profile.company_id', 'sales_opportunities.company_id')
                ->whereNull('profile.deleted_at')
                ->whereColumn('owner_staff.company_id', 'sales_opportunities.company_id')
                ->whereNull('owner_staff.deleted_at'))
            ->where(fn ($booking) => $booking->whereNull('sales_opportunities.won_booking_id')
                ->orWhereExists(function ($linked) {
                    $linked->selectRaw('1')->from('bookings as won_booking')
                        ->whereColumn('won_booking.id', 'sales_opportunities.won_booking_id');
                    $this->constrainBookingOwnerToOpportunity($linked, 'won_booking', 'sales_opportunities');
                }));
        if ($ids !== null) $query->whereIn('owner_sales_profile_id', $ids);
        $rows = $query
            ->where('company_id', $data['company_id'])
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

    public function opportunityCompanyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $profileIds = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $companyIds = $profileIds === null ? null : DB::table('sales_profiles')->whereNull('deleted_at')
            ->whereIn('id', $profileIds)->pluck('company_id')->filter()->unique()->values();
        $query = DB::table('companies')->where('is_active', true)->whereNull('deleted_at')
            ->when($companyIds !== null, fn ($companies) => $companies->whereIn('id', $companyIds));
        if (! empty($data['selected_id'])) $query->where('id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($companies) => $companies->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $query->select(['id', 'name', 'city', 'is_default'])->orderByDesc('is_default')
            ->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($company) => [
            'value' => (string) $company->id, 'label' => $company->name,
            'metadata' => array_filter(['city' => $company->city]) + ['is_default' => (bool) $company->is_default],
            'status' => 'active',
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function showOpportunity(Request $request, SalesOpportunity $opportunity): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity);
        $opportunity->load(['owner.staff:id,code', 'stageEvents']);
        return response()->json(['status' => 'success', 'data' => $this->opportunityPayload($opportunity, true)]);
    }

    public function opportunityOwnerOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'exclude_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team', $data['company_id'] ?? null);
        $featureTableReady = Schema::hasTable('sales_company_feature_settings');
        $query = DB::table('sales_profiles as profile')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->join('companies', 'companies.id', '=', 'profile.company_id')
            ->whereNull('staff.deleted_at')->whereNull('users.deleted_at')->where('users.is_active', true)->whereNull('companies.deleted_at')
            ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts as staff_context')
                ->whereColumn('staff_context.user_id', 'users.id')->whereColumn('staff_context.context_id', 'staff.id')
                ->where('staff_context.context_type', 'staff')->where('staff_context.is_active', true)->whereNull('staff_context.deleted_at'))
            ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
            ->where('profile.status', 'active')->where('profile.effective_from', '<=', now())
            ->where(fn ($effective) => $effective->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
            ->whereNotNull('profile.reporting_currency')->whereNotNull('profile.staff_category_snapshot')
            ->where(fn ($eligible) => $eligible->where('profile.acquisition_eligible', true)
                ->orWhere('profile.collection_eligible', true)->orWhere('profile.commission_eligible', true))
            ->when($ids !== null, fn ($profiles) => $profiles->whereIn('profile.id', $ids))
            ->when($data['company_id'] ?? null, fn ($profiles, $companyId) => $profiles->where('profile.company_id', $companyId));
        if (config('sales.features.crm') === true && $featureTableReady) {
            $query->whereExists(fn ($feature) => $feature->selectRaw('1')->from('sales_company_feature_settings as setting')
                ->whereColumn('setting.company_id', 'profile.company_id')->where('setting.feature_key', 'crm')
                ->whereRaw('setting.version = (select MAX(current_setting.version) from sales_company_feature_settings as current_setting where current_setting.company_id = profile.company_id and current_setting.feature_key = setting.feature_key and current_setting.status = \'approved\' and current_setting.deleted_at is null)')
                ->where('setting.enabled', true)->where('setting.status', 'approved')->whereNull('setting.deleted_at'));
        } else $query->whereRaw('1 = 0');
        if (! empty($data['selected_id'])) $query->where('profile.id', $data['selected_id']);
        else {
            $query->when($data['search'] ?? null, function ($profiles, $search) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $profiles->where(fn ($match) => $match->where('profile.sales_code', 'like', $term)
                    ->orWhere('staff.code', 'like', $term)->orWhere('companies.name', 'like', $term));
            });
        }
        $query->when($data['exclude_id'] ?? null, fn ($profiles, $id) => $profiles->where('profile.id', '!=', $id));
        $rows = $query->orderBy('profile.sales_code')->orderBy('profile.id')->select([
            'profile.id', 'profile.sales_code', 'profile.company_id', 'companies.name as company_name', 'staff.code as staff_code',
        ])->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($row) => [
            'value' => (string) $row->id,
            'label' => $row->sales_code.' - '.$row->staff_code,
            'metadata' => ['company' => $row->company_name],
            'record' => ['company_id' => (string) $row->company_id],
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
            ->orderByDesc('booking.created_at')->paginate($request->integer('per_page', 25));
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
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'source_type' => ['required', Rule::in(['inquiry', 'phone_call'])],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyId = (string) $data['company_id'];
        abort_unless(DB::table('companies')->where('id', $companyId)->where('is_active', true)
            ->whereNull('deleted_at')->exists(), 422, 'Select an active legal entity.');
        abort_unless($this->policySettings->featureEnabled($companyId, 'crm'), 422,
            'CRM source selection is not enabled for this legal entity.');
        $userIds = $this->sourceUserIds($request, $companyId);
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
            $query = DB::table('phone_calls as source')->leftJoin('sales_opportunities as opportunity', 'opportunity.source_phone_call_id', '=', 'source.id')
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
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->createOpportunity(
            $data, (string) $request->user()->id, $this->sourceUserIds($request, (string) $profile->company_id),
        ))], 201);
    }

    public function transitionOpportunity(Request $request, SalesOpportunity $opportunity, SalesCrmService $crm): JsonResponse
    {
        $this->assertOpportunityScope($request, $opportunity, true);
        $data = $request->validate(['to_stage' => ['required', Rule::in(['contacted', 'qualified', 'quotation', 'negotiation', 'lost'])], 'expected_version' => ['required', 'integer', 'min:1'], 'reason_code' => ['nullable', 'string', 'max:80'], 'reason' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->transition($opportunity, $data['to_stage'], $data['expected_version'], $data['reason_code'] ?? null, $data['reason'] ?? null, $data['idempotency_key'], (string) $request->user()->id))]);
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
        $ownerStaffId = SalesProfile::query()->whereKey($opportunity->owner_sales_profile_id)->where('company_id', $opportunity->company_id)
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
            ->whereRaw('COALESCE(owner_staff.company_id, creator_staff.company_id) = ?', [$opportunity->company_id])
            ->whereRaw('COALESCE(owner_staff.id, creator_staff.id) = ?', [$ownerStaffId])
            ->when($opportunity->customer_id, fn ($query, $customerId) => $query->where(fn ($customer) => $customer->whereNull('booking.customer_id')->orWhere('booking.customer_id', $customerId)))
            ->select(['booking.id', 'booking.booking_number', 'booking.log_code', 'booking.created_at']);
    }

    public function activities(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id'], 'sales_profile_id' => ['nullable', 'uuid'], 'opportunity_id' => ['nullable', 'uuid'], 'activity_type' => ['nullable', Rule::in(['call', 'email', 'sms', 'whatsapp', 'meeting', 'site_visit', 'note', 'quotation', 'follow_up', 'collection_follow_up'])], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $this->assertActiveListCompany($data['company_id'], $ids);
        $query = SalesActivity::query()
            ->where('sales_activities.company_id', $data['company_id'])
            ->whereExists(fn ($profile) => $profile->selectRaw('1')->from('sales_profiles as profile')
                ->whereColumn('profile.id', 'sales_activities.sales_profile_id')
                ->whereColumn('profile.company_id', 'sales_activities.company_id')
                ->whereNull('profile.deleted_at')
                ->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as activity_staff')
                    ->whereColumn('activity_staff.id', 'profile.staff_id')
                    ->whereColumn('activity_staff.company_id', 'sales_activities.company_id')
                    ->whereNull('activity_staff.deleted_at')))
            ->where(fn ($opportunity) => $opportunity->whereNull('sales_activities.opportunity_id')
                ->whereNull('sales_activities.customer_id')->whereNull('sales_activities.inquiry_id')
                ->whereNull('sales_activities.phone_call_id')->whereNull('sales_activities.booking_id')
                ->whereNull('sales_activities.booking_activity_id')
                ->orWhereExists(fn ($linked) => $linked->selectRaw('1')->from('sales_opportunities as opportunity')
                    ->whereColumn('opportunity.id', 'sales_activities.opportunity_id')
                    ->whereColumn('opportunity.company_id', 'sales_activities.company_id')
                    ->where(fn ($reference) => $reference->whereNull('sales_activities.customer_id')
                        ->orWhereColumn('sales_activities.customer_id', 'opportunity.customer_id'))
                    ->where(fn ($reference) => $reference->whereNull('sales_activities.inquiry_id')
                        ->orWhereColumn('sales_activities.inquiry_id', 'opportunity.inquiry_id'))
                    ->where(fn ($reference) => $reference->whereNull('sales_activities.phone_call_id')
                        ->orWhereColumn('sales_activities.phone_call_id', 'opportunity.source_phone_call_id'))
                    ->whereExists(fn ($owner) => $owner->selectRaw('1')->from('sales_profiles as opportunity_owner')
                        ->whereColumn('opportunity_owner.id', 'opportunity.owner_sales_profile_id')
                        ->whereColumn('opportunity_owner.company_id', 'opportunity.company_id')
                        ->whereNull('opportunity_owner.deleted_at')
                        ->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as opportunity_staff')
                            ->whereColumn('opportunity_staff.id', 'opportunity_owner.staff_id')
                            ->whereColumn('opportunity_staff.company_id', 'opportunity.company_id')
                            ->whereNull('opportunity_staff.deleted_at'))
                    ->where(fn ($booking) => $booking->whereNull('sales_activities.booking_id')
                        ->orWhereExists(function ($linkedBooking) {
                            $linkedBooking->selectRaw('1')->from('bookings as activity_booking')
                                ->whereColumn('activity_booking.id', 'sales_activities.booking_id')->whereNull('activity_booking.deleted_at')
                                ->where(fn ($bookingLink) => $bookingLink
                                    ->whereColumn('activity_booking.sales_opportunity_id', 'opportunity.id')
                                    ->orWhereColumn('opportunity.won_booking_id', 'activity_booking.id'))
                                ->whereNotExists(fn ($foreignAttribution) => $foreignAttribution->selectRaw('1')
                                    ->from('sales_booking_attributions as activity_attribution')
                                    ->whereColumn('activity_attribution.booking_id', 'activity_booking.id')
                                    ->where(fn ($company) => $company->whereNull('activity_attribution.company_id')
                                        ->orWhereColumn('activity_attribution.company_id', '!=', 'sales_activities.company_id')));
                            $this->constrainBookingOwnerToOpportunity($linkedBooking, 'activity_booking', 'opportunity');
                        }))
                    ->where(fn ($event) => $event->whereNull('sales_activities.booking_activity_id')
                        ->orWhereExists(fn ($bookingEvent) => $bookingEvent->selectRaw('1')->from('booking_activities as activity_event')
                            ->whereColumn('activity_event.id', 'sales_activities.booking_activity_id')
                            ->whereColumn('activity_event.company_id', 'sales_activities.company_id')
                            ->whereNotNull('activity_event.booking_id')->whereNull('activity_event.deleted_at')
                            ->where(fn ($sameBooking) => $sameBooking->whereNull('sales_activities.booking_id')
                                ->orWhereColumn('sales_activities.booking_id', 'activity_event.booking_id'))
                            ->whereExists(function ($linkedBooking) {
                                $linkedBooking->selectRaw('1')->from('bookings as event_booking')
                                    ->whereColumn('event_booking.id', 'activity_event.booking_id')->whereNull('event_booking.deleted_at')
                                    ->where(fn ($bookingLink) => $bookingLink
                                        ->whereColumn('event_booking.sales_opportunity_id', 'opportunity.id')
                                        ->orWhereColumn('opportunity.won_booking_id', 'event_booking.id'))
                                    ->whereNotExists(fn ($foreignAttribution) => $foreignAttribution->selectRaw('1')
                                        ->from('sales_booking_attributions as event_attribution')
                                        ->whereColumn('event_attribution.booking_id', 'event_booking.id')
                                        ->where(fn ($company) => $company->whereNull('event_attribution.company_id')
                                            ->orWhereColumn('event_attribution.company_id', '!=', 'sales_activities.company_id')));
                                $this->constrainBookingOwnerToOpportunity($linkedBooking, 'event_booking', 'opportunity');
                            }))))));
        if ($ids !== null) $query->whereIn('sales_profile_id', $ids);
        $rows = $query->when($data['sales_profile_id'] ?? null, fn ($q, $id) => $q->where('sales_profile_id', $id))
            ->when($data['opportunity_id'] ?? null, fn ($q, $id) => $q->where('opportunity_id', $id))
            ->when($data['activity_type'] ?? null, fn ($q, $type) => $q->where('activity_type', $type))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date))
            ->latest('occurred_at')->paginate($request->integer('per_page', 25));
        $rows->setCollection($rows->getCollection()->map(fn (SalesActivity $activity): array => [
            'activity_type' => $activity->activity_type, 'subject' => $activity->subject,
            'outcome' => $activity->outcome, 'next_action' => $activity->next_action,
            'occurred_at' => $activity->occurred_at,
        ]));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function recordActivity(Request $request, SalesCrmService $crm): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'opportunity_id' => ['required', 'uuid', 'exists:sales_opportunities,id'], 'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'inquiry_id' => ['nullable', 'uuid', 'exists:inquiries,id'], 'booking_id' => ['nullable', 'uuid', 'exists:bookings,id'],
            'phone_call_id' => ['nullable', 'uuid', 'exists:phone_calls,id'], 'booking_activity_id' => ['nullable', 'uuid', 'exists:booking_activities,id'],
            'activity_type' => ['required', Rule::in(['call', 'email', 'sms', 'whatsapp', 'meeting', 'site_visit', 'note', 'quotation', 'follow_up', 'collection_follow_up'])],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])], 'subject' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:80'], 'next_action' => ['nullable', 'string', 'max:2000'], 'next_action_at' => ['nullable', 'date'],
            'source_system' => ['required', 'string', 'max:60'], 'source_reference' => ['required', 'string', 'max:160'],
            'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'], 'occurred_at' => ['required', 'date', 'before_or_equal:now'],
        ]);
        $profile = SalesProfile::query()->findOrFail($data['sales_profile_id']);
        $this->scope->assertProfile($request->user(), $profile, 'sales.crm.manage-all', 'sales.crm.manage-team');
        $authorizedProfileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team');
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->recordActivity($data, (string) $request->user()->id, $authorizedProfileIds))], 201);
    }

    public function tasks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'sales_profile_id' => ['nullable', 'uuid'], 'opportunity_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $ids = $this->scope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
        $this->assertActiveListCompany($data['company_id'], $ids);
        $query = SalesTask::query()
            ->where('sales_tasks.company_id', $data['company_id'])
            ->whereExists(fn ($profile) => $profile->selectRaw('1')->from('sales_profiles as profile')
                ->whereColumn('profile.id', 'sales_tasks.owner_sales_profile_id')
                ->whereColumn('profile.company_id', 'sales_tasks.company_id')
                ->whereNull('profile.deleted_at')
                ->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as owner_staff')
                    ->whereColumn('owner_staff.id', 'profile.staff_id')
                    ->whereColumn('owner_staff.company_id', 'sales_tasks.company_id')
                    ->whereNull('owner_staff.deleted_at')))
            ->whereExists(fn ($linked) => $linked->selectRaw('1')->from('sales_opportunities as opportunity')
                    ->whereColumn('opportunity.id', 'sales_tasks.opportunity_id')
                    ->whereColumn('opportunity.company_id', 'sales_tasks.company_id')
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
                                    ->orWhereColumn('opportunity.won_booking_id', 'task_booking.id'))
                                ->whereNotExists(fn ($foreignAttribution) => $foreignAttribution->selectRaw('1')
                                    ->from('sales_booking_attributions as attribution')
                                    ->whereColumn('attribution.booking_id', 'task_booking.id')
                                    ->where(fn ($company) => $company->whereNull('attribution.company_id')
                                        ->orWhereColumn('attribution.company_id', '!=', 'sales_tasks.company_id')));
                            $this->constrainBookingOwnerToOpportunity($linkedBooking, 'task_booking', 'opportunity');
                        }))));
        if ($ids !== null) $query->whereIn('owner_sales_profile_id', $ids);
        $rows = $query
            ->when($data['sales_profile_id'] ?? null, fn ($q, $id) => $q->where('owner_sales_profile_id', $id))
            ->when($data['opportunity_id'] ?? null, fn ($q, $id) => $q->where('opportunity_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('due_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('due_at', '<=', $date))
            ->orderByRaw("CASE WHEN status IN ('completed','cancelled') THEN 1 ELSE 0 END")
            ->orderBy('due_at')->paginate($request->integer('per_page', 25));
        $rows->setCollection($rows->getCollection()->map(fn (SalesTask $task): array => [
            'id' => (string) $task->id,
            'title' => $task->title,
            'priority' => $task->priority,
            'status' => $task->status,
            'due_at' => $task->due_at,
            'state_version' => (int) $task->state_version,
        ]));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    private function assertActiveListCompany(string $companyId, ?array $profileIds): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->where('is_active', true)
            ->whereNull('deleted_at')->exists(), 422, 'Select an active legal entity.');
        abort_unless($profileIds === null || SalesProfile::query()->whereIn('id', $profileIds)
            ->where('company_id', $companyId)->exists(), 403,
            'The selected legal entity is outside your Sales scope.');
    }

    private function constrainBookingOwnerToOpportunity($query, string $bookingAlias, string $opportunityAlias): void
    {
        $query->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as linked_booking_staff')
            ->whereNull('linked_booking_staff.deleted_at')
            ->whereColumn('linked_booking_staff.company_id', "{$opportunityAlias}.company_id")
            ->where(fn ($owner) => $owner->whereColumn('linked_booking_staff.id', "{$bookingAlias}.commission_owner_staff_id")
                ->orWhere(fn ($creator) => $creator->whereNull("{$bookingAlias}.commission_owner_staff_id")
                    ->whereColumn('linked_booking_staff.user_id', "{$bookingAlias}.created_user_id")))
            ->whereExists(fn ($profile) => $profile->selectRaw('1')->from('sales_profiles as linked_booking_profile')
                ->whereColumn('linked_booking_profile.id', "{$opportunityAlias}.owner_sales_profile_id")
                ->whereColumn('linked_booking_profile.staff_id', 'linked_booking_staff.id')
                ->whereColumn('linked_booking_profile.company_id', "{$opportunityAlias}.company_id")
                ->whereNull('linked_booking_profile.deleted_at')))
            ->whereNotExists(fn ($attribution) => $attribution->selectRaw('1')->from('sales_booking_attributions as linked_booking_attribution')
                ->whereColumn('linked_booking_attribution.booking_id', "{$bookingAlias}.id")
                ->where(fn ($company) => $company->whereNull('linked_booking_attribution.company_id')
                    ->orWhereColumn('linked_booking_attribution.company_id', '!=', "{$opportunityAlias}.company_id")));
    }

    public function createTask(Request $request, SalesCrmService $crm): JsonResponse
    {
        $explicitInstant = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'owner_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'opportunity_id' => ['required', 'uuid', 'exists:sales_opportunities,id'],
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
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($crm->createTask($data, (string) $request->user()->id, $authorizedProfileIds))], 201);
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
        abort_unless((string) $profile->company_id === (string) $opportunity->company_id
            && DB::table('staff')->where('id', $profile->staff_id)->where('company_id', $opportunity->company_id)
                ->whereNull('deleted_at')->exists()
            && (! $opportunity->won_booking_id || DB::table('bookings')->where('id', $opportunity->won_booking_id)
                ->where('company_id', $opportunity->company_id)->exists()),
            409, 'Opportunity owner links do not match its legal entity. Reconcile ownership before continuing.');
        $this->scope->assertProfile($request->user(), $profile, $manage ? 'sales.crm.manage-all' : 'sales.crm.view-all', $manage ? 'sales.crm.manage-team' : 'sales.crm.view-team');
    }

    private function writeConfirmation(object $row): array
    {
        $confirmation = ['id' => (string) $row->id];
        if (isset($row->status)) $confirmation['status'] = (string) $row->status;
        if (isset($row->state_version)) $confirmation['version'] = (int) $row->state_version;

        return $confirmation;
    }

    private function opportunityPayload(SalesOpportunity $row, bool $detail = false): array
    {
        $payload = [
            'id' => (string) $row->id,
            'opportunity_number' => $row->opportunity_number,
            'name' => $row->name,
            'stage' => $row->stage,
            'expected_value_lkr' => $row->expected_value_lkr,
            'probability_percent' => $row->probability_percent,
            'next_action' => $row->next_action,
            'next_action_at' => $row->next_action_at,
        ];
        if (! $detail) return $payload;
        $wonBookingNumber = null;
        if ($row->won_booking_id) {
            $booking = DB::table('sales_opportunities as won_opportunity')
                ->join('bookings as won_booking', 'won_booking.id', '=', 'won_opportunity.won_booking_id')
                ->where('won_opportunity.id', $row->id)->where('won_booking.id', $row->won_booking_id);
            $this->constrainBookingOwnerToOpportunity($booking, 'won_booking', 'won_opportunity');
            $wonBookingNumber = $booking->value('won_booking.booking_number');
        }
        return $payload + [
            'company_id' => (string) $row->company_id,
            'owner_sales_profile_id' => (string) $row->owner_sales_profile_id,
            'owner' => $row->owner ? ['sales_code' => $row->owner->sales_code, 'staff_code' => $row->owner->staff?->code] : null,
            'state_version' => $row->state_version,
            'crm_enabled' => $this->policySettings->featureEnabled((string) $row->company_id, 'crm'),
            'prospect_name' => $row->prospect_name,
            'prospect_email' => $row->prospect_email,
            'prospect_phone' => $row->prospect_phone,
            'won_booking' => $row->won_booking_id
                ? ['booking_number' => $wonBookingNumber]
                : null,
            'stage_events' => $row->stageEvents->sortByDesc('occurred_at')->map(fn ($event) => [
                'from_stage' => $event->from_stage,
                'to_stage' => $event->to_stage,
                'reason' => $event->reason,
                'occurred_at' => $event->occurred_at,
            ])->values(),
        ];
    }

    private function sourceUserIds(Request $request, string $companyId): array
    {
        $profileIds = $this->scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team', $companyId);

        $userIds = SalesProfile::query()->where('company_id', $companyId)->activeAt(now())
            ->when($profileIds !== null, fn ($profiles) => $profiles->whereIn('id', $profileIds))
            ->whereHas('staff', fn ($staff) => $staff->where('company_id', $companyId)->whereNull('deleted_at')
                ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
            ->with('staff:id,user_id')->get()->pluck('staff.user_id')->filter()->unique()->values()->all();

        if ($userIds === []) return [];

        $ambiguousUserIds = DB::table('staff')->whereIn('user_id', $userIds)
            ->where(fn ($staff) => $staff->whereNull('company_id')->orWhere('company_id', '!=', $companyId))
            ->distinct()->pluck('user_id')->all();

        return array_values(array_diff($userIds, $ambiguousUserIds));
    }
}
