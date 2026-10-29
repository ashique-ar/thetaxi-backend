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
use App\Services\SingleCompanyScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MyWorkController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (string) (app(SingleCompanyScope::class)->activeDefaultCompany()?->id ?? '');
        abort_unless($companyId !== '', 409, 'No active business is configured.');
        $userId = (string) $request->user()->id;
        $queues = [];
        $attention = [];
        $employeeWorkload = [];
        $activeBookingStatuses = array_merge(['inquiry', 'inquiry_qualified'], BookingLifecycleStatus::activeOperationalValues());

        if ($request->user()->can('inquiries.view') && $request->user()->can('inquiries.edit')
            && $request->user()->can('companies.view')) {
            $unscopedInquiries = (int) DB::table('inquiries')->whereNull('company_id')->whereNull('deleted_at')->count();
            if ($unscopedInquiries > 0) {
                $queues[] = ['group' => 'assignment', 'title' => 'Older inquiries need a business',
                    'help' => 'Review the request and choose which business should manage it.', 'icon' => 'rule_folder',
                    'link' => '/communication/inquiries/review-unscoped', 'count' => $unscopedInquiries];
            }
        }

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
            foreach ($rows->orderBy('booking.created_at')->limit(5)->get(['booking.id', 'booking.booking_number', 'booking.created_at']) as $row) {
                $attention[] = ['id' => 'booking:'.$row->id, 'group' => 'mine', 'type' => 'Booking', 'title' => $row->booking_number ?: 'Open booking',
                    'detail' => 'Assigned to you', 'when' => $row->created_at, 'link' => '/bookings/'.$row->id,
                    'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
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
            $assignmentTargetMinutes = app(SalesPolicySettingsService::class)->bookingAssignmentTargetMinutes($companyId);
            if ($assignmentTargetMinutes !== null) {
                $pastTarget = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                    ->whereIn('booking.status', $activeBookingStatuses)
                    ->where(fn ($owner) => $owner->whereNull('booking.responsible_user_id')->orWhereNotIn('booking.responsible_user_id', $eligibleOwners))
                    ->where('booking.created_at', '<=', now()->subMinutes($assignmentTargetMinutes));
                CompanyBookingScope::constrain($pastTarget, 'booking', $companyId);
                $queues[] = ['group' => 'assignment', 'title' => 'Bookings past assignment target', 'help' => 'Unassigned bookings that have waited longer than the approved target', 'icon' => 'schedule',
                    'link' => '/bookings/list?queue=needs_responsible_owner_past_target', 'count' => (int) $pastTarget->distinct('booking.id')->count('booking.id')];
                foreach ((clone $pastTarget)->orderBy('booking.created_at')->limit(4)
                    ->get(['booking.id', 'booking.booking_number', 'booking.created_at']) as $row) {
                    $attention[] = ['id' => 'booking-assignment-overdue:'.$row->id, 'group' => 'assignment', 'type' => 'Booking past target',
                        'title' => $row->booking_number ?: 'Booking waiting for an owner', 'detail' => 'Assign an employee now', 'when' => $row->created_at,
                        'link' => '/bookings/list?queue=needs_responsible_owner_past_target', 'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
                }
            }
            $waitingBookings = DB::table('bookings as booking')->whereNull('booking.deleted_at')->whereIn('booking.status', $activeBookingStatuses)
                ->where(fn ($owner) => $owner->whereNull('booking.responsible_user_id')->orWhereNotIn('booking.responsible_user_id', $eligibleOwners));
            CompanyBookingScope::constrain($waitingBookings, 'booking', $companyId);
            foreach ($waitingBookings->orderBy('booking.created_at')->limit(4)->get(['booking.id', 'booking.booking_number', 'booking.created_at']) as $row) {
                $attention[] = ['id' => 'booking-assignment:'.$row->id, 'group' => 'assignment', 'type' => 'Booking to assign',
                    'title' => $row->booking_number ?: 'New booking', 'detail' => 'Choose an employee', 'when' => $row->created_at,
                    'link' => '/bookings/list?queue=needs_responsible_owner', 'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('inquiries.view')) {
            $queues[] = ['group' => 'mine', 'title' => 'My open inquiries', 'help' => 'Website and Respond.io requests waiting for action', 'icon' => 'chat',
                'link' => '/communication/inquiries?mine_only=true&actionable_only=true', 'count' => (int) DB::table('inquiries')
                    ->where('company_id', $companyId)->whereNull('deleted_at')->where('assigned_to', $userId)
                    ->whereIn('status', ['open', 'in_progress'])->count()];
            foreach (DB::table('inquiries')->where('company_id', $companyId)->whereNull('deleted_at')->where('assigned_to', $userId)
                ->whereIn('status', ['open', 'in_progress'])->orderBy('created_at')->limit(5)
                ->get(['id', 'name', 'subject', 'created_at']) as $row) {
                $attention[] = ['id' => 'inquiry:'.$row->id, 'group' => 'mine', 'type' => 'Request', 'title' => $row->subject ?: 'Customer request',
                    'detail' => $row->name ?: 'Customer name not recorded', 'when' => $row->created_at,
                    'link' => '/communication/inquiries/'.$row->id, 'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
            }

            $responseTargetMinutes = app(SalesPolicySettingsService::class)->inquiryFirstResponseMinutes($companyId);
            if ($responseTargetMinutes !== null) {
                $hasUnconfirmedEmail = static fn ($query) => $query->selectRaw('1')->from('inquiry_outbound_responses as email_attempt')
                    ->whereColumn('email_attempt.company_id', 'inquiries.company_id')->whereColumn('email_attempt.inquiry_id', 'inquiries.id')
                    ->whereIn('email_attempt.status', ['sending', 'uncertain']);
                $hasUnconfirmedRespondIo = static fn ($query) => $query->selectRaw('1')->from('integration_webhook_events as reply_attempt')
                    ->whereColumn('reply_attempt.company_id', 'inquiries.company_id')->whereColumn('reply_attempt.linked_inquiry_id', 'inquiries.id')
                    ->whereIn('reply_attempt.outbound_status', ['sending', 'uncertain']);
                $overdueFirstResponses = DB::table('inquiries')->where('company_id', $companyId)->whereNull('deleted_at')
                    ->whereIn('status', ['open', 'in_progress'])->whereNull('first_response_sent_at');
                app(InquiryFirstResponseDeadlineService::class)->constrainOverdue($overdueFirstResponses, $responseTargetMinutes, now());
                $overdueFirstResponses->whereNotExists($hasUnconfirmedEmail)->whereNotExists($hasUnconfirmedRespondIo);
                $myOverdueResponses = (clone $overdueFirstResponses)->where('assigned_to', $userId);
                $queues[] = ['group' => 'mine', 'title' => 'First replies overdue', 'help' => 'Open requests that passed the approved first-response target', 'icon' => 'schedule',
                    'link' => '/communication/inquiries?mine_only=true&actionable_only=true&first_response_state=overdue',
                    'count' => (int) $myOverdueResponses->count()];

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
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where(fn ($unassigned) => $unassigned->whereNull('assigned_to')->orWhereNotIn('assigned_to', $eligibleInquiryAssignees))
                    ->whereIn('status', ['open', 'in_progress'])->count()];
            foreach (DB::table('inquiries')->where('company_id', $companyId)->whereNull('deleted_at')
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
                ->whereColumn('assignment.customer_id', 'customers.id')->where('assignment.company_id', $companyId)
                ->whereNull('assignment.ended_at')->where('assignment.effective_from', '<=', now())
                ->where('profile.company_id', $companyId)->where('profile.status', 'active')->whereNull('profile.deleted_at')
                ->where('profile.effective_from', '<=', now())->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                ->where('owner_staff.company_id', $companyId)->whereNull('owner_staff.deleted_at')
                ->where(fn ($query) => $query->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
                ->where('owner_user.is_active', true)->whereNull('owner_user.deleted_at');
            $queues[] = ['group' => 'assignment', 'title' => 'Customers needing an owner', 'help' => 'Existing and new customers without a relationship owner', 'icon' => 'group_add',
                'link' => '/customers?owner_status=unassigned', 'count' => (int) $customers->whereNotExists($unassignedOwner)->count()];
            $waitingCustomers = DB::table('customers')->whereNull('customers.deleted_at');
            CompanyCustomerScope::constrain($waitingCustomers, 'customers', $companyId);
            foreach ($waitingCustomers->whereNotExists($unassignedOwner)->orderBy('customers.created_at')->limit(4)
                ->get(['customers.id', 'customers.code', 'customers.created_at']) as $row) {
                $attention[] = ['id' => 'customer-owner:'.$row->id, 'group' => 'assignment', 'type' => 'Customer owner',
                    'title' => $row->code ?: 'Customer', 'detail' => 'Choose a relationship owner', 'when' => $row->created_at,
                    'link' => '/customers?owner_status=unassigned', 'sort_at' => strtotime((string) $row->created_at) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('phone-calls.view')) {
            $queues[] = ['group' => 'mine', 'title' => 'Callbacks to make', 'help' => 'Missed calls without a scheduled follow-up', 'icon' => 'call',
                'link' => '/communication/calls?mine_only=true&needs_follow_up=true', 'count' => (int) DB::table('phone_calls')
                    ->where('company_id', $companyId)->whereNull('deleted_at')->where('assigned_user_id', $userId)
                    ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->count()];
            foreach (DB::table('phone_calls')->where('company_id', $companyId)->whereNull('deleted_at')->where('assigned_user_id', $userId)
                ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->orderBy('call_time')->limit(5)
                ->get(['id', 'client_name', 'phone', 'call_time', 'created_at']) as $row) {
                $attention[] = ['id' => 'call:'.$row->id, 'group' => 'mine', 'type' => 'Callback', 'title' => $row->client_name ?: ($row->phone ?: 'Missed call'),
                    'detail' => $row->phone ?: 'Call back and record the result', 'when' => $row->call_time ?: $row->created_at,
                    'link' => '/communication/calls/'.$row->id,
                    'sort_at' => strtotime((string) ($row->call_time ?: $row->created_at)) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('phone-calls.view') && $request->user()->can('phone-calls.edit')) {
            $eligibleCallAssignees = User::permission('phone-calls.view')->whereHas('staff', fn ($staff) => $staff
                ->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('employment_ended_at'))
                ->where('is_active', true)->whereNull('deleted_at')->select('id');
            $queues[] = ['group' => 'assignment', 'title' => 'Missed calls to assign', 'help' => 'Unassigned missed calls with no follow-up scheduled', 'icon' => 'person_add',
                'link' => '/communication/calls?unassigned_follow_up=true', 'count' => (int) DB::table('phone_calls')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where(fn ($owner) => $owner->whereNull('assigned_user_id')->orWhereNotIn('assigned_user_id', $eligibleCallAssignees))
                    ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->count()];
            foreach (DB::table('phone_calls')->where('company_id', $companyId)->whereNull('deleted_at')
                ->where(fn ($owner) => $owner->whereNull('assigned_user_id')->orWhereNotIn('assigned_user_id', $eligibleCallAssignees))
                ->whereIn('call_status', ['Missed', 'Notanswered'])->whereNull('follow_up_task_id')->orderBy('call_time')->limit(4)
                ->get(['id', 'client_name', 'phone', 'call_time', 'created_at']) as $row) {
                $attention[] = ['id' => 'call-assignment:'.$row->id, 'group' => 'assignment', 'type' => 'Missed call to assign',
                    'title' => $row->client_name ?: ($row->phone ?: 'Missed call'), 'detail' => 'Choose an employee',
                    'when' => $row->call_time ?: $row->created_at, 'link' => '/communication/calls/'.$row->id,
                    'sort_at' => strtotime((string) ($row->call_time ?: $row->created_at)) ?: PHP_INT_MAX];
            }
        }

        if ($request->user()->can('communication.manage')) {
            foreach ([['received', 'New messages or updates', 'Review new information from Respond.io before linking it to customer work'],
                ['reply_failed', 'Replies that need checking', 'Respond.io reported a sending problem; check the conversation before trying again']] as [$status, $title, $help]) {
                $events = DB::table('integration_webhook_events')->where('company_id', $companyId)
                    ->where('provider', 'respond_io')
                    ->when($status === 'reply_failed', fn ($query) => $query->where('outbound_status', 'failed'))
                    ->when($status !== 'reply_failed', fn ($query) => $query->where('status', $status));
                $queues[] = ['group' => 'integration', 'title' => $title, 'help' => $help, 'icon' => 'sync_problem',
                    'link' => '/admin/system/integrations/respond-io?status='.$status, 'count' => (int) (clone $events)->count()];
                foreach ($events->orderBy('received_at')->limit(4)->get(['id', 'received_at']) as $row) {
                    $attention[] = ['id' => 'respond-io:'.$status.':'.$row->id, 'group' => 'integration',
                        'type' => $status === 'received' ? 'New message or update' : 'Reply did not send',
                        'title' => $status === 'received' ? 'Check this new information' : 'Check the conversation before retrying',
                        'detail' => $status === 'received' ? 'Open to review and link it to the right customer work' : 'Check whether the customer received it first',
                        'when' => $row->received_at, 'link' => '/admin/system/integrations/respond-io?status='.$status,
                        'sort_at' => strtotime((string) $row->received_at) ?: PHP_INT_MAX];
                }
            }
        }

        if ($request->user()->can('sales.crm.view')) {
            $workdayEndUtc = now(config('app.timezone'))->endOfDay()->utc();
            $profile = DB::table('sales_profiles as profile')->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->join('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
                ->where('profile.company_id', $companyId)->where('profile.status', 'active')->whereNull('profile.deleted_at')
                ->where('profile.effective_from', '<=', now())
                ->where('owner_staff.company_id', $companyId)->whereNull('owner_staff.deleted_at')->whereNull('owner_staff.employment_ended_at')
                ->whereNull('owner_user.deleted_at')->where('owner_user.is_active', true)->where('owner_staff.user_id', $userId)
                ->value('profile.id');
            if ($profile) {
                $count = DB::table('sales_tasks as task')->where('task.company_id', $companyId)
                    ->where('task.owner_sales_profile_id', $profile)->whereIn('task.status', ['open', 'in_progress'])
                    ->where('task.due_at', '<=', $workdayEndUtc)->count();
                $queues[] = ['group' => 'mine', 'title' => 'My follow-ups', 'help' => 'Sales tasks due today or overdue', 'icon' => 'task_alt',
                    'link' => '/sales/work', 'count' => (int) $count];
                foreach (DB::table('sales_tasks')->where('company_id', $companyId)->where('owner_sales_profile_id', $profile)
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
}
