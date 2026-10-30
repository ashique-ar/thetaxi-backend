<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Services\CompanyBookingScope;
use App\Services\CompanyCustomerScope;
use App\Services\BookingResponsibilityService;
use App\Services\InquiryAssignmentService;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\Sales\InquiryFirstResponseDeadlineService;
use App\Services\CompanySettingsAccessService;
use App\Services\WebsiteSettingsService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MyWorkController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (string) (app(WebsiteSettingsService::class)->resolveCurrentCompanyId() ?? '');
        abort_unless($companyId !== '', 409, 'No active business is configured.');
        app(CompanySettingsAccessService::class)->assertCanAccess($request->user(), $companyId);
        $userId = (string) $request->user()->id;
        $queues = [];
        $attention = [];
        $employeeWorkload = [];
        $activeBookingStatuses = array_merge(['inquiry', 'inquiry_qualified'], BookingLifecycleStatus::activeOperationalValues());

        if ($request->user()->can('bookings.view') && !Schema::hasColumn('bookings', 'responsible_user_id')) {
            return response()->json([
                'status' => 'error',
                'code' => 'WORKSPACE_SETUP_REQUIRED',
                'message' => 'Booking tasks are not available yet. Ask your manager to contact support, then try again.',
            ], 503);
        }

        if ($request->user()->can('bookings.view')) {
            $bookings = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->where('booking.responsible_user_id', $userId)
                ->whereIn('booking.status', array_merge(['draft'], $activeBookingStatuses));
            CompanyBookingScope::constrain($bookings, 'booking', $companyId);
            $queues[] = ['group' => 'mine', 'title' => 'My open bookings', 'help' => 'Bookings assigned to you that are still in progress', 'icon' => 'directions_car',
                'link' => '/bookings/list?mine_only=true&work_only=true', 'count' => (int) $bookings->distinct('booking.id')->count('booking.id')];
            $rows = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->where('booking.responsible_user_id', $userId)
                ->whereIn('booking.status', array_merge(['draft'], $activeBookingStatuses));
            CompanyBookingScope::constrain($rows, 'booking', $companyId);
            foreach ($this->withBookingAttentionContext($rows)->orderBy('booking.created_at')->limit(5)->get() as $row) {
                $attention[] = ['id' => 'booking:'.$row->id, 'group' => 'mine', 'type' => 'Booking', 'title' => $this->bookingAttentionTitle($row),
                    'detail' => $this->bookingAttentionDetail($row, 'Assigned to you'), 'when' => $row->created_at, 'link' => '/bookings/'.$row->id,
                    'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('bookings.view')
            && Schema::hasTable('booking_dispatches')
            && Schema::hasColumn('booking_items', 'workflow_config_snapshot')
            && Schema::hasColumn('booking_dispatches', 'booking_item_id')) {
            $expectedReturn = DB::table('booking_dispatches as due_dispatch')
                ->select('due_dispatch.expected_return_at')
                ->whereColumn('due_dispatch.booking_id', 'booking.id')
                ->whereColumn('due_dispatch.booking_item_id', 'item.id')
                ->whereIn('due_dispatch.dispatch_status', ['dispatched', 'in_progress'])
                ->whereNull('due_dispatch.actual_return_at')
                ->whereNotNull('due_dispatch.expected_return_at')
                ->where('due_dispatch.expected_return_at', '<=', now('UTC'))
                ->orderBy('due_dispatch.expected_return_at')->limit(1);
            $dueReturns = DB::table('booking_items as item')
                ->join('bookings as booking', 'booking.id', '=', 'item.booking_id')
                ->leftJoin('service_types as return_service', 'return_service.id', '=', 'item.service_type_id')
                ->leftJoin('customers as return_customer', function ($join): void {
                    $join->on('return_customer.id', '=', 'booking.customer_id')->whereNull('return_customer.deleted_at');
                })
                ->leftJoin('users as return_customer_user', function ($join): void {
                    $join->on('return_customer_user.id', '=', 'return_customer.user_id')->whereNull('return_customer_user.deleted_at');
                })
                ->whereNull('booking.deleted_at')->whereNull('item.deleted_at')->whereNull('item.completed_at')
                ->whereNull('item.returned_at')->where('booking.responsible_user_id', $userId)
                ->whereNotIn('booking.status', ['completed', 'cancelled', 'canceled', 'booking_cancelled', 'booking_rejected', 'rejected'])
                ->where(fn ($status) => $status->whereNull('item.status')->orWhereNotIn('item.status', ['completed', 'cancelled', 'canceled', 'booking_cancelled', 'booking_rejected', 'rejected']))
                ->whereExists(fn ($dispatch) => $dispatch->selectRaw('1')->from('booking_dispatches as due_dispatch')
                    ->whereColumn('due_dispatch.booking_id', 'booking.id')->whereColumn('due_dispatch.booking_item_id', 'item.id')
                    ->whereIn('due_dispatch.dispatch_status', ['dispatched', 'in_progress'])->whereNull('due_dispatch.actual_return_at')
                    ->whereNotNull('due_dispatch.expected_return_at')->where('due_dispatch.expected_return_at', '<=', now('UTC')))
                ->select(['item.id', 'item.workflow_config_snapshot', 'item.service_type_id', 'booking.booking_number', 'return_service.workflow_config'])
                ->selectSub($expectedReturn, 'due_return_at')
                ->selectRaw("NULLIF(CONCAT_WS(' ', return_customer_user.first_name, return_customer_user.last_name), '') as customer_name");
            CompanyBookingScope::constrain($dueReturns, 'booking', $companyId);

            $returnStageResolver = app(\App\Services\BookingWorkflowSettingsResolver::class);
            $returnCount = 0;
            foreach ($dueReturns->orderBy('due_return_at')->orderBy('item.id')->cursor() as $row) {
                $snapshot = is_array($row->workflow_config_snapshot)
                    ? $row->workflow_config_snapshot
                    : (json_decode((string) $row->workflow_config_snapshot, true) ?: null);
                $serviceConfig = is_array($row->workflow_config)
                    ? $row->workflow_config
                    : (json_decode((string) $row->workflow_config, true) ?: null);
                $workflowConfig = (($snapshot['policy_version'] ?? null) === 1)
                    ? $snapshot
                    : array_merge($serviceConfig ?? [], $snapshot ?? []);
                if (!$returnStageResolver->resolve($workflowConfig, $companyId)['enable_return_stage']) continue;

                $returnCount++;
                if ($returnCount <= 4) {
                    $title = trim((string) $row->customer_name) ?: ($row->booking_number ?: 'Return due');
                    $attention[] = ['id' => 'return-due:'.$row->id, 'group' => 'mine', 'type' => 'Return follow-up', 'title' => $title,
                        'detail' => implode(' · ', array_filter([$row->booking_number ? 'Ref '.$row->booking_number : null, 'Return is overdue'])),
                        'when' => $row->due_return_at, 'link' => '/bookings/operations/return-inspection?queue=return_due',
                        'sort_at' => strtotime((string) $row->due_return_at) ?: PHP_INT_MAX];
                }
            }
            if ($returnCount > 0) {
                $queues[] = ['group' => 'mine', 'title' => 'Returns to follow up',
                    'help' => 'Contact the customer and coordinate overdue returns assigned to you.', 'icon' => 'assignment_return',
                    'link' => '/bookings/operations/return-inspection?queue=return_due', 'count' => $returnCount];
            }
        }

        if ($request->user()->can('bookings.view') && $request->user()->can('bookings.assign')) {
            $employeeWorkload = app(BookingResponsibilityService::class)
                ->eligibleEmployeesWithOpenBookingCounts($companyId);
            $eligibleOwners = collect($employeeWorkload)->pluck('id')->all();
            $unassigned = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->whereIn('booking.status', $activeBookingStatuses)
                ->where(fn ($owner) => $owner->whereNull('booking.responsible_user_id')->orWhereNotIn('booking.responsible_user_id', $eligibleOwners));
            CompanyBookingScope::constrain($unassigned, 'booking', $companyId);
            $queues[] = ['group' => 'assignment', 'title' => 'Bookings to assign', 'help' => 'New or active bookings waiting for an employee', 'icon' => 'person_add',
                'link' => '/bookings/list?queue=needs_responsible_owner', 'count' => (int) $unassigned->distinct('booking.id')->count('booking.id')];

            $paymentsAwaitingConfirmation = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->where('booking.booking_origin', 'website')->where('booking.payment_method', 'online')
                ->whereIn('booking.payment_type', ['full', 'advance'])->where('booking.payment_status', 'pending')
                ->whereIn('booking.status', ['pending', 'payment_processing']);
            CompanyBookingScope::constrain($paymentsAwaitingConfirmation, 'booking', $companyId);
            $paymentCount = (int) $paymentsAwaitingConfirmation->distinct('booking.id')->count('booking.id');
            if ($paymentCount > 0) {
                $queues[] = ['group' => 'assignment', 'title' => 'Website payments to follow up',
                    'help' => 'Online bookings still waiting for payment confirmation. Check the payment and contact the customer before releasing the vehicle.',
                    'icon' => 'payments', 'link' => '/bookings/list?queue=payment_confirmation_pending', 'count' => $paymentCount];
            }

            if (Schema::hasTable('booking_extension_requests')) {
                $extensionRequests = DB::table('booking_extension_requests')->where(fn ($scope) => \App\Services\CompanyIdScope::constrain($scope, 'company_id', $companyId))
                    ->where('status', 'pending')->whereNull('deleted_at');
                $extensionCount = (int) (clone $extensionRequests)->count();
                if ($extensionCount > 0) {
                    $queues[] = ['group' => 'assignment', 'title' => 'Rental extensions to review',
                        'help' => 'Check return dates and availability before approving a rental extension', 'icon' => 'more_time',
                        'link' => '/bookings/operations/ongoing-hires?queue=extensions', 'count' => $extensionCount];
                    foreach ((clone $extensionRequests)->orderBy('created_at')->limit(4)->get(['id', 'booking_id', 'booking_item_id', 'requested_end_at', 'created_at']) as $row) {
                        $attention[] = ['id' => 'booking-extension:'.$row->id, 'group' => 'assignment', 'type' => 'Rental extension',
                            'title' => 'Return date change needs review', 'detail' => 'Check availability before confirming the new date',
                            'when' => $row->created_at, 'link' => '/bookings/operations/ongoing-hires?queue=extensions',
                            'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
                    }
                }
            }

            $assignmentTargetMinutes = app(SalesPolicySettingsService::class)->bookingAssignmentTargetMinutes($companyId);
            if ($assignmentTargetMinutes !== null) {
                $pastTarget = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                    ->whereIn('booking.status', $activeBookingStatuses)
                    ->where(fn ($owner) => $owner->whereNull('booking.responsible_user_id')->orWhereNotIn('booking.responsible_user_id', $eligibleOwners))
                    ->where('booking.created_at', '<=', now()->subMinutes($assignmentTargetMinutes));
                CompanyBookingScope::constrain($pastTarget, 'booking', $companyId);
                $queues[] = ['group' => 'assignment', 'title' => 'Bookings past assignment target', 'help' => 'Unassigned bookings that have waited longer than the approved target', 'icon' => 'schedule',
                    'link' => '/bookings/list?queue=needs_responsible_owner_past_target', 'count' => (int) $pastTarget->distinct('booking.id')->count('booking.id')];
                foreach ($this->withBookingAttentionContext(clone $pastTarget)->orderBy('booking.created_at')->limit(4)->get() as $row) {
                    $attention[] = ['id' => 'booking-assignment-overdue:'.$row->id, 'group' => 'assignment', 'type' => 'Booking past target',
                        'title' => $this->bookingAttentionTitle($row), 'detail' => $this->bookingAttentionDetail($row, 'Assign an employee now'), 'when' => $row->created_at,
                        'link' => '/bookings/list?queue=needs_responsible_owner_past_target', 'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
                }
            }
            $waitingBookings = DB::table('bookings as booking')->whereNull('booking.deleted_at')->whereIn('booking.status', $activeBookingStatuses)
                ->where(fn ($owner) => $owner->whereNull('booking.responsible_user_id')->orWhereNotIn('booking.responsible_user_id', $eligibleOwners));
            CompanyBookingScope::constrain($waitingBookings, 'booking', $companyId);
            foreach ($this->withBookingAttentionContext($waitingBookings)->orderBy('booking.created_at')->limit(4)->get() as $row) {
                $attention[] = ['id' => 'booking-assignment:'.$row->id, 'group' => 'assignment', 'type' => 'Booking to assign',
                    'title' => $this->bookingAttentionTitle($row), 'detail' => $this->bookingAttentionDetail($row, 'Choose an employee'), 'when' => $row->created_at,
                    'link' => '/bookings/list?queue=needs_responsible_owner', 'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('inquiries.view')) {
            $queues[] = ['group' => 'mine', 'title' => 'My open inquiries', 'help' => 'Customer requests assigned to you that need action', 'icon' => 'chat',
                'link' => '/communication/inquiries?mine_only=true&actionable_only=true', 'count' => (int) DB::table('inquiries')
                    ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')->where('assigned_to', $userId)
                    ->whereIn('status', ['open', 'in_progress'])->count()];
            foreach (DB::table('inquiries')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')->where('assigned_to', $userId)
                ->whereIn('status', ['open', 'in_progress'])->orderBy('created_at')->limit(5)
                ->get(['id', 'name', 'subject', 'created_at']) as $row) {
                $attention[] = ['id' => 'inquiry:'.$row->id, 'group' => 'mine', 'type' => 'Request', 'title' => $row->subject ?: 'Customer request',
                    'detail' => $row->name ?: 'Customer name not recorded', 'when' => $row->created_at,
                    'link' => '/communication/inquiries/'.$row->id, 'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
            }

            $responseTargetMinutes = app(SalesPolicySettingsService::class)->inquiryFirstResponseMinutes($companyId);
            if ($responseTargetMinutes !== null) {
                $hasUnconfirmedEmail = static fn ($query) => $query->selectRaw('1')->from('inquiry_outbound_responses as email_attempt')
                    ->where(fn ($sameCompany) => \App\Services\CompanyIdScope::sameCompany($sameCompany, 'email_attempt.company_id', 'inquiries.company_id'))->whereColumn('email_attempt.inquiry_id', 'inquiries.id')
                    ->whereIn('email_attempt.status', ['sending', 'uncertain']);
                $hasUnconfirmedRespondIo = static fn ($query) => $query->selectRaw('1')->from('integration_webhook_events as reply_attempt')
                    ->where(fn ($sameCompany) => \App\Services\CompanyIdScope::sameCompany($sameCompany, 'reply_attempt.company_id', 'inquiries.company_id'))->whereColumn('reply_attempt.linked_inquiry_id', 'inquiries.id')
                    ->whereIn('reply_attempt.outbound_status', ['sending', 'uncertain']);
                $overdueFirstResponses = DB::table('inquiries')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                    ->whereIn('status', ['open', 'in_progress'])->whereNull('first_response_sent_at');
                app(InquiryFirstResponseDeadlineService::class)->constrainOverdue($overdueFirstResponses, $responseTargetMinutes, now());
                $overdueFirstResponses->whereNotExists($hasUnconfirmedEmail)->whereNotExists($hasUnconfirmedRespondIo);
                $myOverdueResponses = (clone $overdueFirstResponses)->where('assigned_to', $userId);
                $queues[] = ['group' => 'mine', 'title' => 'First replies overdue', 'help' => 'Open requests that passed the approved first-response target', 'icon' => 'schedule',
                    'link' => '/communication/inquiries?mine_only=true&actionable_only=true&first_response_state=overdue',
                    'count' => (int) $myOverdueResponses->count()];

                $hasSavedResponseDeadline = Schema::hasColumn('inquiries', 'first_response_due_at');
                $overdueResponseColumns = ['id', 'name', 'subject', 'created_at'];
                if ($hasSavedResponseDeadline) $overdueResponseColumns[] = 'first_response_due_at';
                foreach ((clone $myOverdueResponses)
                    ->orderBy($hasSavedResponseDeadline ? 'first_response_due_at' : 'created_at')
                    ->limit(5)->get($overdueResponseColumns) as $row) {
                    $attention = array_values(array_filter($attention, fn ($item) => $item['id'] !== 'inquiry:'.$row->id));
                    $dueAt = $row->first_response_due_at ?? $row->created_at;
                    $attention[] = ['id' => 'inquiry-overdue:'.$row->id, 'group' => 'mine', 'type' => 'Reply overdue',
                        'title' => $row->subject ?: 'Customer request',
                        'detail' => implode(' · ', array_filter([$row->name ?: 'Customer name not recorded', 'Reply is overdue'])),
                        'when' => $dueAt, 'link' => '/communication/inquiries/'.$row->id,
                        'sort_at' => strtotime((string) $dueAt) ?: PHP_INT_MAX];
                }

                if ($request->user()->can('inquiries.edit')) {
                    $eligibleInquiryAssignees = app(InquiryAssignmentService::class)->eligibleAssigneeIds($companyId);
                    $unassignedOverdueResponses = (clone $overdueFirstResponses)->where(fn ($owner) =>
                        $owner->whereNull('assigned_to')->orWhereNotIn('assigned_to', $eligibleInquiryAssignees));
                    $queues[] = ['group' => 'assignment', 'title' => 'Overdue replies needing an owner', 'help' => 'Assign an employee and have them reply to these waiting requests', 'icon' => 'person_add',
                        'link' => '/communication/inquiries?unassigned_only=true&actionable_only=true&first_response_state=overdue',
                        'count' => (int) $unassignedOverdueResponses->count()];
                }
            }
        }

        if ($request->user()->can('inquiries.view') && $request->user()->can('inquiries.edit')) {
            $eligibleInquiryAssignees = app(InquiryAssignmentService::class)->eligibleAssigneeIds($companyId);
            $queues[] = ['group' => 'assignment', 'title' => 'Inquiries to assign', 'help' => 'New inquiries waiting for an employee', 'icon' => 'person_add',
                'link' => '/communication/inquiries?unassigned_only=true&actionable_only=true', 'count' => (int) DB::table('inquiries')
                    ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                    ->where(fn ($unassigned) => $unassigned->whereNull('assigned_to')->orWhereNotIn('assigned_to', $eligibleInquiryAssignees))
                    ->whereIn('status', ['open', 'in_progress'])->count()];
            foreach (DB::table('inquiries')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                ->where(fn ($unassigned) => $unassigned->whereNull('assigned_to')->orWhereNotIn('assigned_to', $eligibleInquiryAssignees))
                ->whereIn('status', ['open', 'in_progress'])->orderBy('created_at')->limit(4)
                ->get(['id', 'name', 'subject', 'created_at']) as $row) {
                $attention[] = ['id' => 'inquiry-assignment:'.$row->id, 'group' => 'assignment', 'type' => 'Request to assign',
                    'title' => $row->subject ?: 'Customer request', 'detail' => $row->name ?: 'Choose an employee',
                    'when' => $row->created_at, 'link' => '/communication/inquiries?unassigned_only=true&actionable_only=true',
                    'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('customers.view') && $request->user()->can('customers.edit')) {
            $customers = DB::table('customers')->whereNull('customers.deleted_at');
            CompanyCustomerScope::constrain($customers, 'customers', $companyId);
            $unassignedOwner = DB::table('customer_relationship_owner_assignments as assignment')->selectRaw('1')
                ->join('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
                ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->join('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
                ->whereColumn('assignment.customer_id', 'customers.id')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'assignment.company_id', $companyId))
                ->whereNull('assignment.ended_at')->where('assignment.effective_from', '<=', now())
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'profile.company_id', $companyId))->where('profile.status', 'active')->whereNull('profile.deleted_at')
                ->where('profile.effective_from', '<=', now())->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'owner_staff.company_id', $companyId))->whereNull('owner_staff.deleted_at')
                ->where(fn ($query) => $query->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
                ->where('owner_user.is_active', true)->whereNull('owner_user.deleted_at');
            $queues[] = ['group' => 'assignment', 'title' => 'Customers needing an owner', 'help' => 'Existing and new customers without a relationship owner', 'icon' => 'group_add',
                'link' => '/customers?owner_status=unassigned', 'count' => (int) $customers->whereNotExists($unassignedOwner)->count()];
            $waitingCustomers = DB::table('customers')->whereNull('customers.deleted_at');
            CompanyCustomerScope::constrain($waitingCustomers, 'customers', $companyId);
            foreach ($waitingCustomers->leftJoin('users as attention_customer_user', function ($join): void {
                $join->on('attention_customer_user.id', '=', 'customers.user_id')->whereNull('attention_customer_user.deleted_at');
            })->whereNotExists($unassignedOwner)->orderBy('customers.created_at')->limit(4)
                ->get(['customers.id', 'customers.code', 'customers.created_at', 'attention_customer_user.first_name', 'attention_customer_user.last_name', 'attention_customer_user.phone']) as $row) {
                $customerName = trim((string) $row->first_name.' '.(string) $row->last_name);
                $customerTitle = $customerName ?: ($row->code ?: 'Customer');
                $attention[] = ['id' => 'customer-owner:'.$row->id, 'group' => 'assignment', 'type' => 'Customer owner',
                    'title' => $customerTitle, 'detail' => implode(' · ', array_filter([$row->code, $row->phone, 'Choose a relationship owner'])), 'when' => $row->created_at,
                    'link' => '/customers?owner_status=unassigned', 'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('phone-calls.view')) {
            $queues[] = ['group' => 'mine', 'title' => 'Callbacks to make', 'help' => 'Missed calls waiting for a callback', 'icon' => 'call',
                'link' => '/communication/calls?mine_only=true&needs_follow_up=true', 'count' => (int) DB::table('phone_calls')
                    ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')->where('assigned_user_id', $userId)
                    ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->whereNull('follow_up_completed_at')->count()];
            foreach (DB::table('phone_calls')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')->where('assigned_user_id', $userId)
                ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->whereNull('follow_up_completed_at')
                ->orderByRaw('CASE WHEN follow_up_due_at IS NULL THEN 0 ELSE 1 END')->orderBy('follow_up_due_at')->orderBy('call_time')->limit(5)
                ->get(['id', 'client_name', 'phone', 'call_time', 'follow_up_due_at', 'follow_up_notes', 'created_at']) as $row) {
                $attention[] = ['id' => 'call:'.$row->id, 'group' => 'mine', 'type' => 'Callback', 'title' => $row->client_name ?: ($row->phone ?: 'Missed call'),
                    'detail' => implode(' · ', array_filter([$row->phone, $row->follow_up_notes ?: 'Call back and record the result'])), 'when' => $row->follow_up_due_at ?: ($row->call_time ?: $row->created_at),
                    'link' => '/communication/calls/'.$row->id,
                    'sort_at' => strtotime((string) ($row->follow_up_due_at ?: ($row->call_time ?: $row->created_at))) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('phone-calls.view') && $request->user()->can('phone-calls.edit')) {
            $eligibleCallAssignees = User::permission('phone-calls.view')->whereHas('staff', fn ($staff) => $staff
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
                ->where('is_active', true)->whereNull('deleted_at')->select('id');
            $queues[] = ['group' => 'assignment', 'title' => 'Missed calls to assign', 'help' => 'Unassigned missed calls with no follow-up scheduled', 'icon' => 'person_add',
                'link' => '/communication/calls?unassigned_follow_up=true', 'count' => (int) DB::table('phone_calls')
                    ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                    ->where(fn ($owner) => $owner->whereNull('assigned_user_id')->orWhereNotIn('assigned_user_id', $eligibleCallAssignees))
                    ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->whereNull('follow_up_completed_at')->count()];
            foreach (DB::table('phone_calls')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
                ->where(fn ($owner) => $owner->whereNull('assigned_user_id')->orWhereNotIn('assigned_user_id', $eligibleCallAssignees))
                ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->whereNull('follow_up_completed_at')->orderBy('call_time')->limit(4)
                ->get(['id', 'client_name', 'phone', 'call_time', 'created_at']) as $row) {
                $attention[] = ['id' => 'call-assignment:'.$row->id, 'group' => 'assignment', 'type' => 'Missed call to assign',
                    'title' => $row->client_name ?: ($row->phone ?: 'Missed call'), 'detail' => 'Choose an employee',
                    'when' => $row->call_time ?: $row->created_at, 'link' => '/communication/calls/'.$row->id,
                    'sort_at' => strtotime((string) ($row->call_time ?: $row->created_at)) ?: PHP_INT_MAX];
            }
        }

        $respondIoEnabled = filter_var(app(WebsiteSettingsService::class)->get('feature_respond_io_enabled', false, $companyId), FILTER_VALIDATE_BOOL);
        if ($respondIoEnabled && $request->user()->can('communication.manage')) {
            foreach ([['received', 'New messages or updates', 'Review new information from Respond.io before linking it to customer work'],
                ['reply_failed', 'Replies to check', 'Check whether the reply was accepted before trying again']] as [$status, $title, $help]) {
                $events = DB::table('integration_webhook_events')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))
                    ->where('provider', 'respond_io')
                    ->when($status === 'reply_failed', fn ($query) => $query->whereIn('outbound_status', ['failed', 'uncertain']))
                    ->when($status !== 'reply_failed', fn ($query) => $query->where('status', $status));
                $queues[] = ['group' => 'integration', 'title' => $title, 'help' => $help, 'icon' => 'sync_problem',
                    'link' => '/admin/system/integrations/respond-io?status='.$status, 'count' => (int) (clone $events)->count()];
                foreach ($events->orderBy('received_at')->limit(4)->get(['id', 'received_at']) as $row) {
                    $attention[] = ['id' => 'respond-io:'.$status.':'.$row->id, 'group' => 'integration',
                        'type' => $status === 'received' ? 'New message or update' : 'Reply needs checking',
                        'title' => $status === 'received' ? 'Check this new information' : 'Check whether the reply went through',
                        'detail' => $status === 'received' ? 'Open to review and link it to the right customer work' : 'Review the event before sending another reply',
                        'when' => $row->received_at, 'link' => '/admin/system/integrations/respond-io?status='.$status,
                        'sort_at' => strtotime((string) $row->received_at) ?: PHP_INT_MAX];
                }
            }
        }

        if ($request->user()->can('bookings.view') && Schema::hasTable('booking_activities')) {
            $quotes = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->where('booking.status', 'quotation_requested')
                ->where(fn ($owner) => $owner->where('booking.responsible_user_id', $userId)
                    ->orWhere(fn ($sender) => $sender->whereNull('booking.responsible_user_id')
                        ->whereRaw("booking.workflow_data->>'quotation_requested_by' = ?", [$userId])))
                ->whereExists(fn ($sent) => $sent->selectRaw('1')->from('booking_activities as sent_quote')
                    ->whereColumn('sent_quote.booking_id', 'booking.id')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'sent_quote.company_id', $companyId))->whereNull('sent_quote.deleted_at')
                    ->where('sent_quote.event_key', 'quotation_email_sent')->where('sent_quote.result_status', 'sent'));
            CompanyBookingScope::constrain($quotes, 'booking', $companyId);
            $queues[] = ['group' => 'mine', 'title' => 'Quotes awaiting a reply',
                'help' => 'Quotations you sent that are still waiting for the customer', 'icon' => 'request_quote',
                'link' => '/bookings/list?status=quotation_requested', 'count' => (int) (clone $quotes)->distinct('booking.id')->count('booking.id')];

            $sentAt = DB::table('booking_activities as sent_quote')->select('sent_quote.event_at')
                ->whereColumn('sent_quote.booking_id', 'booking.id')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'sent_quote.company_id', $companyId))->whereNull('sent_quote.deleted_at')
                ->where('sent_quote.event_key', 'quotation_email_sent')->where('sent_quote.result_status', 'sent')
                ->orderByDesc('sent_quote.event_at')->limit(1);
            foreach ($this->withBookingAttentionContext(clone $quotes)->selectSub($sentAt, 'quotation_sent_at')
                ->orderBy('quotation_sent_at')->limit(5)->get() as $row) {
                $attention[] = ['id' => 'quote:'.$row->id, 'group' => 'mine', 'type' => 'Quote to follow up',
                    'title' => $row->booking_number ?: 'Quotation',
                    'detail' => $this->bookingAttentionDetail($row, 'Check whether the customer has decided'),
                    'when' => $row->quotation_sent_at, 'link' => '/bookings/'.$row->id,
                    'sort_at' => strtotime((string) $row->quotation_sent_at) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('sales.crm.view')) {
            $businessTimezone = app(SalesPolicySettingsService::class)->businessTimezone($companyId) ?: config('app.timezone');
            $workdayEndUtc = now($businessTimezone)->endOfDay()->utc();
            $profile = DB::table('sales_profiles as profile')->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->join('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'profile.company_id', $companyId))->where('profile.status', 'active')->whereNull('profile.deleted_at')
                ->where('profile.effective_from', '<=', now())
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'owner_staff.company_id', $companyId))->whereNull('owner_staff.deleted_at')
                ->where(fn ($employment) => $employment->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
                ->whereNull('owner_user.deleted_at')->where('owner_user.is_active', true)->where('owner_staff.user_id', $userId)
                ->value('profile.id');
            if ($profile) {
                $count = DB::table('sales_tasks as task')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'task.company_id', $companyId))
                    ->where('task.owner_sales_profile_id', $profile)->whereIn('task.status', ['open', 'in_progress'])
                    ->where('task.due_at', '<=', $workdayEndUtc)->count();
                $queues[] = ['group' => 'mine', 'title' => 'My follow-ups', 'help' => 'Sales tasks due today or overdue', 'icon' => 'task_alt',
                    'link' => '/sales/work', 'count' => (int) $count];
                foreach (DB::table('sales_tasks')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->where('owner_sales_profile_id', $profile)
                    ->whereIn('status', ['open', 'in_progress'])->where('due_at', '<=', $workdayEndUtc)->orderBy('due_at')->limit(5)
                    ->get(['id', 'title', 'due_at']) as $row) {
                    $attention[] = ['id' => 'task:'.$row->id, 'group' => 'mine', 'type' => 'Follow-up', 'title' => $row->title,
                        'detail' => 'Due', 'when' => $row->due_at, 'link' => '/sales/work?task_id='.$row->id,
                        'sort_at' => strtotime((string) $row->due_at) ?: PHP_INT_MAX];
                }
            }
        }

        $attention = collect($attention)->groupBy('group')->flatMap(function ($items): array {
            return $items->sortBy('sort_at')->take(8)->map(function (array $item): array {
                unset($item['sort_at']);
                return $item;
            })->values()->all();
        })->values()->all();

        return response()->json(['status' => 'success', 'data' => [
            'as_of' => now()->toIso8601String(), 'queues' => $queues, 'attention' => $attention,
            'employee_workload' => $employeeWorkload,
        ]]);
    }

    private function withBookingAttentionContext($query)
    {
        return $query
            ->leftJoin('customers as attention_customer', function ($join): void {
                $join->on('attention_customer.id', '=', 'booking.customer_id')->whereNull('attention_customer.deleted_at');
            })
            ->leftJoin('users as attention_customer_user', function ($join): void {
                $join->on('attention_customer_user.id', '=', 'attention_customer.user_id')->whereNull('attention_customer_user.deleted_at');
            })
            ->select([
                'booking.id', 'booking.booking_number', 'booking.created_at',
                'attention_customer_user.first_name', 'attention_customer_user.last_name', 'attention_customer_user.phone',
            ])
            ->selectSub(DB::table('bookings as attention_contact_booking')
                ->selectRaw("attention_contact_booking.workflow_data->'corporate_contact'->>'name'")
                ->whereColumn('attention_contact_booking.id', 'booking.id'), 'corporate_contact_name')
            ->selectSub(DB::table('booking_items as attention_item')
                ->join('service_types as attention_service', 'attention_service.id', '=', 'attention_item.service_type_id')
                ->whereColumn('attention_item.booking_id', 'booking.id')->whereNull('attention_item.deleted_at')
                ->where('attention_item.status', '!=', 'cancelled')
                ->orderByRaw('attention_item.from_date NULLS LAST')->limit(1)->select('attention_service.name'), 'service_name')
            ->selectSub(DB::table('booking_items as attention_item')
                ->whereColumn('attention_item.booking_id', 'booking.id')->whereNull('attention_item.deleted_at')
                ->where('attention_item.status', '!=', 'cancelled')->whereNotNull('attention_item.from_date')
                ->orderBy('attention_item.from_date')->limit(1)->select('attention_item.from_date'), 'service_from_date');
    }

    private function bookingAttentionTitle(object $booking): string
    {
        $customerName = trim((string) $booking->first_name.' '.(string) $booking->last_name);

        return $customerName ?: (trim((string) $booking->corporate_contact_name) ?: ($booking->booking_number ?: 'Open booking'));
    }

    private function bookingAttentionDetail(object $booking, string $action): string
    {
        $parts = array_filter([
            $booking->booking_number ? 'Ref '.$booking->booking_number : null,
            $booking->service_name,
            $booking->service_from_date ? \Illuminate\Support\Carbon::parse($booking->service_from_date)->format('j M Y') : null,
            $booking->phone,
            $action,
        ], fn ($part) => is_string($part) && trim($part) !== '');

        return implode(' · ', $parts);
    }
}
