<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\CompanyBookingScope;
use App\Services\BookingResponsibilityService;
use App\Services\CompanyCustomerScope;
use App\Services\Sales\SalesAccessScope;
use App\Services\CompanySettingsAccessService;
use App\Services\StaffAccessService;
use App\Services\WebsiteSettingsService;
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
        $companyId = app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
        abort_unless($companyId, 409, 'No active business is configured.');
        app(CompanySettingsAccessService::class)->assertCanAccess($request->user(), (string) $companyId);

        $employeesPage = $staffAccess->scope(Staff::query(), $request->user())
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.company_id', $companyId)
            ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')
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
            $eligibleBookingOwners = app(BookingResponsibilityService::class)->eligibleEmployees((string) $companyId)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $counts = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->whereIn('booking.status', $statuses)->whereIn('booking.responsible_user_id', $userIds)
                ->whereIn('booking.responsible_user_id', $eligibleBookingOwners);
            CompanyBookingScope::constrain($counts, 'booking', (string) $companyId);
            foreach ($counts->selectRaw('booking.responsible_user_id, COUNT(DISTINCT booking.id) as total')
                ->groupBy('booking.responsible_user_id')->get() as $count) {
                $this->setCount($employees, (string) $count->responsible_user_id, 'active_bookings', (int) $count->total);
            }
            $completedBookings = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->where('booking.status', BookingLifecycleStatus::COMPLETED->value)
                ->where('booking.completed_at', '>=', now()->subDays(30))
                ->whereIn('booking.responsible_user_id', $userIds)
                ->whereIn('booking.responsible_user_id', $eligibleBookingOwners);
            CompanyBookingScope::constrain($completedBookings, 'booking', (string) $companyId);
            foreach ($completedBookings->selectRaw('booking.responsible_user_id, COUNT(DISTINCT booking.id) as total')
                ->groupBy('booking.responsible_user_id')->get() as $count) {
                $this->setCount($employees, (string) $count->responsible_user_id, 'completed_bookings_30d', (int) $count->total);
            }
            $unassignedBookings = DB::table('bookings as booking')->whereNull('booking.deleted_at')
                ->whereIn('booking.status', $statuses)
                ->where(fn ($owner) => $owner->whereNull('booking.responsible_user_id')->orWhereNotIn('booking.responsible_user_id', $eligibleBookingOwners));
            CompanyBookingScope::constrain($unassignedBookings, 'booking', (string) $companyId);
            $unassigned['active_bookings'] = (int) $unassignedBookings->distinct('booking.id')->count('booking.id');
        }

        if ($request->user()->can('inquiries.view')) {
            $available[] = 'open_inquiries';
            foreach (DB::table('inquiries')->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereIn('status', ['open', 'in_progress'])->whereIn('assigned_to', $userIds)
                ->selectRaw('assigned_to, COUNT(*) as total')->groupBy('assigned_to')->get() as $count) {
                $this->setCount($employees, (string) $count->assigned_to, 'open_inquiries', (int) $count->total);
            }
            $unassigned['open_inquiries'] = (int) DB::table('inquiries')->where('company_id', $companyId)
                ->whereNull('deleted_at')->whereIn('status', ['open', 'in_progress'])
                ->whereNull('assigned_to')->count();
        }

        if ($request->user()->can('phone-calls.view')) {
            $available[] = 'calls_last_7_days';
            $since = now()->subDays(7);
            foreach (DB::table('phone_calls')->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('created_at', '>=', $since)->whereIn('assigned_user_id', $userIds)
                ->selectRaw('assigned_user_id, COUNT(*) as total')->groupBy('assigned_user_id')->get() as $count) {
                $this->setCount($employees, (string) $count->assigned_user_id, 'calls_last_7_days', (int) $count->total);
            }
            $unassigned['calls_last_7_days'] = (int) DB::table('phone_calls')->where('company_id', $companyId)
                ->whereNull('deleted_at')->where('created_at', '>=', $since)
                ->whereNull('assigned_user_id')->count();
        }

        if ($request->user()->can('customers.view')) {
            $available[] = 'owned_customers';
            $owners = DB::table('customer_relationship_owner_assignments as assignment')
                ->join('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
                ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->join('customers', 'customers.id', '=', 'assignment.customer_id')
                ->where('assignment.company_id', $companyId)->whereNull('assignment.ended_at')
                ->whereNotNull('assignment.owner_sales_profile_id')->whereIn('owner_staff.user_id', $userIds)
                ->where('profile.company_id', $companyId)->whereNull('profile.deleted_at')
                ->where('owner_staff.company_id', $companyId)->whereNull('owner_staff.deleted_at')
                ->whereNull('owner_staff.employment_ended_at');
            CompanyCustomerScope::constrain($owners, 'customers', (string) $companyId);
            foreach ($owners->selectRaw('owner_staff.user_id, COUNT(DISTINCT customers.id) as total')
                ->groupBy('owner_staff.user_id')->get() as $count) {
                $this->setCount($employees, (string) $count->user_id, 'owned_customers', (int) $count->total);
            }
            $unassignedCustomers = DB::table('customers')->whereNull('customers.deleted_at');
            CompanyCustomerScope::constrain($unassignedCustomers, 'customers', (string) $companyId);
            $activeOwner = DB::table('customer_relationship_owner_assignments as assignment')->selectRaw('1')
                ->whereColumn('assignment.customer_id', 'customers.id')->where('assignment.company_id', $companyId)
                ->whereNull('assignment.ended_at')->whereNotNull('assignment.owner_sales_profile_id');
            $unassigned['owned_customers'] = (int) $unassignedCustomers->whereNotExists($activeOwner)->count();
        }

        if ($request->user()->can('sales.crm.view')) {
            $profileIds = $salesScope->profileIds($request->user(), 'sales.crm.view-all', 'sales.crm.view-team', (string) $companyId);
            if ($profileIds === null || $profileIds !== []) {
                $available[] = 'sales_followups';
                $profiles = DB::table('sales_profiles as profile')
                    ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                    ->where('profile.company_id', $companyId)->whereNull('profile.deleted_at')
                    ->where('owner_staff.company_id', $companyId)->whereNull('owner_staff.deleted_at')
                    ->whereIn('owner_staff.user_id', $userIds)
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
                        ->where('task.company_id', $companyId)->whereIn('task.owner_sales_profile_id', $profileIdsForCounts)
                        ->where('profile.company_id', $companyId)->whereNull('profile.deleted_at')
                        ->whereIn('task.status', ['open', 'in_progress'])
                        ->where('owner_staff.company_id', $companyId)->whereNull('owner_staff.deleted_at')
                        ->selectRaw('owner_staff.user_id, COUNT(*) as open_total, SUM(CASE WHEN task.due_at < ? THEN 1 ELSE 0 END) as overdue_total', [now('UTC')])
                        ->groupBy('owner_staff.user_id')->get() as $count) {
                        $this->setCount($employees, (string) $count->user_id, 'open_followups', (int) $count->open_total);
                        $this->setCount($employees, (string) $count->user_id, 'overdue_followups', (int) $count->overdue_total);
                    }
                }
            }
        }

        return response()->json(['status' => 'success', 'data' => [
            'company_id' => (string) $companyId,
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
