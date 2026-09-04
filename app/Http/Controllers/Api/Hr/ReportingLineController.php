<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\PeopleAccessService;
use App\Services\Hr\ReportingLineAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportingLineController extends Controller
{
    public function __construct(
        private readonly PeopleAccessService $access,
        private readonly ReportingLineAdministrationService $reporting,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->enabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate([
            'manager_staff_id' => ['nullable', 'uuid'], 'member_staff_id' => ['nullable', 'uuid'],
            'line_type' => ['nullable', Rule::in(['primary', 'dotted_line', 'hr_partner', 'approval'])],
            'effective_at' => ['nullable', 'date'], 'status' => ['nullable', Rule::in(['active', 'ended', 'cancelled'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        foreach (['manager_staff_id', 'member_staff_id'] as $field) {
            if (! empty($data[$field])) {
                abort_unless(DB::table('staff')->where('id', $data[$field])->where('company_id', $companyId)->exists(), 422, 'Reporting-line Staff filter is outside your legal entity.');
            }
        }
        $query = DB::table('hr_reporting_lines as line')
            ->join('staff as manager_staff', 'manager_staff.id', '=', 'line.manager_staff_id')
            ->leftJoin('users as manager_user', 'manager_user.id', '=', 'manager_staff.user_id')
            ->join('staff as member_staff', 'member_staff.id', '=', 'line.member_staff_id')
            ->leftJoin('users as member_user', 'member_user.id', '=', 'member_staff.user_id')
            ->where('line.company_id', $companyId)
            ->when($data['manager_staff_id'] ?? null, fn ($q, $id) => $q->where('line.manager_staff_id', $id))
            ->when($data['member_staff_id'] ?? null, fn ($q, $id) => $q->where('line.member_staff_id', $id))
            ->when($data['line_type'] ?? null, fn ($q, $type) => $q->where('line.line_type', $type))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('line.status', $status))
            ->when($data['effective_at'] ?? null, fn ($q, $date) => $q->where('line.effective_from', '<=', $date)
                ->where(fn ($active) => $active->whereNull('line.effective_until')->orWhere('line.effective_until', '>', $date)))
            ->select([
                'line.id', 'line.company_id', 'line.manager_staff_id', 'line.member_staff_id', 'line.line_type', 'line.status',
                'line.effective_from', 'line.effective_until', 'line.version',
                'manager_staff.code as manager_employee_number', 'manager_user.first_name as manager_first_name', 'manager_user.last_name as manager_last_name',
                'member_staff.code as member_employee_number', 'member_user.first_name as member_first_name', 'member_user.last_name as member_last_name',
            ])
            ->selectRaw($request->user()->can('hr.reporting-lines.manage') ? 'line.reason' : 'NULL AS reason')
            ->orderByDesc('line.effective_from')->orderBy('line.member_staff_id')->orderBy('line.id');

        return response()->json(['status' => 'success', 'data' => $query->paginate((int) ($data['per_page'] ?? 25))]);
    }

    public function staffOptions(Request $request): JsonResponse
    {
        $this->enabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $search = trim((string) ($data['search'] ?? ''));
        $query = DB::table('staff')->leftJoin('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.company_id', $companyId)->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')
            ->whereExists(fn ($employment) => $employment->selectRaw('1')->from('hr_employment_spells')
                ->whereColumn('hr_employment_spells.staff_id', 'staff.id')->where('hr_employment_spells.status', 'active')->whereNull('hr_employment_spells.terminated_at'))
            ->when($search !== '', fn ($q) => $q->where(fn ($match) => $match
                ->whereRaw('LOWER(staff.code) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(users.first_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(users.last_name) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->select(['staff.id', 'staff.code as employee_number', 'users.first_name', 'users.last_name'])
            ->orderBy('staff.code')->orderBy('staff.id');

        return response()->json(['status' => 'success', 'data' => $query->paginate((int) ($data['per_page'] ?? 50))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->enabled();
        $data = $request->validate($this->commandRules());
        $companyId = $this->access->actorCompanyId($request->user());

        return response()->json(['status' => 'success', 'data' => $this->reporting->create($data, $companyId, (string) $request->user()->id)], 201);
    }

    public function end(Request $request, string $lineId): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'effective_until' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $companyId = $this->access->actorCompanyId($request->user());

        return response()->json(['status' => 'success', 'data' => $this->reporting->end($lineId, $data, $companyId, (string) $request->user()->id)]);
    }

    private function commandRules(): array
    {
        return [
            'manager_staff_id' => ['required', 'uuid', 'exists:staff,id'], 'member_staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'line_type' => ['required', Rule::in(['primary', 'dotted_line', 'hr_partner', 'approval'])],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ];
    }

    private function enabled(): void
    {
        abort_unless(config('hr.features.people_core', false), 409, 'HR People Core is not enabled.');
    }
}
