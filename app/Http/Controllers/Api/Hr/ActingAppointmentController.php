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
            ->select(['appointment.id', 'appointment.effective_from', 'appointment.effective_until', 'appointment.status', 'appointment.version', 'appointment.reason', 'employee.code as employee_number', 'employee_user.first_name as employee_first_name', 'employee_user.last_name as employee_last_name', 'position.position_number', 'position.title as position_title'])
            ->selectRaw('CASE WHEN appointment.restoration_assignment_id IS NULL THEN 0 ELSE 1 END as has_restoration')
            ->orderByDesc('appointment.effective_from')->orderBy('appointment.id')->paginate((int) ($data['per_page'] ?? 25));
        $canViewReason = $request->user()->can('hr.acting-appointments.manage') || $request->user()->can('hr.acting-appointments.approve');
        $rows->getCollection()->transform(fn ($row) => [
            'id' => (string) $row->id,
            'effective_from' => $row->effective_from,
            'effective_until' => $row->effective_until,
            'status' => $row->status,
            'version' => (int) $row->version,
            'reason' => $canViewReason ? $row->reason : null,
            'employee_number' => $row->employee_number,
            'employee_first_name' => $row->employee_first_name,
            'employee_last_name' => $row->employee_last_name,
            'position_number' => $row->position_number,
            'position_title' => $row->position_title,
            'has_restoration' => (bool) $row->has_restoration,
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function referenceOptions(Request $request): JsonResponse
    {
        $this->enabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate([
            'record_type' => ['required', Rule::in(['staff', 'position'])],
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'],
            'exclude_id' => ['nullable', 'uuid'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $from = $data['effective_from'] ?? now()->toDateString();
        $until = $data['effective_until'] ?? null;
        $query = $data['record_type'] === 'staff'
            ? DB::table('staff')->leftJoin('users', 'users.id', '=', 'staff.user_id')
                ->where('staff.company_id', $companyId)->whereIn('staff.id', $this->access->scope(Staff::query()->select('staff.id'), $request->user()))
                ->whereNull('staff.employment_ended_at')->whereExists(fn ($spell) => $spell->selectRaw('1')->from('hr_employment_spells')->whereColumn('hr_employment_spells.staff_id', 'staff.id')->where('hr_employment_spells.company_id', $companyId)->where('hr_employment_spells.status', 'active'))
            : DB::table('hr_positions')->where('company_id', $companyId)->where('status', 'active')
                ->where('effective_from', '<=', $from)->where(fn ($range) => $until === null
                    ? $range->whereNull('effective_until')
                    : $range->whereNull('effective_until')->orWhere('effective_until', '>=', $until));
        $idColumn = $data['record_type'] === 'staff' ? 'staff.id' : 'hr_positions.id';
        if (! empty($data['exclude_id'])) $query->where($idColumn, '<>', $data['exclude_id']);
        if (! empty($data['selected_id'])) $query->where($idColumn, $data['selected_id']);
        else {
            $search = trim((string) ($data['search'] ?? ''));
            if ($search !== '') {
                if ($data['record_type'] === 'staff') {
                    $query->where(fn ($matches) => $matches->whereRaw("LOWER(COALESCE(users.first_name, '')) LIKE ?", ['%' . mb_strtolower($search) . '%'])->orWhereRaw("LOWER(COALESCE(users.last_name, '')) LIKE ?", ['%' . mb_strtolower($search) . '%'])->orWhereRaw('LOWER(staff.code) LIKE ?', ['%' . mb_strtolower($search) . '%']));
                } else {
                    $query->where(fn ($matches) => $matches->whereRaw('LOWER(hr_positions.title) LIKE ?', ['%' . mb_strtolower($search) . '%'])->orWhereRaw('LOWER(hr_positions.position_number) LIKE ?', ['%' . mb_strtolower($search) . '%']));
                }
            }
        }
        $columns = $data['record_type'] === 'staff'
            ? ['staff.id', 'staff.code as code', 'users.first_name', 'users.last_name']
            : ['hr_positions.id', 'hr_positions.position_number as code', 'hr_positions.title as name', 'hr_positions.organization_unit_id'];
        $rows = $query->select($columns)->orderBy('code')->orderBy($idColumn)->paginate((int) ($data['per_page'] ?? 25));
        $rows->getCollection()->transform(fn ($row) => [
            'value' => (string) $row->id,
            'label' => $data['record_type'] === 'staff'
                ? (trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: 'Unnamed Staff') . ' · ' . $row->code
                : $row->code . ' · ' . $row->name,
            'metadata' => $data['record_type'] === 'position' ? ['title' => $row->name] : [],
            'status' => 'active',
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['staff_id' => ['required', 'uuid'], 'acting_position_id' => ['required', 'uuid'], 'acting_manager_staff_id' => ['nullable', 'uuid'], 'effective_from' => ['required', 'date'], 'effective_until' => ['required', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $staff = Staff::query()->whereKey($data['staff_id'])->firstOrFail();
        $this->access->authorize($request->user(), $staff);
        return response()->json(['status' => 'success', 'data' => $this->commandResponse($this->appointments->create($data, $this->access->actorCompanyId($request->user()), (string) $request->user()->id))], 201);
    }

    public function approve(Request $request, string $appointmentId): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $companyId = $this->access->actorCompanyId($request->user());
        $appointment = DB::table('hr_acting_appointments')->where('id', $appointmentId)->where('company_id', $companyId)->first();
        abort_unless($appointment, 404, 'Acting appointment was not found in your legal entity.');
        $this->access->authorize($request->user(), Staff::query()->whereKey($appointment->staff_id)->firstOrFail());
        return response()->json(['status' => 'success', 'data' => $this->commandResponse($this->appointments->approve($appointmentId, $data, $companyId, (string) $request->user()->id))]);
    }

    private function commandResponse(array $appointment): array
    {
        return [
            'id' => (string) $appointment['id'],
            'effective_from' => $appointment['effective_from'],
            'effective_until' => $appointment['effective_until'],
            'status' => $appointment['status'],
            'version' => (int) $appointment['version'],
            'has_restoration' => ! empty($appointment['restoration_assignment_id']),
        ];
    }

    private function enabled(): void
    {
        abort_unless(config('hr.features.people_core', false), 409, 'HR People Core writes are not enabled.');
    }
}
