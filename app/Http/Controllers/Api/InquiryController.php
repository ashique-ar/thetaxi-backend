<?php
// app/Http/Controllers/Api/InquiryController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Company;
use App\Support\PhoneNumberMatch;
use App\Models\InquiryOutboundResponse;
use App\Models\IntegrationWebhookEvent;
use App\Models\InquiryForm;
use App\Models\Customer;
use App\Models\User;
use App\Services\UserContextService;
use App\Services\InquiryAssignmentService;
use App\Services\AuthService;
use App\Services\MailDispatchService;
use App\Mail\InquiryResponseMail;
use App\Services\SingleCompanyScope;
use App\Services\CompanyBookingScope;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\Sales\InquiryFirstResponseDeadlineService;
use App\Models\Website\CmsContent;
use App\Http\Requests\Inquiry\CreateInquiryRequest;
use App\Http\Requests\Inquiry\UpdateInquiryRequest;
use App\Http\Resources\Inquiry\InquiryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class InquiryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:inquiries.view')->only(['index', 'show', 'filterOptions', 'assigneeOptions', 'responseMetrics', 'unscoped']);
        $this->middleware('permission:inquiries.edit')->only(['scopeUnassigned']);
        $this->middleware('permission:companies.edit')->only(['unscoped', 'scopeUnassigned']);
        $this->middleware('permission:companies.view')->only(['unscoped', 'scopeUnassigned', 'companyOptions']);
        $this->middleware('permission:inquiries.view|communication.manage')->only(['customerOptions', 'bookingOptions']);
        $this->middleware('permission:inquiries.create')->only(['store']);
        $this->middleware('permission:inquiries.edit')->only(['update', 'assign', 'updateStatus', 'respond', 'sendEmailResponse', 'markRead', 'createCustomerFromInquiry']);
        $this->middleware('permission:inquiries.delete')->only(['destroy']);
    }

    public function filterOptions(): JsonResponse
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Set one active default company before viewing inquiries.');

        return response()->json(['status' => 'success', 'data' => [
            'services' => CmsContent::withInactive()->withTrashed()
                ->whereHas('inquiries', fn ($query) => $query->where('company_id', $companyId))->orderBy('title')->get(['id', 'title']),
            'forms' => InquiryForm::withInactive()->withTrashed()
                ->whereHas('cmsServices.inquiries', fn ($query) => $query->where('company_id', $companyId))->orderBy('name')->get(['id', 'name']),
            'workflows' => Inquiry::where('company_id', $companyId)->distinct()->whereNotNull('inquiry_type')->pluck('inquiry_type'),
            'statuses' => Inquiry::where('company_id', $companyId)->distinct()->whereNotNull('status')->pluck('status'),
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
            'mine_only' => ['nullable', 'in:0,1,true,false'],
            'unassigned_only' => ['nullable', 'in:0,1,true,false'],
            'actionable_only' => ['nullable', 'in:0,1,true,false'],
            'first_response_state' => ['nullable', 'in:awaiting,check,answered,overdue'],
            'response_state' => ['nullable', 'in:missing,saved'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Set one active default company before viewing inquiries.');
        $q = Inquiry::where('company_id', $companyId)->with(['cmsContent', 'inquiryServicePage', 'assignedUser.staff', 'firstResponseSavedBy']);
        $q->addSelect([
            'email_response_status' => InquiryOutboundResponse::query()->select('status')
                ->whereColumn('company_id', 'inquiries.company_id')->whereColumn('inquiry_id', 'inquiries.id')->latest('created_at')->limit(1),
            'respond_io_reply_status' => IntegrationWebhookEvent::query()->select('outbound_status')
                ->whereColumn('company_id', 'inquiries.company_id')->whereColumn('linked_inquiry_id', 'inquiries.id')
                ->incomingMessage()->latest('received_at')->limit(1),
        ]);
        if ($request->filled('search')) {
            $term = '%'.$filters['search'].'%';
            $q->where(fn ($query) => $query->where('subject', 'like', $term)
                ->orWhere('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('inquiry_number', 'like', $term));
        }
        foreach (['service_id' => 'cms_content_id', 'workflow' => 'inquiry_type', 'status' => 'status'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $q->where($column, $filters[$filter]);
            }
        }
        if ($request->boolean('actionable_only')) {
            $q->whereIn('status', ['open', 'in_progress']);
        }
        $responseTargetMinutes = app(SalesPolicySettingsService::class)->inquiryFirstResponseMinutes((string) $companyId);
        if (in_array($filters['first_response_state'] ?? null, ['awaiting', 'check', 'overdue'], true)) {
            $hasUnconfirmedEmail = static fn ($attempt) => $attempt->selectRaw('1')->from('inquiry_outbound_responses as email_attempt')
                ->whereColumn('email_attempt.company_id', 'inquiries.company_id')->whereColumn('email_attempt.inquiry_id', 'inquiries.id')
                ->whereIn('email_attempt.status', ['sending', 'uncertain']);
            $hasUnconfirmedRespondIo = static fn ($attempt) => $attempt->selectRaw('1')->from('integration_webhook_events as reply_attempt')
                ->whereColumn('reply_attempt.company_id', 'inquiries.company_id')->whereColumn('reply_attempt.linked_inquiry_id', 'inquiries.id')
                ->whereIn('reply_attempt.outbound_status', ['sending', 'uncertain']);
            $q->whereNotIn('status', ['closed', 'resolved', 'archived']);
            if ($filters['first_response_state'] === 'check') {
                $q->where(fn ($query) => $query->whereExists($hasUnconfirmedEmail)->orWhereExists($hasUnconfirmedRespondIo));
            } else {
                $q->whereNull('first_response_sent_at');
                $q->whereNotExists($hasUnconfirmedEmail)->whereNotExists($hasUnconfirmedRespondIo);
                if ($filters['first_response_state'] === 'overdue') {
                    abort_unless($responseTargetMinutes !== null, 409, 'Configure and approve a first-response target before using the overdue queue.');
                    app(InquiryFirstResponseDeadlineService::class)->constrainOverdue($q, $responseTargetMinutes, now());
                }
            }
        } elseif (($filters['first_response_state'] ?? null) === 'answered') {
            $q->whereNotNull('first_response_sent_at');
        }
        if ($request->boolean('mine_only')) {
            $q->where('assigned_to', $request->user()->id);
        } elseif ($request->boolean('unassigned_only')) {
            $eligibleAssignees = app(InquiryAssignmentService::class)->eligibleAssigneeIds((string) $companyId);
            $q->where(fn ($unassigned) => $unassigned->whereNull('assigned_to')->orWhereNotIn('assigned_to', $eligibleAssignees));
        } elseif (! empty($filters['assigned_to'])) {
            $q->where('assigned_to', $filters['assigned_to']);
        }
        if (($filters['response_state'] ?? null) === 'missing') {
            $q->where(fn ($query) => $query->whereNull('response')->orWhere('response', ''));
        } elseif (($filters['response_state'] ?? null) === 'saved') {
            $q->whereNotNull('response')->where('response', '<>', '');
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

        if (in_array($filters['first_response_state'] ?? null, ['awaiting', 'check', 'overdue'], true)) {
            $q->orderBy('created_at')->orderBy('id');
        } else {
            $q->latest();
        }
        return InquiryResource::collection($q->paginate($filters['per_page'] ?? 15));
    }

    public function unscoped(Request $request): JsonResponse
    {
        $rows = DB::table('inquiries')->leftJoin('users as assignee', 'assignee.id', '=', 'inquiries.assigned_to')
            ->whereNull('inquiries.company_id')->whereNull('inquiries.deleted_at')
            ->orderBy('inquiries.created_at')->orderBy('inquiries.id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100), [
                'inquiries.id', 'inquiries.inquiry_number', 'inquiries.name', 'inquiries.email', 'inquiries.phone', 'inquiries.subject',
                'inquiries.message', 'inquiries.assigned_to', 'inquiries.status', 'inquiries.created_at',
                'assignee.first_name as assigned_first_name', 'assignee.last_name as assigned_last_name',
            ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = Company::query()->where('is_active', true)->whereNull('deleted_at');
        if (! empty($data['search'])) {
            $query->whereLikeInsensitive('name', trim($data['search']));
        }
        $options = $query->orderByDesc('is_default')->orderBy('name')->limit(25)->get(['id', 'name'])
            ->map(fn ($company) => ['value' => (string) $company->id, 'label' => $company->name, 'status' => 'active']);

        return response()->json(['status' => 'success', 'data' => $options]);
    }

    public function scopeUnassigned(Request $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'reason' => ['required', 'string', 'min:8', 'max:350'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $companyId = (string) $data['company_id'];
        $data['reason'] = trim($data['reason']);
        abort_unless(mb_strlen($data['reason']) >= 8, 422, 'Explain why this inquiry belongs to the selected business.');
        $scoped = DB::transaction(function () use ($inquiry, $data, $companyId, $request): Inquiry {
            $locked = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $existingEvent = DB::table('inquiry_company_scope_events')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existingEvent) {
                abort_unless((string) $existingEvent->inquiry_id === (string) $locked->id
                    && (string) $existingEvent->company_id === $companyId
                    && $existingEvent->reason === $data['reason'], 409, 'This review key was already used for different details.');
                abort_unless((string) $locked->company_id === $companyId, 409, 'This inquiry was moved again; refresh the list.');
                return $locked;
            }
            abort_unless($locked->company_id === null, 409, 'This inquiry already has a company. Refresh the list.');
            $company = Company::query()->whereKey($companyId)->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 422, 'Choose an active business.');

            if ($locked->booking_id) {
                $booking = DB::table('bookings as booking')->where('booking.id', $locked->booking_id);
                CompanyBookingScope::constrain($booking, 'booking', $companyId);
                abort_unless($booking->exists(), 422, 'The linked booking does not belong to this business.');
            }

            $fromUserId = $locked->assigned_to;
            $assigneeIsInCompany = $fromUserId && $this->eligibleAssignees($companyId)
                ->where('users.id', $fromUserId)->exists();
            $toUserId = $assigneeIsInCompany ? $fromUserId : null;
            $locked->update([
                'company_id' => $companyId,
                'assigned_to' => $toUserId,
                'status' => $locked->status ?: 'open',
                'updated_user_id' => $request->user()->id,
            ]);
            DB::table('inquiry_company_scope_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'inquiry_id' => $locked->id,
                'actor_user_id' => $request->user()->id, 'reason' => $data['reason'],
                'idempotency_key' => $data['idempotency_key'], 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($fromUserId && ! $assigneeIsInCompany) {
                DB::table('inquiry_assignment_events')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId, 'inquiry_id' => $locked->id,
                    'from_user_id' => $fromUserId, 'to_user_id' => null, 'actor_user_id' => $request->user()->id,
                    'event_type' => 'unassigned',
                    'reason' => 'Previous employee was not active in the selected business; request returned to its unassigned list.',
                    'idempotency_key' => $data['idempotency_key'], 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $locked->fresh();
        });

        return response()->json(['status' => 'success', 'message' => 'Inquiry added to the selected business.', 'data' => [
            'id' => (string) $scoped->id, 'company_id' => (string) $scoped->company_id, 'assigned_to' => $scoped->assigned_to,
        ]]);
    }

    public function store(CreateInquiryRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source'] ?? null) === 'respond_io') {
            $this->ensureRespondIoEnabled();
        }
        $data['company_id'] = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($data['company_id'], 409, 'Set one active default company before creating inquiries.');
        $data['created_user_id'] = $request->user()->id;
        $inq = Inquiry::createWithUniqueNumber($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry created',
            'data' => ['inquiry' => new InquiryResource($inq)]
        ], 201);
    }

    public function show(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->assertInquiryCompany($inquiry);
        $inquiry->load(['cmsContent', 'inquiryServicePage', 'assignedUser.staff', 'firstResponseSavedBy', 'booking']);
        if ($inquiry->source === 'respond_io') {
            $replyEvent = IntegrationWebhookEvent::query()->where('company_id', $inquiry->company_id)
                ->where('linked_inquiry_id', $inquiry->id)->incomingMessage()->latest('received_at')->first();
            $inquiry->setAttribute('respond_io_reply_status', $replyEvent?->outbound_status);
        } else {
            $emailResponse = InquiryOutboundResponse::query()->where('company_id', $inquiry->company_id)
                ->where('inquiry_id', $inquiry->id)->latest('created_at')->first(['status', 'sent_at', 'idempotency_key']);
            $inquiry->setAttribute('email_response_status', $emailResponse?->status);
            $inquiry->setAttribute('email_response_sent_at', $emailResponse?->sent_at);
            if ($request->user()->can('inquiries.edit')) {
                $inquiry->setAttribute('email_response_idempotency_key', $emailResponse?->idempotency_key);
            }
        }
        if ($request->user()->can('sales.crm.view')) {
            $inquiry->setAttribute('linked_opportunity_id', DB::table('sales_opportunities')->where('inquiry_id', $inquiry->id)
                ->where('company_id', $inquiry->company_id)->whereNull('deleted_at')->value('id'));
        }
        return response()->json([
            'status' => 'success',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    public function responseMetrics(SalesPolicySettingsService $settings): JsonResponse
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Set one active default company before viewing inquiry response metrics.');
        $inquiries = DB::table('inquiries')->where('company_id', $companyId)->whereNull('deleted_at');
        $hasUnconfirmedEmail = static fn ($query) => $query->selectRaw('1')->from('inquiry_outbound_responses as email_attempt')
            ->whereColumn('email_attempt.company_id', 'inquiries.company_id')->whereColumn('email_attempt.inquiry_id', 'inquiries.id')
            ->whereIn('email_attempt.status', ['sending', 'uncertain']);
        $hasUnconfirmedRespondIo = static fn ($query) => $query->selectRaw('1')->from('integration_webhook_events as reply_attempt')
            ->whereColumn('reply_attempt.company_id', 'inquiries.company_id')->whereColumn('reply_attempt.linked_inquiry_id', 'inquiries.id')
            ->whereIn('reply_attempt.outbound_status', ['sending', 'uncertain']);
        $activeOpen = fn () => (clone $inquiries)->whereNotIn('status', ['closed', 'resolved', 'archived']);
        $activeUnanswered = fn () => $activeOpen()->whereNull('first_response_sent_at');
        $targetMinutes = $settings->inquiryFirstResponseMinutes((string) $companyId);
        $pastFirstResponse = null;
        if ($targetMinutes !== null) {
            $pastFirstResponseQuery = $activeUnanswered()->whereNotExists($hasUnconfirmedEmail)->whereNotExists($hasUnconfirmedRespondIo);
            app(InquiryFirstResponseDeadlineService::class)->constrainOverdue($pastFirstResponseQuery, $targetMinutes, now());
            $pastFirstResponse = $pastFirstResponseQuery->count();
        }
        $receivedAfter = now()->subDays(30)->utc();
        $responseWindow = (clone $inquiries)->where('created_at', '>=', $receivedAfter)
            ->whereNotNull('first_response_sent_at')->whereColumn('first_response_sent_at', '>=', 'created_at');
        $averageQuery = clone $responseWindow;
        $averageSeconds = match (DB::connection()->getDriverName()) {
            'pgsql' => $averageQuery->selectRaw('AVG(EXTRACT(EPOCH FROM (first_response_sent_at - created_at))) AS aggregate')->value('aggregate'),
            'mysql', 'mariadb' => $averageQuery->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, first_response_sent_at)) AS aggregate')->value('aggregate'),
            'sqlite' => $averageQuery->selectRaw('AVG((julianday(first_response_sent_at) - julianday(created_at)) * 86400) AS aggregate')->value('aggregate'),
            'sqlsrv' => $averageQuery->selectRaw('AVG(DATEDIFF(SECOND, created_at, first_response_sent_at)) AS aggregate')->value('aggregate'),
            default => null,
        };

        return response()->json(['status' => 'success', 'data' => [
            'awaiting_first_response' => $activeUnanswered()->whereNotExists($hasUnconfirmedEmail)->whereNotExists($hasUnconfirmedRespondIo)->count(),
            'first_response_target_minutes' => $targetMinutes,
            'past_first_response_target' => $pastFirstResponse,
            'response_delivery_needs_check' => $activeOpen()->where(fn ($query) => $query
                ->whereExists($hasUnconfirmedEmail)->orWhereExists($hasUnconfirmedRespondIo))->count(),
            'first_responses_last_30_days' => $responseWindow->count(),
            'average_first_response_minutes_last_30_days' => $averageSeconds === null ? null : round((float) $averageSeconds / 60, 1),
        ]]);
    }

    public function update(UpdateInquiryRequest $request, Inquiry $inquiry): JsonResponse
    {
        $this->assertInquiryCompany($inquiry);
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        DB::transaction(function () use ($inquiry, $data, $request): void {
            $locked = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $companyId = app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id;
            abort_unless($companyId && $locked->company_id === $companyId, 404);
            if (($data['source'] ?? null) === 'respond_io' && $locked->source !== 'respond_io') {
                $this->ensureRespondIoEnabled();
            }
            if (array_key_exists('customer_id', $data) && $data['customer_id'] !== $locked->customer_id) {
                $customerId = $data['customer_id'];
                if ($locked->booking_id) {
                    abort_unless((string) DB::table('bookings')->where('id', $locked->booking_id)->value('customer_id') === (string) $customerId,
                        422, 'This inquiry is linked to a booking for a different customer.');
                }
                if ($customerId !== null) {
                    $customer = Customer::query()->whereKey($customerId)->whereNull('deleted_at')->firstOrFail();
                    $this->assertCustomerInCompany($customer, $companyId);
                }
                $payload = $locked->payload ?? [];
                $linkEvent = [
                    'from_customer_id' => $locked->customer_id,
                    'to_customer_id' => $customerId,
                    'actor_user_id' => $request->user()->id,
                    'changed_at' => now()->toIso8601String(),
                ];
                $payload['customer_link'] = $linkEvent;
                $payload['customer_link_history'][] = $linkEvent;
                $data['payload'] = $payload;
            }
            $locked->update($data);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry updated',
            'data' => ['inquiry' => new InquiryResource($inquiry->fresh(['firstResponseSavedBy', 'booking']))]
        ]);
    }

    public function customerOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inquiry_id' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'No active business is configured.');
        $phoneCallingCode = \App\Services\CompanyCustomerScope::phoneCallingCode((string) $companyId);
        if (! empty($data['inquiry_id'])) {
            Inquiry::query()->where('company_id', $companyId)->findOrFail($data['inquiry_id']);
        }
        $customers = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')
            ->whereNull('customers.deleted_at')
            ->where(function ($scope) use ($companyId): void {
                $scope->whereExists(fn ($q) => $q->selectRaw('1')->from('customer_relationship_owner_assignments as owner')
                    ->whereColumn('owner.customer_id', 'customers.id')->where('owner.company_id', $companyId))
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('sales_opportunities as opportunity')
                        ->whereColumn('opportunity.customer_id', 'customers.id')->where('opportunity.company_id', $companyId)->whereNull('opportunity.deleted_at'))
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('inquiries as linked_inquiry')
                        ->whereColumn('linked_inquiry.customer_id', 'customers.id')->where('linked_inquiry.company_id', $companyId)->whereNull('linked_inquiry.deleted_at'))
                    ->orWhereExists(function ($booking) use ($companyId): void {
                        $booking->selectRaw('1')->from('bookings')->whereColumn('bookings.customer_id', 'customers.id')->whereNull('bookings.deleted_at');
                        \App\Services\CompanyBookingScope::constrain($booking, 'bookings', $companyId);
                    });
            })
            ->when(! empty($data['selected_id']), fn ($query) => $query->where('customers.id', $data['selected_id']))
            ->when(empty($data['selected_id']) && ! empty($data['search']), function ($query) use ($data, $phoneCallingCode): void {
                $term = '%' . addcslashes(trim($data['search']), '%_\\') . '%';
                $emailTerm = strtolower($term);
                $phoneDigits = preg_replace('/\D+/', '', $data['search']);
                $phoneSuffix = strlen($phoneDigits) >= 8 ? substr($phoneDigits, -8) : '';
                $query->where(fn ($search) => $search->where('users.first_name', 'like', $term)
                    ->orWhere('users.last_name', 'like', $term)->orWhereRaw('LOWER(users.email) LIKE ?', [$emailTerm])
                    ->orWhere('users.phone', 'like', $term)->orWhere('customers.code', 'like', $term)
                    ->when($phoneDigits !== '', fn ($search) => $search->orWhereRaw(
                        PhoneNumberMatch::sqlExpression('users.phone', $phoneCallingCode).' LIKE ?',
                        ['%' . $phoneDigits . '%'],
                    ))
                    // shortcut: last-eight-digit matches can collide; use only to surface candidates for staff review until country-aware parsing is available.
                    ->when($phoneSuffix !== '', fn ($search) => $search->orWhereRaw(
                        PhoneNumberMatch::sqlExpression('users.phone', $phoneCallingCode).' LIKE ?', ['%'.$phoneSuffix.'%'],
                    )));
            })
            ->select(['customers.id', 'customers.code', 'users.first_name', 'users.last_name', 'users.email', 'users.phone'])
            ->orderBy('users.first_name')->orderBy('users.last_name')->paginate($data['per_page'] ?? 25);
        $customers->getCollection()->transform(fn ($customer) => [
            'value' => (string) $customer->id,
            'label' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) . ($customer->code ? ' · ' . $customer->code : ''),
            'metadata' => array_filter(['Email' => $customer->email, 'Phone' => $customer->phone]),
        ]);

        return response()->json(['status' => 'success', 'data' => $customers]);
    }

    public function bookingOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'uuid'], 'inquiry_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'No active business is configured.');
        if (! empty($data['inquiry_id'])) {
            $inquiry = Inquiry::query()->where('company_id', $companyId)->findOrFail($data['inquiry_id']);
            abort_unless((string) $inquiry->customer_id === (string) $data['customer_id'], 422, 'Choose a booking for the linked customer.');
        }
        $bookings = DB::table('bookings')->where('bookings.customer_id', $data['customer_id'])
            ->whereNull('bookings.deleted_at')
            ->when(! empty($data['selected_id']), fn ($query) => $query->where('bookings.id', $data['selected_id']))
            ->when(empty($data['selected_id']) && ! empty($data['search']), function ($query) use ($data): void {
                $term = '%' . addcslashes(trim($data['search']), '%_\\') . '%';
                $query->where(fn ($search) => $search->where('bookings.booking_number', 'like', $term)
                    ->orWhere('bookings.log_code', 'like', $term));
            })
            ->select(['bookings.id', 'bookings.booking_number', 'bookings.log_code', 'bookings.created_at'])
            ->orderByDesc('bookings.created_at');
        \App\Services\CompanyBookingScope::constrain($bookings, 'bookings', $companyId);
        $bookings = $bookings->paginate($data['per_page'] ?? 25);
        $bookings->getCollection()->transform(fn ($booking) => [
            'value' => (string) $booking->id,
            'label' => $booking->booking_number ?: ($booking->log_code ? 'Draft ' . $booking->log_code : 'Booking'),
            'metadata' => ['Received' => $booking->created_at],
        ]);

        return response()->json(['status' => 'success', 'data' => $bookings]);
    }

    public function createCustomerFromInquiry(Request $request, Inquiry $inquiry, UserContextService $contexts): JsonResponse
    {
        abort_unless($request->user()->can('customers.create'), 403, 'Customer creation permission is required.');
        $request->validate([
            'confirm_new_person' => ['required', 'accepted'],
            'allow_shared_phone_duplicate' => ['sometimes', 'boolean'],
            'link_existing_account' => ['sometimes', 'boolean'],
        ]);
        $this->assertInquiryCompany($inquiry);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id;
        abort_unless($companyId, 409, 'No active business is configured.');
        $phoneCallingCode = \App\Services\CompanyCustomerScope::phoneCallingCode((string) $companyId);

        try {
            [$inquiry, $created, $createdUserId, $linkedExisting] = DB::transaction(function () use ($request, $inquiry, $companyId, $contexts): array {
                abort_unless(app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id === $companyId, 409, 'Business changed; retry customer creation.');
                $locked = Inquiry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($inquiry->id);
                if ($locked->customer_id) {
                    return [$locked, false, null, data_get($locked->payload, 'customer_link.action') === 'linked_existing_customer_account'];
                }

                $name = trim((string) $locked->name);
                $phone = trim((string) $locked->phone);
                abort_if($name === '', 422, 'Add the customer name to the inquiry first.');
                $email = trim((string) $locked->email);
                $email = $email !== '' ? Str::lower($email) : null;
                abort_if($request->boolean('link_existing_account') && ! $email && $phone === '', 422, 'An email address or phone number is required to link an existing customer account.');
                abort_if($phone === '' && ! $email, 422, 'Add a phone number or email address to the inquiry first.');
                abort_if(strlen($phone) > 20, 422, 'Shorten the phone number to 20 characters or less before creating the customer.');
                $normalizedPhone = PhoneNumberMatch::normalized($phone, $phoneCallingCode);
                $phoneColumn = PhoneNumberMatch::sqlExpression('users.phone', $phoneCallingCode);
                $phoneMatches = Customer::query()->join('users', 'users.id', '=', 'customers.user_id')
                    ->whereNull('customers.deleted_at')->whereNull('users.deleted_at')
                    ->whereRaw($phoneColumn.' = ?', [$normalizedPhone]);
                \App\Services\CompanyCustomerScope::constrain($phoneMatches, 'customers', $companyId);
                if ($normalizedPhone !== '' && $phoneMatches->exists() && ! $request->boolean('allow_shared_phone_duplicate')) {
                    throw new HttpResponseException(response()->json([
                        'status' => 'error',
                        'code' => 'phone_match_requires_review',
                        'message' => 'A customer in this business already uses this phone. Search and link that person, or confirm this is a different person sharing the number.',
                    ], 409));
                }
                if ($email) {
                    $existingUser = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                    if ($existingUser) {
                        $existingCustomer = Customer::query()->where('user_id', $existingUser->id)
                            ->whereNull('deleted_at')
                            ->whereHas('user', fn ($user) => $user->whereNull('deleted_at'))
                            ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts')
                                ->whereColumn('user_contexts.context_id', 'customers.id')
                                ->whereColumn('user_contexts.user_id', 'customers.user_id')
                                ->where('user_contexts.context_type', 'customer')->where('user_contexts.is_active', true)
                                ->whereNull('user_contexts.deleted_at'))
                            ->lockForUpdate()->first();
                        if ($existingCustomer && ! $request->boolean('link_existing_account')) {
                            throw new HttpResponseException(response()->json([
                                'status' => 'error',
                                'code' => 'customer_exists_elsewhere',
                                'match_type' => 'email',
                                'message' => 'A customer account already uses this email. Review the inquiry and confirm if you want to link that account to this business.',
                            ], 409));
                        }
                        abort_unless($existingCustomer && $request->boolean('link_existing_account'), 409,
                            'An account already uses this email, but it cannot be linked as a customer. Ask a manager to review it.');

                        return [$this->linkExistingInquiryCustomer($locked, $existingCustomer, $companyId, (string) $request->user()->id), false, null, true];
                    }
                }

                if (! $email && $normalizedPhone !== '' && ! $request->boolean('allow_shared_phone_duplicate')) {
                    $phoneMatches = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')
                        ->whereNull('customers.deleted_at')->whereNull('users.deleted_at')
                        ->whereRaw(PhoneNumberMatch::sqlExpression('users.phone', $phoneCallingCode).' = ?', [$normalizedPhone])
                        ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts')
                            ->whereColumn('user_contexts.context_id', 'customers.id')
                            ->whereColumn('user_contexts.user_id', 'customers.user_id')
                            ->where('user_contexts.context_type', 'customer')->where('user_contexts.is_active', true)
                            ->whereNull('user_contexts.deleted_at'))
                        ->select(['customers.id', 'customers.user_id'])->limit(2)->get();
                    if ($phoneMatches->count() > 1) {
                        throw new HttpResponseException(response()->json([
                            'status' => 'error', 'code' => 'phone_match_requires_review',
                            'message' => 'More than one customer account uses this phone number. Ask a manager to review it, or confirm this is a different person sharing the number before creating a separate profile.',
                        ], 409));
                    }
                    if ($phoneMatches->count() === 1) {
                        if (! $request->boolean('link_existing_account')) {
                            throw new HttpResponseException(response()->json([
                                'status' => 'error', 'code' => 'customer_exists_elsewhere', 'match_type' => 'phone',
                                'message' => 'A customer account already uses this phone number. Review the inquiry and confirm whether to link that customer or create a separate profile for a different person.',
                            ], 409));
                        }
                        $match = $phoneMatches->first();
                        $existingUser = User::query()->whereKey($match->user_id)->lockForUpdate()->first();
                        $existingCustomer = $existingUser
                            ? Customer::query()->whereKey($match->id)->where('user_id', $existingUser->id)
                                ->whereHas('user', fn ($user) => $user->whereNull('deleted_at'))
                                ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts')
                                    ->whereColumn('user_contexts.context_id', 'customers.id')
                                    ->whereColumn('user_contexts.user_id', 'customers.user_id')
                                    ->where('user_contexts.context_type', 'customer')->where('user_contexts.is_active', true)
                                    ->whereNull('user_contexts.deleted_at'))
                                ->lockForUpdate()->first()
                            : null;
                        abort_unless($existingCustomer, 409, 'The matching customer account is no longer available. Search for the customer or ask a manager to review the inquiry.');
                        return [$this->linkExistingInquiryCustomer($locked, $existingCustomer, $companyId, (string) $request->user()->id), false, null, true];
                    }
                }
                abort_if($request->boolean('link_existing_account'), 409, 'The matching customer account is no longer available. Search for the customer or ask a manager to review the inquiry.');

                [$firstName, $lastName] = array_pad(preg_split('/\s+/', $name, 2) ?: [], 2, null);
                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => Hash::make(Str::random(64)),
                    'email_verified_at' => null,
                    'phone_verified_at' => null,
                    'is_active' => false,
                    'is_verified' => false,
                    'created_user_id' => $request->user()->id,
                ]);
                $context = $contexts->switchContext($user, 'customer', [
                    'created_user_id' => $request->user()->id,
                    'default_payment_arrangement' => 'account_credit',
                ], (string) $request->user()->id);
                $customerId = (string) $context->context_id;
                DB::table('customer_relationship_owner_assignments')->insert([
                    'id' => (string) Str::uuid(), 'customer_id' => $customerId, 'company_id' => $companyId,
                    'owner_sales_profile_id' => null, 'assigned_by_user_id' => $request->user()->id,
                    'reason' => 'Created from an inquiry; assign a customer owner after review.',
                    'effective_from' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                $payload = $locked->payload ?? [];
                $event = [
                    'from_customer_id' => null, 'to_customer_id' => $customerId,
                    'actor_user_id' => $request->user()->id, 'changed_at' => now()->toIso8601String(),
                    'action' => 'created_from_inquiry',
                ];
                $payload['customer_link'] = $event;
                $payload['customer_link_history'][] = $event;
                $locked->update(['customer_id' => $customerId, 'payload' => $payload, 'updated_user_id' => $request->user()->id]);
                return [$locked->fresh(), true, (string) $user->id, false];
            });
        } catch (QueryException $exception) {
            if ((string) $inquiry->email !== '' && User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $inquiry->email))])->exists()) {
                $isCustomerAccount = Customer::query()->join('users', 'users.id', '=', 'customers.user_id')
                    ->whereNull('customers.deleted_at')->whereNull('users.deleted_at')
                    ->whereRaw('LOWER(users.email) = ?', [Str::lower(trim((string) $inquiry->email))])
                    ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts')
                        ->whereColumn('user_contexts.context_id', 'customers.id')
                        ->whereColumn('user_contexts.user_id', 'customers.user_id')
                        ->where('user_contexts.context_type', 'customer')->where('user_contexts.is_active', true)
                        ->whereNull('user_contexts.deleted_at'))->exists();
                if ($isCustomerAccount) {
                    throw new HttpResponseException(response()->json([
                        'status' => 'error',
                        'code' => 'customer_exists_elsewhere',
                        'match_type' => 'email',
                        'message' => 'A customer account already uses this email. Review the inquiry and confirm if you want to link that account to this business.',
                    ], 409));
                }
                abort(409, 'An account already uses this email. Search for its customer record or ask a manager to review it.');
            }
            throw $exception;
        }

        $verificationEmailStatus = 'no_email';
        if ($createdUserId) {
            $customerUser = User::query()->findOrFail($createdUserId);
            if ($customerUser->email) {
                try {
                    app(AuthService::class)->sendEmailVerification($customerUser);
                    $verificationEmailStatus = 'queued';
                } catch (Throwable $exception) {
                    report($exception);
                    $verificationEmailStatus = 'failed';
                }
            }
        }

        return response()->json(['status' => 'success', 'data' => [
            'inquiry' => new InquiryResource($inquiry->fresh()), 'created' => $created,
            'portal_access' => $created ? 'inactive_pending_verification' : null,
            'verification_email_status' => $verificationEmailStatus,
            'linked_existing' => $linkedExisting,
        ]], $created ? 201 : 200);
    }

    public function destroy(Inquiry $inquiry): JsonResponse
    {
        $this->assertInquiryCompany($inquiry);
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
        [$assignedInquiry, $assignmentChanged] = DB::transaction(function () use ($inquiry, $data, $request, $companyId): array {
            abort_unless(app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id === $companyId, 409, 'Default company changed; retry assignment.');
            $locked = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->company_id === $companyId, 404);
            abort_unless($this->eligibleAssignees($companyId)->where('users.id', $data['assigned_to'])->lockForUpdate()->first(['users.id']), 422, 'Select an active Staff assignee.');
            $fromUserId = $locked->assigned_to;
            $locked->update([
                'assigned_to' => $data['assigned_to'],
                'status' => $locked->status ?: 'open',
                'updated_user_id' => $request->user()->id,
            ]);
            if ($fromUserId !== $data['assigned_to']) {
                DB::table('inquiry_assignment_events')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'inquiry_id' => $locked->id,
                    'from_user_id' => $fromUserId,
                    'to_user_id' => $data['assigned_to'],
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'assigned',
                    'reason' => 'Manual inquiry assignment.',
                    'idempotency_key' => null,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return [$locked->fresh(), $fromUserId !== $data['assigned_to']];
        });

        if ($assignmentChanged) app(\App\Services\InquiryAssignmentNotificationService::class)->notifyAssigned($assignedInquiry);
        $respondIoSync = app(\App\Services\RespondIoInquiryAssignmentSync::class)->sync($assignedInquiry);

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry assigned',
            'data' => [
                'inquiry' => new InquiryResource($assignedInquiry->load('assignedUser.staff')),
                'respond_io_assignment_sync' => $respondIoSync,
            ]
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
        $query = app(InquiryAssignmentService::class)->eligibleAssignees($companyId);
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
        $company = app(SingleCompanyScope::class)->activeDefaultCompany();
        abort_unless($company, 409, 'Set one active default company before using Inquiry Staff options.');
        abort_unless(app(InquiryAssignmentService::class)->eligibleAssignees($company->id)->where('users.id', $request->user()->id)->exists(), 403);

        return $company->id;
    }

    private function eligibleAssignees(string $companyId)
    {
        return app(InquiryAssignmentService::class)->eligibleAssignees($companyId);
    }

    private function ensureRespondIoEnabled(): void
    {
        abort_unless(filter_var(app(\App\Services\WebsiteSettingsService::class)
            ->get('feature_respond_io_enabled', false), FILTER_VALIDATE_BOOL), 422,
            'Respond.io is turned off in Business Settings.');
    }

    public function updateStatus(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->assertInquiryCompany($inquiry);
        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,closed,archived'],
        ]);
        $inquiry = DB::transaction(function () use ($request, $inquiry, $data): Inquiry {
            $locked = Inquiry::query()->where('company_id', $inquiry->company_id)->lockForUpdate()->findOrFail($inquiry->id);
            $fromStatus = $locked->status;
            $locked->update(['status' => $data['status'], 'updated_user_id' => $request->user()->id]);
            app(\App\Services\InquiryStatusHistoryService::class)->record(
                $locked, $fromStatus, $locked->status, (string) $request->user()->id, 'Status changed by an employee.',
            );
            return $locked->fresh();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry status updated',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    public function respond(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->assertInquiryCompany($inquiry);
        $data = $request->validate([
            'message' => ['required', 'string'],
            'status' => ['sometimes', 'nullable', 'in:open,in_progress,closed,archived'],
        ]);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id;
        abort_unless($companyId && $inquiry->company_id === $companyId, 404);
        $inquiry = DB::transaction(function () use ($inquiry, $data, $request, $companyId): Inquiry {
            $locked = Inquiry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($inquiry->id);
            $attributes = [
                'response' => $data['message'],
                'status' => $data['status'] ?? 'in_progress',
                'updated_user_id' => $request->user()->id,
            ];
            $fromStatus = $locked->status;
            if ($locked->response === null) {
                $attributes['first_response_saved_at'] = now();
                $attributes['first_response_saved_by_user_id'] = $request->user()->id;
            }
            $locked->update($attributes);
            app(\App\Services\InquiryStatusHistoryService::class)->record(
                $locked, $fromStatus, $locked->status, (string) $request->user()->id, 'Response saved.',
            );
            return $locked->fresh(['firstResponseSavedBy']);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Response draft saved. It has not been sent.',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    public function sendEmailResponse(Request $request, Inquiry $inquiry, MailDispatchService $mail): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $this->assertInquiryCompany($inquiry);
        abort_if($inquiry->source === 'respond_io', 422, 'Send Respond.io replies through the connected conversation.');
        $recipient = trim((string) $inquiry->email);
        abort_unless(filter_var($recipient, FILTER_VALIDATE_EMAIL), 422, 'Add a valid customer email before sending a response.');
        $companyId = (string) $inquiry->company_id;

        try {
            [$outbound, $shouldSend] = DB::transaction(function () use ($request, $data, $inquiry, $recipient, $companyId): array {
                $locked = Inquiry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($inquiry->id);
                $existing = InquiryOutboundResponse::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless($existing->company_id === $companyId && $existing->inquiry_id === $locked->id
                        && $existing->recipient_email === $recipient && $existing->message === $data['message'],
                        409, 'This send key was already used for a different response.');
                    return [$existing, false];
                }

                $outbound = InquiryOutboundResponse::create([
                    'company_id' => $companyId,
                    'inquiry_id' => $locked->id,
                    'sent_by_user_id' => $request->user()->id,
                    'idempotency_key' => $data['idempotency_key'],
                    'recipient_email' => $recipient,
                    'message' => $data['message'],
                    'status' => 'sending',
                ]);
                $locked->update(['response' => $data['message'], 'updated_user_id' => $request->user()->id]);

                return [$outbound, true];
            });
        } catch (QueryException $exception) {
            $outbound = InquiryOutboundResponse::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if (! $outbound) throw $exception;
            abort_unless($outbound->company_id === $companyId && $outbound->inquiry_id === $inquiry->id
                && $outbound->recipient_email === $recipient && $outbound->message === $data['message'],
                409, 'This send key was already used for a different response.');
            $shouldSend = false;
        }

        if (! $shouldSend) {
            if ($outbound->status === 'sent') {
                return response()->json(['status' => 'success', 'message' => 'This response was already sent.', 'data' => [
                    'delivery_status' => 'sent', 'sent_at' => $outbound->sent_at,
                ]]);
            }
            return response()->json(['status' => 'error', 'code' => 'delivery_not_confirmed',
                'message' => 'This response may already be sending. Check the customer email record before trying again.',
                'data' => ['delivery_status' => $outbound->status]], 409);
        }

        try {
            $mail->sendToCustomer($recipient, new InquiryResponseMail($inquiry, $data['message']), false);
        } catch (Throwable $exception) {
            $outbound->update(['status' => 'uncertain', 'failure_reason' => 'Mail delivery could not be confirmed.']);
            report($exception);

            return response()->json(['status' => 'error', 'code' => 'delivery_uncertain',
                'message' => 'Delivery could not be confirmed. Check before sending again.',
                'data' => ['delivery_status' => 'uncertain']], 502);
        }

        $sentAt = now();
        DB::transaction(function () use ($outbound, $inquiry, $data, $request, $companyId, $sentAt): void {
            $locked = InquiryOutboundResponse::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($outbound->id);
            if ($locked->status !== 'sending') return;
            $locked->update(['status' => 'sent', 'sent_at' => $sentAt]);
            $lockedInquiry = Inquiry::query()->where('company_id', $companyId)->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $inquiryChanges = ['response' => $data['message'], 'responded_at' => $sentAt, 'updated_user_id' => $request->user()->id];
            if (! $lockedInquiry->first_response_sent_at) $inquiryChanges['first_response_sent_at'] = $sentAt;
            $lockedInquiry->update($inquiryChanges);
        });

        return response()->json(['status' => 'success', 'message' => 'Response email sent.', 'data' => [
            'delivery_status' => 'sent', 'sent_at' => $sentAt,
        ]]);
    }

    public function markRead(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->assertInquiryCompany($inquiry);
        $inquiry = DB::transaction(function () use ($request, $inquiry): Inquiry {
            $locked = Inquiry::query()->where('company_id', $inquiry->company_id)->lockForUpdate()->findOrFail($inquiry->id);
            $fromStatus = $locked->status;
            $locked->update(['status' => $locked->status ?: 'read', 'updated_user_id' => $request->user()->id]);
            app(\App\Services\InquiryStatusHistoryService::class)->record(
                $locked, $fromStatus, $locked->status, (string) $request->user()->id, 'Marked as read.',
            );
            return $locked->fresh();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry marked as read',
            'data' => ['inquiry' => new InquiryResource($inquiry)]
        ]);
    }

    private function assertInquiryCompany(Inquiry $inquiry): void
    {
        abort_unless($inquiry->company_id && $inquiry->company_id === app(SingleCompanyScope::class)->activeDefaultCompany()?->id, 404);
    }

    private function linkExistingInquiryCustomer(Inquiry $inquiry, Customer $customer, string $companyId, string $actorId): Inquiry
    {
        $hasBusinessLink = DB::table('customer_relationship_owner_assignments')->where('customer_id', $customer->id)
            ->where('company_id', $companyId)->exists();
        if (! $hasBusinessLink) {
            DB::table('customer_relationship_owner_assignments')->insert([
                'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'company_id' => $companyId,
                'owner_sales_profile_id' => null, 'assigned_by_user_id' => $actorId,
                'reason' => 'Linked from an inquiry; assign a customer owner after review.',
                'effective_from' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $payload = $inquiry->payload ?? [];
        $event = [
            'from_customer_id' => null, 'to_customer_id' => (string) $customer->id,
            'actor_user_id' => $actorId, 'changed_at' => now()->toIso8601String(),
            'action' => 'linked_existing_customer_account',
        ];
        $payload['customer_link'] = $event;
        $payload['customer_link_history'][] = $event;
        $inquiry->update(['customer_id' => $customer->id, 'payload' => $payload, 'updated_user_id' => $actorId]);

        return $inquiry->fresh();
    }

    private function assertCustomerInCompany(Customer $customer, string $companyId): void
    {
        $customerId = $customer->id;
        $belongsToCompany = DB::table('customer_relationship_owner_assignments')->where('customer_id', $customerId)->where('company_id', $companyId)->exists()
            || DB::table('sales_opportunities')->where('customer_id', $customerId)->where('company_id', $companyId)->whereNull('deleted_at')->exists()
            || DB::table('inquiries')->where('customer_id', $customerId)->where('company_id', $companyId)->whereNull('deleted_at')->exists()
            || DB::table('sales_booking_attributions as attribution')->join('bookings', 'bookings.id', '=', 'attribution.booking_id')
                ->where('bookings.customer_id', $customerId)->where('attribution.company_id', $companyId)->exists()
            || DB::table('bookings')->join('staff', 'staff.user_id', '=', 'bookings.responsible_user_id')
                ->where('bookings.customer_id', $customerId)->where('staff.company_id', $companyId)
                ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')->exists();
        abort_unless($belongsToCompany, 422, 'Choose a customer already associated with this business.');
    }
}
