<?php
// app/Http/Controllers/Api/PhoneCallController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Models\PhoneCallAssignment;
use App\Models\PhoneCallFollowUpAttempt;
use App\Models\User;
use App\Http\Requests\PhoneCall\CreatePhoneCallRequest;
use App\Http\Requests\PhoneCall\UpdatePhoneCallRequest;
use App\Http\Resources\PhoneCall\PhoneCallResource;
use App\Models\Sales\SalesProfile;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesCrmService;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\CompanyBookingScope;
use App\Services\CompanyCustomerScope;
use App\Services\CompanySettingsAccessService;
use App\Services\WebsiteSettingsService;
use App\Notifications\PhoneCallAssignedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PhoneCallController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:phone-calls.view')->only(['index']);
        $this->middleware('permission:phone-calls.view|phone-calls.edit')->only(['show']);
        $this->middleware('permission:phone-calls.create')->only(['store']);
        $this->middleware('permission:phone-calls.edit')->only(['update', 'assignmentOptions', 'assign', 'createInquiryFromCall']);
        $this->middleware('permission:phone-calls.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['unassigned_only' => ['nullable', 'boolean'], 'mine_only' => ['nullable', 'boolean'], 'needs_follow_up' => ['nullable', 'boolean'], 'unassigned_follow_up' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->selectedCompanyId();
        $q = PhoneCall::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->with('assignedTo')
            ->when($request->user()->can('sales.crm.view'), fn ($query) => $query->with('salesOpportunity'))
            ->when($request->boolean('unassigned_only'), fn ($query) => $query->whereNull('assigned_user_id'));
        if ($request->boolean('mine_only')) {
            $q->where('assigned_user_id', $request->user()->id);
        }
        if ($request->boolean('needs_follow_up')) {
            $q->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->whereNull('follow_up_completed_at');
        }
        if ($request->boolean('unassigned_follow_up')) {
            $eligible = User::permission('phone-calls.view')->whereHas('staff', fn ($staff) => $staff
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
                ->where('is_active', true)->whereNull('deleted_at')->select('id');
            $q->where(fn ($queue) => $queue->whereNull('assigned_user_id')->orWhereNotIn('assigned_user_id', $eligible))
                ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->whereNull('follow_up_completed_at');
        }
        return PhoneCallResource::collection($q->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function assignmentOptions(): JsonResponse
    {
        $companyId = $this->selectedCompanyId();
        $employees = DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
            ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'staff.company_id', $companyId))->whereNull('staff.deleted_at')
            ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
            ->whereNull('users.deleted_at')->where('users.is_active', true)
            ->select(['users.id', 'users.first_name', 'users.last_name', 'staff.code'])->orderBy('users.first_name')->orderBy('users.last_name')->get();
        $eligible = User::permission('phone-calls.view')->whereIn('id', $employees->pluck('id'))->pluck('id')->map(fn ($id) => (string) $id)->all();
        return response()->json(['status' => 'success', 'data' => $employees->whereIn('id', $eligible)->values()]);
    }

    public function assign(Request $request, PhoneCall $phoneCall): JsonResponse
    {
        $data = $request->validate([
            'assigned_user_id' => ['present', 'nullable', 'uuid', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $companyId = $this->selectedCompanyId();

        [$call, $assignmentChanged] = DB::transaction(function () use ($request, $phoneCall, $data, $companyId): array {
            $call = PhoneCall::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->lockForUpdate()->findOrFail($phoneCall->id);
            if ((string) $call->assigned_user_id === (string) $data['assigned_user_id']) return [$call, false];
            if ($data['assigned_user_id'] !== null) {
                $eligible = DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
                    ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'staff.company_id', $companyId))->where('users.id', $data['assigned_user_id'])
                    ->whereNull('staff.deleted_at')
                    ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
                    ->whereNull('users.deleted_at')->where('users.is_active', true)->lockForUpdate()->exists()
                    && User::permission('phone-calls.view')->whereKey($data['assigned_user_id'])->exists();
                abort_unless($eligible, 422, 'Choose an active employee who can access calls in this business.');
            }
            $fromUserId = $call->assigned_user_id;
            $call->update(['assigned_user_id' => $data['assigned_user_id'], 'updated_user_id' => $request->user()->id]);
            PhoneCallAssignment::create([
                    'phone_call_id' => $call->id, 'company_id' => $companyId,
                'from_user_id' => $fromUserId, 'to_user_id' => $data['assigned_user_id'],
                'actor_user_id' => $request->user()->id, 'reason' => trim($data['reason']), 'event_at' => now(),
            ]);
            return [$call, true];
        });

        if ($assignmentChanged && $call->assigned_user_id) {
            try {
                $userId = User::permission('phone-calls.view')->whereKey($call->assigned_user_id)->where('is_active', true)->whereNull('deleted_at')->value('id');
                if ($userId) User::query()->whereKey($userId)->first()?->notify(new PhoneCallAssignedNotification($call));
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('Phone call assignment notification could not be queued', [
                    'phone_call_id' => $call->id, 'user_id' => $call->assigned_user_id, 'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Call assignment updated.', 'data' => [
            'call' => new PhoneCallResource($call->load('assignedTo')),
        ]]);
    }

    public function store(CreatePhoneCallRequest $request, SalesAccessScope $scope, SalesCrmService $crm): JsonResponse
    {
        $data = $request->validated();
        $followUp = $data['follow_up'] ?? null;
        unset($data['follow_up']);
        if ($followUp) {
            abort_unless($request->user()->can('sales.crm.manage'), 403, 'Sales permission is required to create a follow-up task.');
            abort_unless(! empty($data['customer_id']), 422, 'Link this call to a customer before creating a follow-up.');
        }

        $call = DB::transaction(function () use ($data, $followUp, $request, $scope, $crm): PhoneCall {
            $companyId = $this->selectedCompanyId();
            $this->assertCallLinksInCompany($data['customer_id'] ?? null, $data['booking_id'] ?? null, (string) $companyId);
            $data['created_user_id'] = $request->user()->id;
            $data['company_id'] = $companyId;
            $data['assigned_user_id'] = $this->eligibleCallEmployeeId((string) $request->user()->id, (string) $companyId);
            $call = PhoneCall::create($data);
            if ($call->assigned_user_id) {
                PhoneCallAssignment::create([
                    'phone_call_id' => $call->id, 'company_id' => $companyId,
                    'from_user_id' => null, 'to_user_id' => $call->assigned_user_id,
                    'actor_user_id' => $request->user()->id, 'reason' => 'Assigned to the employee who logged the call.', 'event_at' => now(),
                ]);
            }
            if (! $followUp) return $call;

            $companyId = $this->selectedCompanyId();
            $assignment = DB::table('customer_relationship_owner_assignments')->where('customer_id', $call->customer_id)
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('ended_at')->whereNotNull('owner_sales_profile_id')
                ->orderByDesc('effective_from')->lockForUpdate()->first();
            abort_unless($assignment, 422, 'Assign a Sales owner to this customer before creating a follow-up.');
            $profile = SalesProfile::query()->findOrFail($assignment->owner_sales_profile_id);
            $profileIds = $scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team', (string) $assignment->company_id);
            abort_unless($profileIds === null || in_array((string) $profile->id, $profileIds, true), 403,
                'The customer owner is outside your Sales team. Ask a manager to assign the follow-up.');
            $task = $crm->createTask([
                'company_id' => (string) $assignment->company_id,
                'owner_sales_profile_id' => (string) $profile->id,
                'customer_id' => (string) $call->customer_id,
                'booking_id' => $call->booking_id,
                'creation_idempotency_key' => 'phone-call-follow-up:'.$call->id,
                'title' => $followUp['title'],
                'description' => $call->summary,
                'priority' => 'normal',
                'due_at' => $followUp['due_at'],
            ], (string) $request->user()->id, $profileIds);
            $call->update(['follow_up_task_id' => $task->id]);
            return $call;
        });

        return response()->json([
            'status'=>'success',
            'message'=>'Phone call logged',
            'data'=>['call'=>new PhoneCallResource($call)]
        ],201);
    }

    public function show(PhoneCall $phoneCall): JsonResponse
    {
        $this->assertCompanyCall($phoneCall);
        $phoneCall->load(['assignedTo', 'assignments.fromUser', 'assignments.toUser', 'assignments.actor', 'followUpAttempts.actor']);
        if (request()->user()->can('sales.crm.view')) $phoneCall->load('salesOpportunity');
        return response()->json([
            'status'=>'success',
            'data'=>['call'=>new PhoneCallResource($phoneCall)]
        ]);
    }

    public function scheduleFollowUp(Request $request, PhoneCall $phoneCall, SalesAccessScope $scope, SalesCrmService $crm): JsonResponse
    {
        $this->assertCompanyCall($phoneCall);
        $data = $request->validate([
            'follow_up_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $companyId = $this->selectedCompanyId();
        $businessTimezone = app(SalesPolicySettingsService::class)->businessTimezone($companyId) ?? config('app.timezone');
        $followUpDate = Carbon::createFromFormat('!Y-m-d', $data['follow_up_date'], $businessTimezone);
        abort_if($followUpDate->lt(now($businessTimezone)->startOfDay()), 422, 'Choose today or a future date for the callback.');
        $result = DB::transaction(function () use ($request, $phoneCall, $data, $scope, $crm, $followUpDate) {
            $call = PhoneCall::query()->lockForUpdate()->findOrFail($phoneCall->id);
            if ($call->follow_up_task_id) return ['task' => $call->followUpTask, 'callback' => false];

            $companyId = $this->selectedCompanyId();
            $assignment = $call->customer_id ? DB::table('customer_relationship_owner_assignments')->where('customer_id', $call->customer_id)
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('ended_at')->whereNotNull('owner_sales_profile_id')
                ->orderByDesc('effective_from')->lockForUpdate()->first() : null;

            $dueAt = $followUpDate->copy()->startOfDay();
            $notes = trim((string) ($data['notes'] ?? ''));
            if (! $assignment || ! $request->user()->can('sales.crm.manage')) {
                $employeeCanAccessCall = $call->assigned_user_id && User::permission('phone-calls.view')
                    ->whereKey($call->assigned_user_id)->where('is_active', true)->whereNull('deleted_at')
                    ->whereHas('staff', fn ($staff) => $staff->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                        ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
                    ->exists();
                abort_unless($employeeCanAccessCall, 422, 'Assign this call to an active employee before scheduling a callback.');
                $call->update([
                    'follow_up_due_at' => $dueAt,
                    'follow_up_notes' => $notes ?: null,
                    'updated_user_id' => $request->user()->id,
                ]);
                return ['task' => null, 'callback' => true];
            }

            $profile = SalesProfile::query()->findOrFail($assignment->owner_sales_profile_id);
            $profileIds = $scope->profileIds($request->user(), 'sales.crm.manage-all', 'sales.crm.manage-team', (string) $companyId);
            abort_unless($profileIds === null || in_array((string) $profile->id, $profileIds, true), 403,
                'The customer owner is outside your Sales team. Ask a manager to assign the follow-up.');

            $description = trim(implode("\n\n", array_filter([$notes, $call->summary])));
            $bookingId = $call->booking_id;
            if ($bookingId) {
                $bookingBelongsToCompany = DB::table('sales_booking_attributions')->where('booking_id', $bookingId)->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->exists()
                    || DB::table('bookings')->where('id', $bookingId)->whereIn('responsible_user_id',
                        DB::table('staff')->select('user_id')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at'))
                        ->exists();
                if (! $bookingBelongsToCompany) $bookingId = null;
            }
            $task = $crm->createTask([
                'company_id' => (string) $companyId,
                'owner_sales_profile_id' => (string) $profile->id,
                'customer_id' => (string) $call->customer_id,
                'booking_id' => $bookingId,
                'creation_idempotency_key' => 'phone-call-follow-up:'.$call->id,
                'title' => Str::limit('Call back '.($call->client_name ?: $call->phone ?: 'customer'), 255, ''),
                'description' => $description ?: null,
                'priority' => 'normal',
                'due_at' => $dueAt->toIso8601String(),
            ], (string) $request->user()->id, $profileIds);
            $call->update(['follow_up_task_id' => $task->id, 'follow_up_due_at' => null, 'follow_up_notes' => null, 'updated_user_id' => $request->user()->id]);
            return ['task' => $task, 'callback' => false];
        });

        $phoneCall->refresh();
        if ($result['callback'] && $phoneCall->assigned_user_id) {
            try {
                User::query()->whereKey($phoneCall->assigned_user_id)->first()?->notify(new PhoneCallAssignedNotification($phoneCall));
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('Phone callback notification could not be queued', [
                    'phone_call_id' => $phoneCall->id, 'user_id' => $phoneCall->assigned_user_id, 'error' => $exception->getMessage(),
                ]);
            }
        }
        return response()->json(['status' => 'success', 'message' => $result['callback'] ? 'Callback scheduled for the assigned employee.' : 'Follow-up task scheduled.', 'data' => [
            'call' => new PhoneCallResource($phoneCall), 'task_id' => $result['task']?->id, 'callback_scheduled' => $result['callback'],
        ]]);
    }

    public function completeFollowUp(Request $request, PhoneCall $phoneCall): JsonResponse
    {
        $this->assertCompanyCall($phoneCall);
        $data = $request->validate([
            'outcome' => ['required', 'in:contacted,no_answer,not_interested,other'],
            'next_follow_up_date' => ['nullable', 'required_if:outcome,no_answer', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $companyId = $this->selectedCompanyId();
        $businessTimezone = app(SalesPolicySettingsService::class)->businessTimezone($companyId) ?? config('app.timezone');
        $nextDueAt = ! empty($data['next_follow_up_date'])
            ? Carbon::createFromFormat('!Y-m-d', $data['next_follow_up_date'], $businessTimezone)->startOfDay()
            : null;
        abort_if($nextDueAt && $nextDueAt->lt(now($businessTimezone)->startOfDay()), 422, 'Choose today or a future date for the next callback.');

        $result = DB::transaction(function () use ($request, $phoneCall, $data, $companyId, $nextDueAt): array {
            $call = PhoneCall::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->lockForUpdate()->findOrFail($phoneCall->id);
            $previous = PhoneCallFollowUpAttempt::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->where('idempotency_key', $data['idempotency_key'])->first();
            if ($previous) {
                abort_unless((string) $previous->phone_call_id === (string) $call->id, 409, 'This callback result was already used for another call.');
                return ['call' => $call, 'duplicate' => true, 'outcome' => $previous->outcome];
            }
            abort_unless(in_array($call->call_status, ['Missed', 'Notanswered'], true) && ! $call->follow_up_task_id, 422,
                'This call does not have an open callback to record.');
            abort_unless($call->assigned_user_id && ((string) $call->assigned_user_id === (string) $request->user()->id || $request->user()->can('communication.manage')), 403,
                'Only the responsible employee or a communication manager can record this callback.');
            abort_unless(! $call->follow_up_completed_at, 409, 'This callback has already been completed.');

            $call->followUpAttempts()->create([
                'company_id' => $companyId,
                'actor_user_id' => $request->user()->id,
                'idempotency_key' => $data['idempotency_key'],
                'outcome' => $data['outcome'],
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'attempted_at' => now(),
            ]);
            $call->update([
                'follow_up_due_at' => $data['outcome'] === 'no_answer' ? $nextDueAt : $call->follow_up_due_at,
                'follow_up_notes' => $data['outcome'] === 'no_answer' ? null : $call->follow_up_notes,
                'follow_up_completed_at' => $data['outcome'] === 'no_answer' ? null : now(),
                'updated_user_id' => $request->user()->id,
            ]);

            return ['call' => $call, 'duplicate' => false, 'outcome' => $data['outcome']];
        });

        $call = $result['call']->load('followUpAttempts.actor');
        if ($result['outcome'] === 'no_answer' && (string) $call->assigned_user_id !== (string) $request->user()->id) {
            try {
                User::query()->whereKey($call->assigned_user_id)->where('is_active', true)->whereNull('deleted_at')
                    ->first()?->notify(new PhoneCallAssignedNotification($call));
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('Rescheduled phone callback notification could not be queued', [
                    'phone_call_id' => $call->id, 'user_id' => $call->assigned_user_id, 'error' => $exception->getMessage(),
                ]);
            }
        }
        return response()->json(['status' => 'success', 'message' => $result['outcome'] === 'no_answer'
            ? 'No answer recorded. The next callback is on the work list.' : 'Callback result recorded.', 'data' => [
            'call' => new PhoneCallResource($call), 'duplicate' => $result['duplicate'],
        ]]);
    }

    public function createInquiryFromCall(Request $request, PhoneCall $phoneCall): JsonResponse
    {
        $data = $request->validate([
            'confirm_lead' => ['required', 'accepted'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'inquiry_type' => ['required', 'in:general,complaint,feedback'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:20000'],
            'customer_id' => ['nullable', 'uuid'],
        ]);
        $companyId = $this->selectedCompanyId();

        [$call, $inquiry, $duplicate] = DB::transaction(function () use ($request, $phoneCall, $data, $companyId): array {
            abort_unless($this->selectedCompanyId() === $companyId, 409, 'Business changed; retry call conversion.');
            $call = PhoneCall::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->lockForUpdate()->findOrFail($phoneCall->id);
            if ($call->inquiry_id) {
                return [$call, \App\Models\Inquiry::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->findOrFail($call->inquiry_id), true];
            }
            $selectedCustomerId = $data['customer_id'] ?? null;
            abort_unless(! $call->customer_id || ! $selectedCustomerId
                || (string) $call->customer_id === (string) $selectedCustomerId, 409,
                'This call is already linked to a different customer. Use the linked customer for this inquiry.');
            $customerId = $call->customer_id ?? $selectedCustomerId;
            $bookingId = $call->booking_id;
            $this->assertCallLinksInCompany($customerId, $bookingId, $companyId);

            $assigneeId = $call->assigned_user_id && in_array((string) $call->assigned_user_id,
                app(\App\Services\InquiryAssignmentService::class)->eligibleAssigneeIds($companyId), true)
                ? (string) $call->assigned_user_id : null;
            $inquiry = \App\Models\Inquiry::createWithUniqueNumber([
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'booking_id' => $bookingId,
                'assigned_to' => $assigneeId,
                'name' => trim($data['name']),
                'phone' => $data['phone'] ?? null,
                'inquiry_type' => $data['inquiry_type'],
                'subject' => trim($data['subject']),
                'message' => trim($data['message']),
                'status' => 'open',
                'priority' => 'medium',
                'source' => 'phone',
                'created_user_id' => $request->user()->id,
                'payload' => ['origin' => [
                    'kind' => 'phone_call',
                    'phone_call_id' => $call->id,
                    'external_provider' => $call->external_provider,
                    'external_call_key' => $call->external_call_key,
                ]],
            ]);

            if ($assigneeId) {
                DB::table('inquiry_assignment_events')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'inquiry_id' => $inquiry->id,
                    'from_user_id' => null,
                    'to_user_id' => $assigneeId,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'assigned',
                    'reason' => 'Inquiry created from a call assigned to this employee.',
                    'idempotency_key' => 'phone-call-inquiry-assignment:'.$call->id,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $call->update(['customer_id' => $customerId, 'inquiry_id' => $inquiry->id, 'updated_user_id' => $request->user()->id]);
            return [$call->fresh(), $inquiry, false];
        });

        if (! $duplicate && $inquiry->assigned_to) {
            app(\App\Services\InquiryAssignmentNotificationService::class)->notifyAssigned($inquiry);
        }

        return response()->json(['status' => 'success', 'duplicate' => $duplicate, 'data' => [
            'call' => new PhoneCallResource($call),
            'inquiry' => new \App\Http\Resources\Inquiry\InquiryResource($inquiry->load('booking')),
        ]], $duplicate ? 200 : 201);
    }

    public function update(UpdatePhoneCallRequest $request, PhoneCall $phoneCall): JsonResponse
    {
        $data = $request->validated();
        $this->assertCompanyCall($phoneCall);
        if (array_key_exists('booking_id', $data) || array_key_exists('customer_id', $data)) {
            $this->assertCallLinksInCompany(
                array_key_exists('customer_id', $data) ? $data['customer_id'] : $phoneCall->customer_id,
                array_key_exists('booking_id', $data) ? $data['booking_id'] : $phoneCall->booking_id,
                (string) $phoneCall->company_id,
            );
        }
        $data['updated_user_id'] = $request->user()->id;
        $phoneCall->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Phone call updated',
            'data'=>['call'=>new PhoneCallResource($phoneCall)]
        ]);
    }

    public function destroy(PhoneCall $phoneCall): JsonResponse
    {
        $this->assertCompanyCall($phoneCall);
        $phoneCall->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Phone call deleted'
        ]);
    }

    private function eligibleCallEmployeeId(string $userId, string $companyId): ?string
    {
        return User::permission('phone-calls.view')->whereKey($userId)->where('is_active', true)->whereNull('deleted_at')
            ->whereHas('staff', fn ($staff) => $staff->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
            ->value('id');
    }

    private function assertCallLinksInCompany(?string $customerId, ?string $bookingId, string $companyId): void
    {
        if ($customerId) {
            $customer = DB::table('customers as customer')->where('customer.id', $customerId)->whereNull('customer.deleted_at');
            CompanyCustomerScope::constrain($customer, 'customer', $companyId);
            abort_unless($customer->exists(), 422, 'Choose a customer associated with this business.');
        }
        if (! $bookingId) return;

        $booking = DB::table('bookings as booking')->where('booking.id', $bookingId)->whereNull('booking.deleted_at');
        CompanyBookingScope::constrain($booking, 'booking', $companyId);
        abort_unless($booking->exists(), 422, 'The linked booking is not associated with this business.');
        abort_unless($customerId && DB::table('bookings')->where('id', $bookingId)->where('customer_id', $customerId)->exists(),
            422, 'Choose a booking that belongs to this customer.');
    }

    private function assertCompanyCall(PhoneCall $call): void
    {
        $companyId = $this->selectedCompanyId();
        abort_unless($companyId && \App\Services\CompanyIdScope::sameCompanyId((string) ((string) $call->company_id), (string) ((string) $companyId)), 404);
    }

    private function selectedCompanyId(): string
    {
        $companyId = app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
        abort_unless($companyId, 409, 'Select an active business to manage phone calls.');
        app(CompanySettingsAccessService::class)->assertCanAccess(request()->user(), (string) $companyId);

        return (string) $companyId;
    }
}
