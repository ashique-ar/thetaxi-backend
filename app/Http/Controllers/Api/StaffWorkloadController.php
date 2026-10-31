<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\User;
use App\Services\BookingResponsibilityService;
use App\Services\InquiryAssignmentService;
use App\Services\Sales\SalesAccessScope;
use App\Services\StaffAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffWorkloadController extends Controller
{
    public function __invoke(Request $request, StaffAccessService $staffAccess, SalesAccessScope $salesScope): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $employeesPage = $staffAccess->scope(Staff::query(), $request->user())
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->whereNull('staff.deleted_at')
            ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
            ->whereNull('users.deleted_at')->where('users.is_active', true)
            ->orderBy('users.first_name')->orderBy('users.last_name')->orderBy('staff.id')
            ->select(['staff.id as staff_id', 'staff.user_id', 'staff.code', 'users.first_name', 'users.last_name'])
            ->paginate($data['per_page'] ?? 25);
        $employees = $employeesPage->getCollection()->map(fn ($employee) => [
            'staff_id' => (string) $employee->staff_id,
            'user_id' => (string) $employee->user_id,
            'staff_code' => $employee->code,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'active_bookings' => $request->user()->can('bookings.view') ? 0 : null,
            'completed_bookings_30d' => $request->user()->can('bookings.view') ? 0 : null,
            'open_inquiries' => $request->user()->can('inquiries.view') ? 0 : null,
            'calls_last_7_days' => $request->user()->can('phone-calls.view') ? 0 : null,
            'open_followups' => null,
            'overdue_followups' => null,
            'sales_profile_id' => null,
            'owned_customers' => $request->user()->can('customers.view') ? 0 : null,
        ])->values();
        $userIds = $employees->pluck('user_id')->all();
        $available = [];
        $unassigned = [
            'active_bookings' => null,
            'open_inquiries' => null,
            'calls_last_7_days' => null,
            'open_followups' => null,
            'overdue_followups' => null,
            'owned_customers' => null,
        ];

        if ($request->user()->can('bookings.view')) {
            $available[] = 'active_bookings';
            $available[] = 'completed_bookings_30d';
            $statuses = BookingLifecycleStatus::activeOperationalValues();
            $eligibleBookingOwners = app(BookingResponsibilityService::class)->eligibleEmployees()->pluck('id')->map(fn ($id) => (string) $id)->all();
            $counts = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->whereIn('booking.status', $statuses)->whereIn('booking.responsible_user_id', $userIds)
                ->whereIn('booking.responsible_user_id', $eligibleBookingOwners);
            foreach ($counts->selectRaw('booking.responsible_user_id, COUNT(DISTINCT booking.id) as total')
                ->groupBy('booking.responsible_user_id')->get() as $count) {
                $this->setCount($employees, (string) $count->responsible_user_id, 'active_bookings', (int) $count->total);
            }
            $completedBookings = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->where('booking.status', BookingLifecycleStatus::COMPLETED->value)
                ->where('booking.completed_at', '>=', now()->subDays(30))
                ->whereIn('booking.responsible_user_id', $userIds)
                ->whereIn('booking.responsible_user_id', $eligibleBookingOwners);
            foreach ($completedBookings->selectRaw('booking.responsible_user_id, COUNT(DISTINCT booking.id) as total')
                ->groupBy('booking.responsible_user_id')->get() as $count) {
                $this->setCount($employees, (string) $count->responsible_user_id, 'completed_bookings_30d', (int) $count->total);
            }
            $unassignedBookings = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->whereIn('booking.status', $statuses)
                ->where(fn ($owner) => $owner->whereNull('booking.responsible_user_id')->orWhereNotIn('booking.responsible_user_id', $eligibleBookingOwners));
            $unassigned['active_bookings'] = (int) $unassignedBookings->distinct('booking.id')->count('booking.id');
        }

        if ($request->user()->can('inquiries.view')) {
            $available[] = 'open_inquiries';
            $eligibleInquiryAssignees = app(InquiryAssignmentService::class)->eligibleAssigneeIds();
            foreach (DB::table('inquiries')->whereNull('deleted_at')
                ->whereIn('status', ['open', 'in_progress'])->whereIn('assigned_to', $eligibleInquiryAssignees)
                ->selectRaw('assigned_to, COUNT(*) as total')->groupBy('assigned_to')->get() as $count) {
                $this->setCount($employees, (string) $count->assigned_to, 'open_inquiries', (int) $count->total);
            }
            $unassigned['open_inquiries'] = (int) DB::table('inquiries')->whereNull('deleted_at')->whereIn('status', ['open', 'in_progress'])
                ->where(fn ($owner) => $owner->whereNull('assigned_to')->orWhereNotIn('assigned_to', $eligibleInquiryAssignees))
                ->count();
        }

        if ($request->user()->can('phone-calls.view')) {
            $available[] = 'calls_last_7_days';
            $since = now()->subDays(7);
            $eligibleCallAssignees = User::permission('phone-calls.view')->whereHas('staff', fn ($staff) => $staff
                ->whereNull('deleted_at')
                ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
                ->where('is_active', true)->whereNull('deleted_at')->select('id');
            foreach (DB::table('phone_calls')->whereNull('deleted_at')
                ->where('created_at', '>=', $since)->whereIn('assigned_user_id', $eligibleCallAssignees)
                ->selectRaw('assigned_user_id, COUNT(*) as total')->groupBy('assigned_user_id')->get() as $count) {
                $this->setCount($employees, (string) $count->assigned_user_id, 'calls_last_7_days', (int) $count->total);
            }
            $unassigned['calls_last_7_days'] = (int) DB::table('phone_calls')
                ->whereNull('deleted_at')->where('created_at', '>=', $since)
                ->where(fn ($owner) => $owner->whereNull('assigned_user_id')->orWhereNotIn('assigned_user_id', $eligibleCallAssignees))
                ->count();
        }

        if ($request->user()->can('customers.view')) {
            $available[] = 'owned_customers';
            $owners = DB::table('customer_relationship_owner_assignments as assignment')
                ->join('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
                ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->join('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
                ->join('customers', 'customers.id', '=', 'assignment.customer_id')
                ->whereNull('assignment.ended_at')->where('assignment.effective_from', '<=', now())
                ->whereNotNull('assignment.owner_sales_profile_id')->whereIn('owner_staff.user_id', $userIds)
                ->where('profile.status', 'active')->whereNull('profile.deleted_at')
                ->where('profile.effective_from', '<=', now())->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                ->whereNull('owner_staff.deleted_at')
                ->where(fn ($query) => $query->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
                ->where('owner_user.is_active', true)->whereNull('owner_user.deleted_at');
            foreach ($owners->selectRaw('owner_staff.user_id, COUNT(DISTINCT customers.id) as total')
                ->groupBy('owner_staff.user_id')->get() as $count) {
                $this->setCount($employees, (string) $count->user_id, 'owned_customers', (int) $count->total);
            }
            $unassignedCustomers = DB::table('customers')->whereNull('customers.deleted_at');
            $activeOwner = DB::table('customer_relationship_owner_assignments as assignment')->selectRaw('1')
                ->join('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
                ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->join('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
                ->whereColumn('assignment.customer_id', 'customers.id')
                ->whereNull('assignment.ended_at')->where('assignment.effective_from', '<=', now())
                ->where('profile.status', 'active')->whereNull('profile.deleted_at')
                ->where('profile.effective_from', '<=', now())->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                ->whereNull('owner_staff.deleted_at')
                ->where(fn ($query) => $query->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
                ->where('owner_user.is_active', true)->whereNull('owner_user.deleted_at');
            $unassigned['owned_customers'] = (int) $unassignedCustomers->whereNotExists($activeOwner)->count();
        }

        if ($request->user()->can('sales.crm.view')) {
            $profileIds = $salesScope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team');
            if ($profileIds === null || $profileIds !== []) {
                $available[] = 'sales_followups';
                $profiles = DB::table('sales_profiles as profile')
                    ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                    ->where('profile.status', 'active')->whereNull('profile.deleted_at')
                    ->where('profile.effective_from', '<=', now())->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                    ->whereNotNull('profile.reporting_currency')->whereNotNull('profile.staff_category_snapshot')
                    ->where(fn ($eligible) => $eligible->where('profile.acquisition_eligible', true)
                        ->orWhere('profile.collection_eligible', true)->orWhere('profile.commission_eligible', true))
                    ->whereNull('owner_staff.deleted_at')
                    ->where(fn ($employment) => $employment->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
                    ->whereIn('owner_staff.user_id', $userIds)
                    ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts')
                        ->whereColumn('user_contexts.user_id', 'owner_staff.user_id')->whereColumn('user_contexts.context_id', 'owner_staff.id')
                        ->where('user_contexts.context_type', 'staff')->where('user_contexts.is_active', true)->whereNull('user_contexts.deleted_at'))
                    ->when($profileIds !== null, fn ($query) => $query->whereIn('profile.id', $profileIds))
                    ->get(['profile.id as profile_id', 'owner_staff.user_id']);
                $profileIdsForCounts = $profiles->pluck('profile_id')->all();
                foreach ($profiles->groupBy('user_id') as $userId => $userProfiles) {
                    $this->setCount($employees, (string) $userId, 'open_followups', 0);
                    $this->setCount($employees, (string) $userId, 'overdue_followups', 0);
                    if ($userProfiles->count() === 1) {
                        $this->setValue($employees, (string) $userId, 'sales_profile_id', (string) $userProfiles->first()->profile_id);
                    }
                }
                if ($profileIdsForCounts !== []) {
                    foreach (DB::table('sales_tasks as task')->join('sales_profiles as profile', 'profile.id', '=', 'task.owner_sales_profile_id')
                        ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                        ->whereIn('task.owner_sales_profile_id', $profileIdsForCounts)
                        ->where('profile.status', 'active')->whereNull('profile.deleted_at')
                        ->whereIn('task.status', ['open', 'in_progress'])
                        ->whereNull('owner_staff.deleted_at')
                        ->selectRaw('owner_staff.user_id, COUNT(*) as open_total, SUM(CASE WHEN task.due_at < ? THEN 1 ELSE 0 END) as overdue_total', [now('UTC')])
                        ->groupBy('owner_staff.user_id')->get() as $count) {
                        $this->setCount($employees, (string) $count->user_id, 'open_followups', (int) $count->open_total);
                        $this->setCount($employees, (string) $count->user_id, 'overdue_followups', (int) $count->overdue_total);
                    }
                }

                if ($profileIds === null || $salesScope->hasPermission($request->user(), 'sales.crm.manage-all')) {
                    $available[] = 'sales_followups_needing_owner';
                    $activeTaskOwner = fn () => DB::table('sales_profiles as task_owner_profile')->selectRaw('1')
                        ->join('staff as task_owner_staff', 'task_owner_staff.id', '=', 'task_owner_profile.staff_id')
                        ->join('users as task_owner_user', 'task_owner_user.id', '=', 'task_owner_staff.user_id')
                        ->whereColumn('task_owner_profile.id', 'task.owner_sales_profile_id')
                        ->where('task_owner_profile.status', 'active')->whereNull('task_owner_profile.deleted_at')
                        ->where('task_owner_profile.effective_from', '<=', now())
                        ->where(fn ($effective) => $effective->whereNull('task_owner_profile.effective_until')->orWhere('task_owner_profile.effective_until', '>', now()))
                        ->whereNotNull('task_owner_profile.reporting_currency')->whereNotNull('task_owner_profile.staff_category_snapshot')
                        ->where(fn ($eligible) => $eligible->where('task_owner_profile.acquisition_eligible', true)
                            ->orWhere('task_owner_profile.collection_eligible', true)->orWhere('task_owner_profile.commission_eligible', true))
                        ->whereNull('task_owner_staff.deleted_at')
                        ->where(fn ($employment) => $employment->whereNull('task_owner_staff.employment_ended_at')->orWhere('task_owner_staff.employment_ended_at', '>', now()))
                        ->where('task_owner_user.is_active', true)->whereNull('task_owner_user.deleted_at')
                        ->whereExists(fn ($context) => $context->selectRaw('1')->from('user_contexts')
                            ->whereColumn('user_contexts.user_id', 'task_owner_staff.user_id')->whereColumn('user_contexts.context_id', 'task_owner_staff.id')
                            ->where('user_contexts.context_type', 'staff')->where('user_contexts.is_active', true)->whereNull('user_contexts.deleted_at'));
                    $unassigned['open_followups'] = (int) DB::table('sales_tasks as task')
                        ->whereIn('task.status', ['open', 'in_progress'])
                        ->whereExists(fn ($owner) => $owner->selectRaw('1')->from('sales_profiles as task_profile')
                            ->whereColumn('task_profile.id', 'task.owner_sales_profile_id')
                            ->whereNull('task_profile.deleted_at')->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff as task_staff')
                                ->whereColumn('task_staff.id', 'task_profile.staff_id')))
                        ->whereNotExists($activeTaskOwner())->count();
                }
            }
        }

        return response()->json(['status' => 'success', 'data' => [
            'as_of' => now()->toIso8601String(),
            'available_metrics' => $available,
            'unassigned' => $unassigned,
            'employees' => $employeesPage->setCollection($employees),
        ]]);
    }

    private function setCount($employees, string $userId, string $metric, int $count): void
    {
        $this->setValue($employees, $userId, $metric, $count);
    }

    private function setValue($employees, string $userId, string $metric, mixed $value): void
    {
        $employees->transform(function (array $employee) use ($userId, $metric, $value): array {
            if ($employee['user_id'] === $userId) $employee[$metric] = $value;
            return $employee;
        });
    }
}
