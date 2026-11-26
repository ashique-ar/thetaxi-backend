<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Services\CorporateService;
use App\Services\CorporatePortalPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;

class CorporateWorkspaceController extends Controller
{
    public function bookingConfiguration(Request $request): JsonResponse
    {
        abort_unless(CorporatePortalPermission::allowsAny($request, ['create_bookings', 'bookings.create']), 403);
        $corporate = Corporate::findOrFail($request->corporate_id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $corporate->id,
                'booking_vehicle_display' => $corporate->booking_vehicle_display ?? 'vehicle_class',
            ],
        ]);
    }

    public function approvalOptions(Request $request): JsonResponse
    {
        abort_unless(CorporatePortalPermission::allows($request, 'manage_employees'), 403);
        $corporateId = $request->corporate_id;
        $corporate = Corporate::findOrFail($corporateId);
        app(\App\Services\CorporateRoleStarterService::class)->provision($corporate);
        $roles = Role::query()->where('guard_name', 'api')->where('name', 'like', 'Corporate_'.$corporateId.'_%')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'approve_bookings'))
            ->get(['id', 'name'])->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => Str::after($role->name, 'Corporate_'.$corporateId.'_'),
            ]);
        $employees = CorporateEmployee::query()->where('corporate_id', $corporateId)->where('is_active', true)
            ->whereHas('userContext', fn ($q) => $q->where('is_active', true)->whereHas('roles.permissions', fn ($permission) => $permission->where('name', 'approve_bookings')))
            ->with('user:id,first_name,last_name,email')->get()
            ->map(fn (CorporateEmployee $employee) => [
                'id' => $employee->id,
                'name' => trim(($employee->user?->first_name ?? '').' '.($employee->user?->last_name ?? '')),
                'email' => $employee->user?->email,
            ]);
        return response()->json(['status' => 'success', 'data' => ['roles' => $roles, 'employees' => $employees]]);
    }

    public function updateProfile(Request $request, CorporateService $corporateService): JsonResponse
    {
        abort_unless(CorporatePortalPermission::allows($request, 'manage_employees'), 403);
        $data = $request->validate([
            'approval_required' => ['required', 'boolean'],
            'approval_mode' => ['required', 'in:role,employee'],
            'approval_role_ids' => ['present', 'array'],
            'approval_role_ids.*' => ['integer', 'distinct'],
            'approval_employee_ids' => ['present', 'array'],
            'approval_employee_ids.*' => ['uuid', 'distinct'],
        ]);
        $corporate = Corporate::findOrFail($request->corporate_id);
        $roleIds = $data['approval_mode'] === 'role' ? $data['approval_role_ids'] : [];
        $employeeIds = $data['approval_mode'] === 'employee' ? $data['approval_employee_ids'] : [];
        abort_if($data['approval_required'] && !$roleIds && !$employeeIds, 422, 'Select at least one company approver before enabling company approval.');
        $prefix = 'Corporate_'.$corporate->id.'_';
        abort_if($roleIds && Role::query()->whereIn('id', $roleIds)->where('guard_name', 'api')
            ->where('name', 'like', $prefix.'%')->whereHas('permissions', fn ($q) => $q->where('name', 'approve_bookings'))->count() !== count($roleIds), 422, 'Choose company roles with booking approval permission.');
        abort_if($employeeIds && CorporateEmployee::query()->whereIn('id', $employeeIds)->where('corporate_id', $corporate->id)->where('is_active', true)
            ->whereHas('userContext', fn ($q) => $q->where('is_active', true)->whereHas('roles.permissions', fn ($permission) => $permission->where('name', 'approve_bookings')))
            ->count() !== count($employeeIds), 422, 'Choose active employees with booking approval permission.');
        $corporate = $corporateService->updateCorporate($corporate, [
            'approval_required' => $data['approval_required'],
            'approval_mode' => $data['approval_mode'],
            'approval_role_ids' => $roleIds,
            'approval_employee_ids' => $employeeIds,
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Company booking settings updated.',
            'data' => ['corporate' => $corporate],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $request->attributes->get('corporate_employee');
        $corporate = $employee->corporate;
        $can = fn (string $permission) => CorporatePortalPermission::allows($request, $permission);
        $all = $can('view_all_bookings');
        $base = Booking::query()->where('corporate_account_id', $request->corporate_id)
            ->when(! $all, fn ($query) => $query->where(function ($scope) use ($employee) {
                $scope->where('employee_id', $employee->user_id)
                    ->orWhere('created_by_user_id', $employee->user_id);
            }));

        $tasks = [];
        if ($can('approve_bookings')) {
            $tasks[] = ['key' => 'approvals', 'label' => 'Bookings awaiting approval', 'count' => (clone $base)->where('corporate_approval_status', 'pending')->count(), 'route' => '/corporate/approvals'];
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
            'portal_setup_complete' => $corporate->departments()->exists() && $corporate->employees()->where('is_active', true)->exists(),
            'tasks' => $tasks,
            'capabilities' => collect(['create_bookings', 'bookings.create', 'create_bookings_for_others', 'view_all_bookings', 'approve_bookings', 'view_payments', 'view_reports', 'manage_employees', 'corporate.audit-logs.view'])->mapWithKeys(fn ($permission) => [$permission => $can($permission)]),
        ]]);
    }
}
