<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ActingAppointmentAdministrationService;
use App\Services\Hr\PeopleAccessService;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ActingAppointmentController extends Controller
{
    public function __construct(private readonly PeopleAccessService $access, private readonly ActingAppointmentAdministrationService $appointments) {}

    public function index(Request $request): JsonResponse
    {
        $this->enabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $authorizedStaff = $this->access->scope(Staff::query()->select('staff.id'), $request->user());
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending_approval', 'approved'])], 'staff_id' => ['nullable', 'uuid'], 'effective_at' => ['nullable', 'date'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = DB::table('hr_acting_appointments as appointment')->join('staff as employee', 'employee.id', '=', 'appointment.staff_id')->leftJoin('users as employee_user', 'employee_user.id', '=', 'employee.user_id')->join('hr_positions as position', 'position.id', '=', 'appointment.acting_position_id')
            ->where('appointment.company_id', $companyId)->whereIn('appointment.staff_id', $authorizedStaff)->when($data['status'] ?? null, fn ($query, $status) => $query->where('appointment.status', $status))
            ->when($data['staff_id'] ?? null, fn ($query, $staffId) => $query->where('appointment.staff_id', $staffId))
            ->when($data['effective_at'] ?? null, fn ($query, $date) => $query->where('appointment.effective_from', '<=', $date)->where('appointment.effective_until', '>', $date))
            ->select(['appointment.id', 'appointment.staff_id', 'appointment.acting_position_id', 'appointment.acting_manager_staff_id', 'appointment.effective_from', 'appointment.effective_until', 'appointment.status', 'appointment.version', 'appointment.reason', 'appointment.requested_by', 'appointment.approved_by', 'appointment.approved_at', 'appointment.acting_assignment_id', 'appointment.restoration_assignment_id', 'employee.code as employee_number', 'employee_user.first_name as employee_first_name', 'employee_user.last_name as employee_last_name', 'position.position_number', 'position.title as position_title'])
            ->orderByDesc('appointment.effective_from')->orderBy('appointment.id')->paginate((int) ($data['per_page'] ?? 25));
        if (! $request->user()->can('hr.acting-appointments.manage') && ! $request->user()->can('hr.acting-appointments.approve')) $rows->getCollection()->transform(function ($row) { $row->reason = null; $row->requested_by = null; $row->approved_by = null; return $row; });
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function references(Request $request): JsonResponse
    {
        $this->enabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $authorizedStaff = $this->access->scope(Staff::query()->select('staff.id'), $request->user());
        $date = $request->validate(['effective_at' => ['nullable', 'date']])['effective_at'] ?? now()->toDateString();
        $staff = DB::table('staff')->leftJoin('users', 'users.id', '=', 'staff.user_id')->where('staff.company_id', $companyId)->whereIn('staff.id', $authorizedStaff)->whereNull('staff.employment_ended_at')->whereExists(fn ($query) => $query->selectRaw('1')->from('hr_employment_spells')->whereColumn('hr_employment_spells.staff_id', 'staff.id')->where('status', 'active'))
            ->select(['staff.id', 'staff.code as employee_number', 'users.first_name', 'users.last_name'])->orderBy('staff.code')->limit(100)->get();
        $positions = DB::table('hr_positions')->where('company_id', $companyId)->where('status', 'active')->where('effective_from', '<=', $date)->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $date))
            ->select(['id', 'organization_unit_id', 'position_number', 'title', 'headcount_limit'])->orderBy('position_number')->limit(100)->get();
        return response()->json(['status' => 'success', 'data' => ['staff' => $staff, 'positions' => $positions, 'reference_limit' => 100]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['staff_id' => ['required', 'uuid'], 'acting_position_id' => ['required', 'uuid'], 'acting_manager_staff_id' => ['nullable', 'uuid'], 'effective_from' => ['required', 'date'], 'effective_until' => ['required', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $staff = Staff::query()->whereKey($data['staff_id'])->firstOrFail();
        $this->access->authorize($request->user(), $staff);
        return response()->json(['status' => 'success', 'data' => $this->appointments->create($data, $this->access->actorCompanyId($request->user()), (string) $request->user()->id)], 201);
    }

    public function approve(Request $request, string $appointmentId): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $companyId = $this->access->actorCompanyId($request->user());
        $appointment = DB::table('hr_acting_appointments')->where('id', $appointmentId)->where('company_id', $companyId)->first();
        abort_unless($appointment, 404, 'Acting appointment was not found in your legal entity.');
        $this->access->authorize($request->user(), Staff::query()->whereKey($appointment->staff_id)->firstOrFail());
        return response()->json(['status' => 'success', 'data' => $this->appointments->approve($appointmentId, $data, $companyId, (string) $request->user()->id)]);
    }

    private function enabled(): void
    {
        abort_unless(config('hr.features.people_core', false), 409, 'HR People Core writes are not enabled.');
    }
}
