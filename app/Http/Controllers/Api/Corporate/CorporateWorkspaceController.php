<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Services\CorporatePortalPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateWorkspaceController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $request->attributes->get('corporate_employee');
        $can = fn (string $permission) => CorporatePortalPermission::allows($request, $permission);
        $all = $can('view_all_bookings');
        $base = Booking::query()->where('corporate_account_id', $request->corporate_id)
            ->when(! $all, fn ($query) => $query->where('employee_id', $employee->user_id));

        $tasks = [];
        if ($can('approve_bookings')) {
            $tasks[] = ['key' => 'approvals', 'label' => 'Bookings awaiting approval', 'count' => (clone $base)->where('status', 'pending_approval')->count(), 'route' => '/corporate/approvals'];
        }
        $tasks[] = ['key' => 'upcoming', 'label' => 'Upcoming trips', 'count' => (clone $base)->whereHas('bookingItems', fn ($q) => $q->whereDate('from_date', '>=', today()))->count(), 'route' => $all ? '/corporate/bookings' : '/employee/bookings'];
        if ($can('view_payments')) {
            $tasks[] = ['key' => 'finance', 'label' => 'Account and overdue balances', 'count' => null, 'route' => '/corporate/finance'];
        }
        if ($can('manage_employees')) {
            $tasks[] = ['key' => 'employees', 'label' => 'Manage employees and access', 'count' => null, 'route' => '/corporate/employees'];
        }

        return response()->json(['status' => 'success', 'data' => [
            'role_mode' => $can('manage_employees') ? 'administrator' : ($can('view_payments') ? 'finance' : ($can('approve_bookings') ? 'approver' : ($all ? 'coordinator' : 'employee'))),
            'scope' => $all ? 'company' : 'employee',
            'tasks' => $tasks,
            'capabilities' => collect(['create_bookings', 'create_bookings_for_others', 'view_all_bookings', 'approve_bookings', 'view_payments', 'view_reports', 'manage_employees', 'view_audit_log'])->mapWithKeys(fn ($permission) => [$permission => $can($permission)]),
        ]]);
    }
}
