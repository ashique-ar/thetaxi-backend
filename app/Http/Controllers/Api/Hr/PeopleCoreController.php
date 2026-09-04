<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Hr\HrEmployeeTimelineEvent;
use App\Models\Hr\HrRehireCase;
use App\Models\Hr\HrStaffProfileVersion;
use App\Models\Hr\HrEmployeeRecord;
use App\Models\Document;
use App\Models\Staff;
use App\Services\Hr\PeopleCoreService;
use App\Services\Hr\PeopleAccessService;
use App\Services\Hr\OrganizationAdministrationService;
use App\Services\Hr\JobPositionAdministrationService;
use App\Services\Hr\StaffCustomFieldValueService;
use App\Services\Hr\SubjectCustomFieldValueService;
use App\Services\Sales\SalesPolicySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PeopleCoreController extends Controller
{
    public function __construct(
        private readonly PeopleAccessService $access,
        private readonly PeopleCoreService $people,
        private readonly OrganizationAdministrationService $organizationAdmin,
        private readonly JobPositionAdministrationService $jobPositionAdmin,
        private readonly StaffCustomFieldValueService $customFieldValues,
        private readonly SubjectCustomFieldValueService $subjectCustomFieldValues,
        private readonly SalesPolicySettingsService $policySettings,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'former'])],
            'staff_type' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $at = now();
        $query = $this->access->scope(Staff::withTrashed()->with([
            'user:id,first_name,last_name,email,phone,is_active',
            'company:id,name',
            'employmentSpells' => fn($spells) => $spells->orderByDesc('spell_number'),
            'employmentAssignments' => fn($assignments) => $assignments
                ->where('effective_from', '<=', $at)
                ->where(fn($current) => $current->whereNull('effective_until')->orWhere('effective_until', '>', $at))
                ->orderByDesc('effective_from'),
        ]), $request->user());
        $search = trim((string) ($data['search'] ?? ''));
        $query->when($search !== '', fn(Builder $staff) => $staff->where(fn(Builder $match) => $match
            ->whereLikeInsensitive('code', $search)
            ->orWhereLikeInsensitive('staff_type', $search)
            ->orWhereHas('user', fn(Builder $user) => $user
                ->whereLikeInsensitive('first_name', $search)
                ->orWhereLikeInsensitive('last_name', $search)
                ->orWhereLikeInsensitive('email', $search))))
            ->when(($data['staff_type'] ?? null), fn(Builder $staff, string $type) => $staff->where('staff_type', $type))
            ->when(($data['status'] ?? null) === 'active', fn(Builder $staff) => $staff->whereNull('deleted_at')->whereNull('employment_ended_at'))
            ->when(($data['status'] ?? null) === 'former', fn(Builder $staff) => $staff->where(fn(Builder $former) => $former->whereNotNull('deleted_at')->orWhereNotNull('employment_ended_at')))
            ->orderBy('code')->orderBy('id');
        $page = $query->paginate((int) ($data['per_page'] ?? 25));
        $page->getCollection()->transform(fn(Staff $staff) => $this->employeeSummary($staff));

        return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function show(Request $request, string $staffId): JsonResponse
    {
        $this->ensureEnabled();
        $staff = Staff::withTrashed()->with([
            'user:id,first_name,last_name,email,phone,is_active',
            'company:id,name',
            'employmentSpells' => fn($spells) => $spells->with('assignments')->orderByDesc('spell_number'),
            'employmentAssignments' => fn($assignments) => $assignments->orderByDesc('effective_from'),
        ])->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        $at = now()->toDateString();
        $reportingLines = DB::table('hr_reporting_lines as line')->join('staff as manager_staff', 'manager_staff.id', '=', 'line.manager_staff_id')->leftJoin('users as manager_user', 'manager_user.id', '=', 'manager_staff.user_id')
            ->where('line.company_id', $staff->company_id)->where('line.member_staff_id', $staff->id)->where('line.effective_from', '<=', $at)->where(fn($query) => $query->whereNull('line.effective_until')->orWhere('line.effective_until', '>', $at))
            ->select(['line.id', 'line.manager_staff_id', 'line.line_type', 'line.effective_from', 'line.effective_until', 'line.version', 'manager_staff.code as manager_employee_number', 'manager_user.first_name as manager_first_name', 'manager_user.last_name as manager_last_name'])->orderBy('line.line_type')->get();
        $actingAppointments = DB::table('hr_acting_appointments as appointment')->join('hr_positions as position', 'position.id', '=', 'appointment.acting_position_id')
            ->where('appointment.company_id', $staff->company_id)->where('appointment.staff_id', $staff->id)
            ->select(['appointment.id', 'appointment.effective_from', 'appointment.effective_until', 'appointment.status', 'appointment.version', 'appointment.acting_assignment_id', 'appointment.restoration_assignment_id', 'position.position_number', 'position.title as position_title'])->orderByDesc('appointment.effective_from')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'employee' => $this->employeeSummary($staff),
                'employment_history' => $staff->employmentSpells,
                'current_reporting_lines' => $reportingLines,
                'acting_appointments' => $actingAppointments,
                'custom_field_values' => $this->customFieldValues->listForStaff($staff, $request->user()),
                'document_compliance' => $this->documentCompliance($staff),
            ]
        ]);
    }

    public function putStaffCustomFieldValue(Request $request, string $staffId, string $definitionId): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['definition_version' => ['required', 'integer', 'min:1'], 'expected_version' => ['nullable', 'integer', 'min:0'], 'effective_from' => ['required', 'date'], 'value' => ['present'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $staff = Staff::withTrashed()->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        return response()->json(['status' => 'success', 'data' => $this->customFieldValues->put($staff, $definitionId, $data, $request->user())]);
    }

    public function subjectCustomFieldValues(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $this->ensureEnabled();
        $this->assertSubjectType($ownerType);
        $companyId = $this->access->actorCompanyId($request->user());
        return response()->json(['status' => 'success', 'data' => $this->subjectCustomFieldValues->listFor($ownerType, $ownerId, $companyId, $request->user())]);
    }

    public function putSubjectCustomFieldValue(Request $request, string $ownerType, string $ownerId, string $definitionId): JsonResponse
    {
        $this->ensureEnabled();
        $this->assertSubjectType($ownerType);
        $data = $request->validate(['definition_version' => ['required', 'integer', 'min:1'], 'expected_version' => ['nullable', 'integer', 'min:0'], 'effective_from' => ['required', 'date'], 'value' => ['present'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $companyId = $this->access->actorCompanyId($request->user());
        return response()->json(['status' => 'success', 'data' => $this->subjectCustomFieldValues->put($ownerType, $ownerId, $definitionId, $data, $companyId, $request->user())]);
    }

    private function assertSubjectType(string $ownerType): void
    {
        abort_unless(in_array($ownerType, ['organization_unit', 'position', 'employment_spell'], true), 422, 'Unsupported custom-field subject type.');
    }

    public function organization(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'unit_type' => ['nullable', 'string', 'max:40'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->access->actorCompanyId($request->user());
        abort_if(isset($data['company_id']) && $data['company_id'] !== $companyId, 403, 'The requested organization is outside your legal entity.');
        $search = trim((string) ($data['search'] ?? ''));
        $rows = DB::table('hr_organization_units')->where('company_id', $companyId)->when($data['unit_type'] ?? null, fn($q, $type) => $q->where('unit_type', $type))->when($data['status'] ?? null, fn($q, $status) => $q->where('status', $status))->when($search !== '', fn($q) => $q->where(fn($match) => $match->whereRaw('LOWER(code) LIKE ?', ['%' . mb_strtolower($search) . '%'])->orWhereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%'])))->orderBy('name')->orderBy('id')->paginate((int) ($data['per_page'] ?? 50));
        $rows->getCollection()->transform(fn($row) => $this->decodeJsonColumns($row, ['custom_fields']));
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /**
     * §5.1/QH1-01: "dated organization chart/HR dashboard." The pre-existing
     * organization() list is flat and paginated (up to 100 rows per page), so it
     * cannot render a hierarchy. This read-only endpoint returns every unit
     * effective on the requested date (default today) for the actor's legal
     * entity in one response — a chart needs the whole graph, not one page of
     * it — each carrying its parent, manager identity (reusing the exact
     * staff/users join pattern already used for reporting-line manager
     * display), and current active position count. No new schema.
     */
    public function organizationChart(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $companyId = $this->access->actorCompanyId($request->user());
        $asOf = $data['as_of'] ?? now()->toDateString();
        $units = DB::table('hr_organization_units as unit')
            ->leftJoin('staff as manager_staff', 'manager_staff.id', '=', 'unit.manager_staff_id')
            ->leftJoin('users as manager_user', 'manager_user.id', '=', 'manager_staff.user_id')
            ->where('unit.company_id', $companyId)->where('unit.effective_from', '<=', $asOf)
            ->where(fn($q) => $q->whereNull('unit.effective_until')->orWhere('unit.effective_until', '>', $asOf))
            ->select(['unit.id', 'unit.parent_id', 'unit.unit_type', 'unit.code', 'unit.name', 'unit.status', 'unit.manager_staff_id', 'manager_staff.code as manager_employee_number', 'manager_user.first_name as manager_first_name', 'manager_user.last_name as manager_last_name'])
            ->orderBy('unit.name')->get();
        $positionCounts = DB::table('hr_positions')->where('company_id', $companyId)->where('status', '!=', 'inactive')
            ->where('effective_from', '<=', $asOf)->where(fn($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $asOf))
            ->groupBy('organization_unit_id')->selectRaw('organization_unit_id, count(*) as count')->pluck('count', 'organization_unit_id');
        $rows = $units->map(fn($unit) => (array) $unit + ['position_count' => (int) ($positionCounts[$unit->id] ?? 0)])->values();
        return response()->json(['status' => 'success', 'data' => ['as_of' => $asOf, 'units' => $rows]]);
    }

    public function storeOrganizationUnit(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->organizationUnitRules() + ['company_id' => ['nullable', 'uuid', 'exists:companies,id']]);
        $companyId = $this->access->actorCompanyId($request->user());
        abort_if(isset($data['company_id']) && $data['company_id'] !== $companyId, 403, 'The organization unit must belong to your legal entity.');
        $this->assertOrganizationStaffReferences($data, $companyId);
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->createUnit($data, $companyId, (string) $request->user()->id)], 201);
    }

    public function updateOrganizationUnit(Request $request, string $unitId): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate($this->organizationUnitRules(true));
        $this->assertOrganizationStaffReferences($data, $companyId);
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->updateUnit($unitId, $data, $companyId, (string) $request->user()->id)]);
    }

    public function payrollGroups(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['status' => ['nullable', Rule::in(['active', 'inactive'])], 'search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->access->actorCompanyId($request->user());
        $search = trim((string) ($data['search'] ?? ''));
        $rows = DB::table('hr_payroll_groups')->where('company_id', $companyId)->when($data['status'] ?? null, fn($q, $status) => $q->where('status', $status))->when($search !== '', fn($q) => $q->where(fn($match) => $match->whereRaw('LOWER(code) LIKE ?', ['%' . mb_strtolower($search) . '%'])->orWhereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%'])))->orderBy('name')->orderBy('id')->paginate((int) ($data['per_page'] ?? 50));
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function storePayrollGroup(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->payrollGroupRules());
        $companyId = $this->access->actorCompanyId($request->user());
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->createPayrollGroup($data, $companyId, (string) $request->user()->id)], 201);
    }

    public function updatePayrollGroup(Request $request, string $groupId): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->payrollGroupRules(true));
        $companyId = $this->access->actorCompanyId($request->user());
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->updatePayrollGroup($groupId, $data, $companyId, (string) $request->user()->id)]);
    }

    private function payrollGroupRules(bool $update = false): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'pay_frequency' => ['required', Rule::in(['monthly', 'semi_monthly', 'bi_weekly', 'weekly', 'four_weekly'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => $update ? ['required', Rule::in(['active', 'inactive'])] : ['prohibited'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            ...($update ? ['expected_version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    public function documentTypes(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['category' => ['nullable', 'string', 'max:40'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->access->actorCompanyId($request->user());
        $search = trim((string) ($data['search'] ?? ''));
        $rows = DB::table('hr_document_types')->where('company_id', $companyId)->when($data['category'] ?? null, fn($q, $category) => $q->where('category', $category))->when($data['status'] ?? null, fn($q, $status) => $q->where('status', $status))->when($search !== '', fn($q) => $q->where(fn($match) => $match->whereRaw('LOWER(code) LIKE ?', ['%' . mb_strtolower($search) . '%'])->orWhereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%'])))->orderBy('category')->orderBy('name')->orderBy('id')->paginate((int) ($data['per_page'] ?? 50));
        $rows->getCollection()->transform(fn($row) => $this->decodeJsonColumns($row, ['required_for_staff_types', 'required_for_employment_types']));
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function storeDocumentType(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->documentTypeRules());
        $companyId = $this->access->actorCompanyId($request->user());
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->createDocumentType($data, $companyId, (string) $request->user()->id)], 201);
    }

    public function updateDocumentType(Request $request, string $typeId): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->documentTypeRules(true));
        $companyId = $this->access->actorCompanyId($request->user());
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->updateDocumentType($typeId, $data, $companyId, (string) $request->user()->id)]);
    }

    private function documentTypeRules(bool $update = false): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['contract', 'appointment_letter', 'policy_acknowledgement', 'certificate', 'identification', 'bank_evidence', 'disciplinary_document', 'exit_document', 'other'])],
            'required_for_staff_types' => ['nullable', 'array'],
            'required_for_staff_types.*' => ['string', 'max:80'],
            'required_for_employment_types' => ['nullable', 'array'],
            'required_for_employment_types.*' => ['string', 'max:80'],
            'requires_expiry' => ['required', 'boolean'],
            'renewal_reminder_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => $update ? ['required', Rule::in(['active', 'inactive'])] : ['prohibited'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            ...($update ? ['expected_version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    public function workCalendars(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $rows = DB::table('hr_work_calendars')->where('company_id', $companyId)->orderBy('name')->orderBy('id')->get();
        return response()->json(['status' => 'success', 'data' => $rows->map(fn($row) => $this->decodeJsonColumns($row, ['weekly_working_days']))->values()]);
    }

    public function storeWorkCalendar(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone'], 'weekly_working_days' => ['required', 'array', 'min:1'], 'weekly_working_days.*' => ['required', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $companyId = $this->access->actorCompanyId($request->user());
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->createWorkCalendar($data, $companyId, (string) $request->user()->id)], 201);
    }

    public function workCalendarDays(Request $request, string $calendarId): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        abort_unless(DB::table('hr_work_calendars')->where('id', $calendarId)->where('company_id', $companyId)->exists(), 404, 'Work calendar was not found in your legal entity.');
        $rows = DB::table('hr_work_calendar_days')->where('calendar_id', $calendarId)->orderBy('calendar_date')->get();
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function storeWorkCalendarDay(Request $request, string $calendarId): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['calendar_date' => ['required', 'date'], 'day_type' => ['required', Rule::in(['working', 'holiday', 'rest_day', 'special_leave'])], 'name' => ['nullable', 'string', 'max:255'], 'paid' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $companyId = $this->access->actorCompanyId($request->user());
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->createWorkCalendarDay($calendarId, $data, $companyId, (string) $request->user()->id)], 201);
    }

    public function customFieldDefinitions(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate(['applies_to' => ['nullable', Rule::in(['staff', 'organization_unit', 'position', 'employment_spell'])], 'active' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = DB::table('hr_custom_field_definitions')->where('company_id', $companyId)->when($data['applies_to'] ?? null, fn($q, $type) => $q->where('applies_to', $type))->when(array_key_exists('active', $data), fn($q) => $q->where('active', (bool) $data['active']))->orderBy('applies_to')->orderBy('label')->orderBy('id')->paginate((int) ($data['per_page'] ?? 50));
        $rows->getCollection()->transform(fn($row) => $this->decodeJsonColumns($row, ['validation_rules']));
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function storeCustomFieldDefinition(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate($this->customFieldRules());
        $this->assertCustomFieldRules($data);
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->createDefinition($data, $companyId, (string) $request->user()->id)], 201);
    }

    public function updateCustomFieldDefinition(Request $request, string $definitionId): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate($this->customFieldRules(true));
        $this->assertCustomFieldRules($data);
        return response()->json(['status' => 'success', 'data' => $this->organizationAdmin->updateDefinition($definitionId, $data, $companyId, (string) $request->user()->id)]);
    }

    public function jobFamilies(Request $request): JsonResponse
    {
        return $this->jobCatalog($request, 'hr_job_families');
    }

    public function storeJobFamily(Request $request): JsonResponse
    {
        return $this->storeJobCatalog($request, 'job_family', $this->jobFamilyRules());
    }

    public function updateJobFamily(Request $request, string $familyId): JsonResponse
    {
        return $this->updateJobCatalog($request, 'job_family', 'hr_job_families', $familyId, $this->jobFamilyRules(true));
    }

    public function jobGrades(Request $request): JsonResponse
    {
        return $this->jobCatalog($request, 'hr_job_grades');
    }

    public function storeJobGrade(Request $request): JsonResponse
    {
        $data = $request->validate($this->jobGradeRules());
        $this->assertSalaryRange($data);

        return $this->storeJobCatalogData($request, 'job_grade', $data);
    }

    public function updateJobGrade(Request $request, string $gradeId): JsonResponse
    {
        $data = $request->validate($this->jobGradeRules(true));
        $this->assertSalaryRange($data);

        return $this->updateJobCatalogData($request, 'job_grade', 'hr_job_grades', $gradeId, $data);
    }

    public function designations(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate($this->catalogListRules() + [
            'job_family_id' => ['nullable', 'uuid'],
            'job_grade_id' => ['nullable', 'uuid'],
        ]);
        $this->assertOptionalCompanyReference($data['job_family_id'] ?? null, 'hr_job_families', $companyId, 'Job family');
        $this->assertOptionalCompanyReference($data['job_grade_id'] ?? null, 'hr_job_grades', $companyId, 'Job grade');
        $rows = $this->catalogQuery('hr_designations', $companyId, $data)
            ->when($data['job_family_id'] ?? null, fn($query, $id) => $query->where('job_family_id', $id))
            ->when($data['job_grade_id'] ?? null, fn($query, $id) => $query->where('job_grade_id', $id))
            ->paginate((int) ($data['per_page'] ?? 50));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function storeDesignation(Request $request): JsonResponse
    {
        return $this->storeJobCatalog($request, 'designation', $this->designationRules());
    }

    public function updateDesignation(Request $request, string $designationId): JsonResponse
    {
        return $this->updateJobCatalog($request, 'designation', 'hr_designations', $designationId, $this->designationRules(true));
    }

    public function positions(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate($this->catalogListRules() + [
            'organization_unit_id' => ['nullable', 'uuid'],
            'designation_id' => ['nullable', 'uuid'],
            'availability' => ['nullable', Rule::in(['vacant', 'partially_filled', 'filled', 'inactive'])],
        ]);
        $this->assertOptionalCompanyReference($data['organization_unit_id'] ?? null, 'hr_organization_units', $companyId, 'Organization unit');
        $this->assertOptionalCompanyReference($data['designation_id'] ?? null, 'hr_designations', $companyId, 'Designation');
        $asOf = $data['effective_at'] ?? now()->toDateString();
        $occupancy = DB::table('hr_employment_assignments')
            ->where('effective_from', '<=', $asOf)
            ->where(fn($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $asOf))
            ->selectRaw('position_id, COUNT(*) AS occupied_count')
            ->groupBy('position_id');
        $search = trim((string) ($data['search'] ?? ''));
        $rows = DB::table('hr_positions')
            ->leftJoinSub($occupancy, 'occupancy', fn($join) => $join->on('occupancy.position_id', '=', 'hr_positions.id'))
            ->where('hr_positions.company_id', $companyId)
            ->when($data['status'] ?? null, fn($query, $status) => $query->where('hr_positions.status', $status))
            ->when($search !== '', fn($query) => $query->where(fn($match) => $match
                ->whereRaw('LOWER(hr_positions.position_number) LIKE ?', ['%' . mb_strtolower($search) . '%'])
                ->orWhereRaw('LOWER(hr_positions.title) LIKE ?', ['%' . mb_strtolower($search) . '%'])))
            ->when($data['effective_at'] ?? null, fn($query, $date) => $query->where('hr_positions.effective_from', '<=', $date)
                ->where(fn($active) => $active->whereNull('hr_positions.effective_until')->orWhere('hr_positions.effective_until', '>', $date)))
            ->when($data['organization_unit_id'] ?? null, fn($query, $id) => $query->where('organization_unit_id', $id))
            ->when($data['designation_id'] ?? null, fn($query, $id) => $query->where('designation_id', $id))
            ->when(($data['availability'] ?? null) === 'inactive', fn($query) => $query->where('hr_positions.status', 'inactive'))
            ->when(($data['availability'] ?? null) === 'vacant', fn($query) => $query->where('hr_positions.status', '!=', 'inactive')->whereRaw('COALESCE(occupancy.occupied_count, 0) = 0'))
            ->when(($data['availability'] ?? null) === 'partially_filled', fn($query) => $query->where('hr_positions.status', '!=', 'inactive')->whereRaw('COALESCE(occupancy.occupied_count, 0) > 0')->whereColumn('occupancy.occupied_count', '<', 'hr_positions.headcount_limit'))
            ->when(($data['availability'] ?? null) === 'filled', fn($query) => $query->where('hr_positions.status', '!=', 'inactive')->whereColumn('occupancy.occupied_count', '>=', 'hr_positions.headcount_limit'))
            ->select('hr_positions.*')
            ->selectRaw('COALESCE(occupancy.occupied_count, 0) AS occupied_count')
            ->orderBy('hr_positions.position_number')->orderBy('hr_positions.id')
            ->paginate((int) ($data['per_page'] ?? 50));
        $rows->getCollection()->transform(fn($row) => $this->jobPositionAdmin->withOccupancy((array) $row, (int) $row->occupied_count));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function storePosition(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate($this->positionRules());

        return response()->json(['status' => 'success', 'data' => $this->jobPositionAdmin->createPosition($data, $companyId, (string) $request->user()->id)], 201);
    }

    public function updatePosition(Request $request, string $positionId): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate($this->positionRules(true));

        return response()->json(['status' => 'success', 'data' => $this->jobPositionAdmin->updatePosition($positionId, $data, $companyId, (string) $request->user()->id)]);
    }

    public function employmentHistory(Request $request, string $staffId): JsonResponse
    {
        $this->ensureEnabled();
        $staff = Staff::withTrashed()->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        return response()->json(['status' => 'success', 'data' => HrEmploymentSpell::query()->where('staff_id', $staff->id)->with('assignments')->orderBy('spell_number')->get()]);
    }

    public function timeline(Request $request, string $staffId): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['domain' => ['nullable', 'string', 'max:50'], 'employment_spell_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $staff = Staff::withTrashed()->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        if (!empty($data['employment_spell_id']))
            abort_unless(HrEmploymentSpell::query()->whereKey($data['employment_spell_id'])->where('staff_id', $staff->id)->where('company_id', $staff->company_id)->exists(), 422, 'The timeline employment spell does not belong to this employee.');
        $query = HrEmployeeTimelineEvent::query()->where('staff_id', $staff->id);
        if (!$request->user()->can('hr.people.timeline-confidential'))
            $query->whereIn('confidentiality', ['employee', 'manager', 'internal']);
        $query->when($data['domain'] ?? null, fn($q, $domain) => $q->where('domain', $domain))->when($data['employment_spell_id'] ?? null, fn($q, $spell) => $q->where('employment_spell_id', $spell))->when($data['from'] ?? null, fn($q, $from) => $q->where('effective_at', '>=', $from))->when($data['to'] ?? null, fn($q, $to) => $q->where('effective_at', '<', date('Y-m-d', strtotime($to . ' +1 day'))));
        return response()->json(['status' => 'success', 'data' => $query->orderByDesc('effective_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25))]);
    }

    public function prepareRehire(Request $request, string $staffId): JsonResponse
    {
        $this->ensureEnabled();
        $staff = Staff::withTrashed()->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        $data = $request->validate(['proposed_rehire_date' => ['required', 'date', 'after_or_equal:today'], 'eligibility_snapshot' => ['required', 'array'], 'prior_service_decisions' => ['required', 'array'], 'prior_service_decisions.gratuity' => ['required', 'string'], 'prior_service_decisions.leave' => ['required', 'string'], 'access_reactivation_plan' => ['nullable', 'array'], 'benefit_statutory_review' => ['required', 'array'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        return response()->json(['status' => 'success', 'data' => $this->people->prepareRehire($staff, $data, (string) $request->user()->id)], 201);
    }

    public function approveRehire(Request $request, HrRehireCase $case): JsonResponse
    {
        $this->ensureEnabled();
        $staff = Staff::withTrashed()->findOrFail($case->staff_id);
        $this->access->authorize($request->user(), $staff);
        abort_unless($staff->company_id === $case->company_id, 409, 'The rehire case and employee legal entity do not match.');
        $assignment = $request->validate(['employment_type_id' => ['nullable', 'uuid', 'exists:hr_employment_types,id'], 'position_id' => ['nullable', 'uuid', 'exists:hr_positions,id'], 'organization_unit_id' => ['nullable', 'uuid', 'exists:hr_organization_units,id'], 'manager_staff_id' => ['nullable', 'uuid', 'exists:staff,id'], 'cost_centre_code' => ['nullable', 'string', 'max:80'], 'location_code' => ['nullable', 'string', 'max:80'], 'payroll_group_code' => ['nullable', 'string', 'max:80'], 'default_shift_code' => ['nullable', 'string', 'max:80'], 'work_pattern_code' => ['nullable', 'string', 'max:80']]);
        foreach (['employment_type_id' => 'hr_employment_types', 'position_id' => 'hr_positions', 'organization_unit_id' => 'hr_organization_units'] as $field => $table) {
            if (!empty($assignment[$field]))
                abort_unless(DB::table($table)->where('id', $assignment[$field])->where('company_id', $case->company_id)->exists(), 422, "{$field} must belong to the employee legal entity.");
        }
        if (!empty($assignment['manager_staff_id'])) {
            abort_if($assignment['manager_staff_id'] === $staff->id, 422, 'A rehired employee cannot manage their own assignment.');
            abort_unless(Staff::query()->whereKey($assignment['manager_staff_id'])->where('company_id', $case->company_id)->whereNull('employment_ended_at')->exists(), 422, 'An active manager must belong to the employee legal entity.');
        }
        return response()->json(['status' => 'success', 'data' => $this->people->approveRehire($case, $assignment, (string) $request->user()->id)]);
    }

    public function profileVersions(Request $request, string $staffId): JsonResponse
    {
        $this->ensureEnabled();
        $staff = Staff::withTrashed()->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        $rows = HrStaffProfileVersion::query()->where('staff_id', $staff->id)->latest('version')->get();
        if ($request->user()->can('hr.people.timeline-confidential'))
            $rows->each(fn($row) => $row->makeVisible('encrypted_profile'));
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function storeProfileVersion(Request $request, string $staffId): JsonResponse
    {
        $this->ensureEnabled();
        $staff = Staff::withTrashed()->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        $data = $request->validate(['profile' => ['required', 'array'], 'change_reason' => ['required', 'string', 'max:2000']]);
        $row = $this->people->addProfileVersion($staff, $data['profile'], $data['change_reason'], (string) $request->user()->id);
        $row->makeVisible('encrypted_profile');
        return response()->json(['status' => 'success', 'data' => $row], 201);
    }

    public function records(Request $request, string $staffId): JsonResponse
    {
        $this->ensureEnabled();
        $staff = Staff::withTrashed()->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        $query = HrEmployeeRecord::query()->where('staff_id', $staff->id);
        if (!$request->user()->can('hr.people.timeline-confidential'))
            $query->whereIn('confidentiality', ['employee', 'manager', 'internal']);
        $page = $query->latest('effective_date')->paginate($request->integer('per_page', 50));
        if ($request->user()->can('hr.people.timeline-confidential'))
            $page->getCollection()->each(fn($row) => $row->makeVisible('encrypted_data'));
        return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function storeRecord(Request $request, string $staffId): JsonResponse
    {
        $this->ensureEnabled();
        $staff = Staff::withTrashed()->findOrFail($staffId);
        $this->access->authorize($request->user(), $staff);
        $data = $request->validate(['employment_spell_id' => ['nullable', 'uuid', 'exists:hr_employment_spells,id'], 'record_type' => ['required', Rule::in(['emergency_contact', 'dependent', 'beneficiary', 'qualification', 'skill', 'language', 'membership', 'licence', 'certification', 'career', 'achievement', 'award', 'note'])], 'title' => ['required', 'string', 'max:255'], 'data' => ['required', 'array'], 'effective_date' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date', 'after_or_equal:effective_date'], 'confidentiality' => ['required', Rule::in(['employee', 'manager', 'internal', 'hr_private', 'legal'])], 'source' => ['required', 'string', 'max:60'], 'employee_submitted' => ['nullable', 'boolean'], 'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id']]);
        if (!empty($data['employment_spell_id']))
            abort_unless(HrEmploymentSpell::query()->whereKey($data['employment_spell_id'])->where('staff_id', $staff->id)->where('company_id', $staff->company_id)->exists(), 422, 'The employment spell does not belong to this employee.');
        if (!empty($data['evidence_file_id']))
            abort_unless(DB::table('domain_evidence_files')->where('id', $data['evidence_file_id'])->where('company_id', $staff->company_id)->where('domain', 'hr')->whereNull('deleted_at')->exists(), 422, 'Employee-record evidence must be an active HR file from the same legal entity.');
        $payload = $data;
        $payload['encrypted_data'] = $payload['data'];
        unset($payload['data']);
        $payload['verification_status'] = 'unverified';
        $row = $this->people->addEmployeeRecord($staff, $payload, (string) $request->user()->id);
        $row->makeVisible('encrypted_data');
        return response()->json(['status' => 'success', 'data' => $row], 201);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('hr.features.people_core', false), 409, 'HR People Core is not enabled.');
    }

    private function jobCatalog(Request $request, string $table): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $data = $request->validate($this->catalogListRules());
        $rows = $this->catalogQuery($table, $companyId, $data)->paginate((int) ($data['per_page'] ?? 50));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    private function storeJobCatalog(Request $request, string $type, array $rules): JsonResponse
    {
        return $this->storeJobCatalogData($request, $type, $request->validate($rules));
    }

    private function storeJobCatalogData(Request $request, string $type, array $data): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());

        return response()->json(['status' => 'success', 'data' => $this->jobPositionAdmin->createCatalog($type, $data, $companyId, (string) $request->user()->id)], 201);
    }

    private function updateJobCatalog(Request $request, string $type, string $table, string $id, array $rules): JsonResponse
    {
        return $this->updateJobCatalogData($request, $type, $table, $id, $request->validate($rules));
    }

    private function updateJobCatalogData(Request $request, string $type, string $table, string $id, array $data): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        abort_unless(DB::table($table)->where('id', $id)->where('company_id', $companyId)->exists(), 404, 'The requested job catalogue record was not found in your legal entity.');

        return response()->json(['status' => 'success', 'data' => $this->jobPositionAdmin->updateCatalog($type, $id, $data, $companyId, (string) $request->user()->id)]);
    }

    private function catalogQuery(string $table, string $companyId, array $data): \Illuminate\Database\Query\Builder
    {
        $search = trim((string) ($data['search'] ?? ''));

        return DB::table($table)->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn($query, $status) => $query->where('status', $status))
            ->when($search !== '', fn($query) => $query->where(fn($match) => $match
                ->whereRaw('LOWER(code) LIKE ?', ['%' . mb_strtolower($search) . '%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%'])))
            ->when($data['effective_at'] ?? null, fn($query, $date) => $query->where('effective_from', '<=', $date)
                ->where(fn($active) => $active->whereNull('effective_until')->orWhere('effective_until', '>', $date)))
            ->orderBy('name')->orderBy('id');
    }

    private function catalogListRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'vacant'])],
            'effective_at' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    private function jobFamilyRules(bool $update = false): array
    {
        return $this->catalogCommandRules($update) + [
            'description' => ['nullable', 'string', 'max:4000'],
        ];
    }

    private function jobGradeRules(bool $update = false): array
    {
        return $this->catalogCommandRules($update) + [
            'rank' => ['required', 'integer', 'min:1', 'max:65535'],
            'minimum_salary_lkr' => ['nullable', 'regex:/^\d{1,16}(\.\d{1,4})?$/'],
            'maximum_salary_lkr' => ['nullable', 'regex:/^\d{1,16}(\.\d{1,4})?$/'],
        ];
    }

    private function designationRules(bool $update = false): array
    {
        return $this->catalogCommandRules($update) + [
            'job_family_id' => ['nullable', 'uuid', 'exists:hr_job_families,id'],
            'job_grade_id' => ['nullable', 'uuid', 'exists:hr_job_grades,id'],
            'description' => ['nullable', 'string', 'max:4000'],
        ];
    }

    private function catalogCommandRules(bool $update): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'status' => $update ? ['required', Rule::in(['active', 'inactive'])] : ['prohibited'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            ...($update ? ['expected_version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    private function positionRules(bool $update = false): array
    {
        return [
            'organization_unit_id' => ['required', 'uuid', 'exists:hr_organization_units,id'],
            'designation_id' => ['required', 'uuid', 'exists:hr_designations,id'],
            'position_number' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'headcount_limit' => ['required', 'integer', 'min:1', 'max:1000000'],
            'status' => $update ? ['required', Rule::in(['active', 'inactive'])] : ['prohibited'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'custom_fields' => ['prohibited'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            ...($update ? ['expected_version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    private function assertSalaryRange(array $data): void
    {
        if (($data['minimum_salary_lkr'] ?? null) === null || ($data['maximum_salary_lkr'] ?? null) === null) {
            return;
        }
        $minimum = $this->normalizeDecimal((string) $data['minimum_salary_lkr']);
        $maximum = $this->normalizeDecimal((string) $data['maximum_salary_lkr']);
        abort_if(strlen($minimum) > strlen($maximum) || (strlen($minimum) === strlen($maximum) && strcmp($minimum, $maximum) > 0), 422, 'Maximum salary must be greater than or equal to minimum salary.');
    }

    private function normalizeDecimal(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ltrim($whole, '0') . str_pad($fraction, 4, '0');
    }

    private function assertOptionalCompanyReference(?string $id, string $table, string $companyId, string $label): void
    {
        if ($id) {
            abort_unless(DB::table($table)->where('id', $id)->where('company_id', $companyId)->exists(), 422, "{$label} must belong to your legal entity.");
        }
    }

    private function organizationUnitRules(bool $update = false): array
    {
        return [
            'parent_id' => ['nullable', 'uuid', 'exists:hr_organization_units,id'],
            'unit_type' => ['required', Rule::in(['legal_entity', 'branch', 'location', 'cost_centre', 'business_unit', 'department', 'section', 'team', 'project'])],
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'manager_staff_id' => ['nullable', 'uuid', 'exists:staff,id'],
            'hr_partner_staff_id' => ['nullable', 'uuid', 'exists:staff,id'],
            'timezone' => ['required', 'timezone'],
            'status' => $update ? ['required', Rule::in(['active', 'inactive'])] : ['prohibited'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'custom_fields' => ['prohibited'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            ...($update ? ['expected_version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    private function customFieldRules(bool $update = false): array
    {
        return [
            'applies_to' => ['required', Rule::in(['staff', 'organization_unit', 'position', 'employment_spell'])],
            'field_key' => ['required', 'alpha_dash', 'max:80'],
            'label' => ['required', 'string', 'max:255'],
            'data_type' => ['required', Rule::in(['text', 'long_text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'select', 'multi_select', 'email', 'phone', 'url'])],
            'validation_rules' => ['nullable', 'array:options,min_length,max_length,minimum,maximum'],
            'validation_rules.options' => ['nullable', 'array', 'max:200'],
            'validation_rules.options.*' => ['string', 'max:255'],
            'validation_rules.min_length' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'validation_rules.max_length' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'validation_rules.minimum' => ['nullable', 'string', 'max:100', 'regex:/^-?(0|[1-9][0-9]*)(\.[0-9]{1,8})?$/'],
            'validation_rules.maximum' => ['nullable', 'string', 'max:100', 'regex:/^-?(0|[1-9][0-9]*)(\.[0-9]{1,8})?$/'],
            'confidentiality' => ['required', Rule::in(['employee', 'manager', 'internal', 'hr_private', 'legal'])],
            'required' => ['required', 'boolean'],
            'active' => [$update ? 'required' : 'nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            ...($update ? ['expected_version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    private function assertOrganizationStaffReferences(array $data, string $companyId): void
    {
        foreach (['manager_staff_id', 'hr_partner_staff_id'] as $field)
            if (!empty($data[$field]))
                abort_unless(Staff::query()->whereKey($data[$field])->where('company_id', $companyId)->exists(), 422, "{$field} must belong to the organization legal entity.");
    }

    private function assertCustomFieldRules(array $data): void
    {
        $options = $data['validation_rules']['options'] ?? [];
        $select = in_array($data['data_type'], ['select', 'multi_select'], true);
        abort_if($select && count($options) === 0, 422, 'Select custom fields require at least one option.');
        abort_if(!$select && count($options) > 0, 422, 'Only select custom fields may define options.');
        abort_if(count($options) !== count(array_unique($options)), 422, 'Custom-field options must be unique.');
        abort_if(count(array_filter($options, fn($option) => trim($option) === '' || trim($option) !== $option)) > 0, 422, 'Custom-field options must be non-empty and already trimmed.');
        $rules = $data['validation_rules'] ?? [];
        abort_if(isset($rules['min_length'], $rules['max_length']) && (int) $rules['min_length'] > (int) $rules['max_length'], 422, 'Custom-field minimum length cannot exceed maximum length.');
        abort_if((isset($rules['minimum']) || isset($rules['maximum'])) && !in_array($data['data_type'], ['integer', 'decimal'], true), 422, 'Numeric custom-field bounds require an integer or decimal data type.');
        abort_if($data['data_type'] === 'integer' && ((isset($rules['minimum']) && str_contains($rules['minimum'], '.')) || (isset($rules['maximum']) && str_contains($rules['maximum'], '.'))), 422, 'Integer custom-field bounds must be integers.');
        if (isset($rules['minimum'], $rules['maximum']))
            abort_if($this->compareExactDecimals($rules['minimum'], $rules['maximum']) > 0, 422, 'Custom-field minimum cannot exceed maximum.');
    }

    private function compareExactDecimals(string $left, string $right): int
    {
        $normalize = function (string $value): array {
            $negative = str_starts_with($value, '-');
            $unsigned = $negative ? substr($value, 1) : $value;
            [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
            $integer = ltrim($integer, '0') ?: '0';
            $fraction = rtrim($fraction, '0');
            if ($integer === '0' && $fraction === '')
                $negative = false;
            return [$negative, $integer, $fraction]; };
        [$ln, $li, $lf] = $normalize($left);
        [$rn, $ri, $rf] = $normalize($right);
        if ($ln !== $rn)
            return $ln ? -1 : 1;
        $m = strlen($li) <=> strlen($ri);
        if ($m === 0)
            $m = strcmp($li, $ri) <=> 0;
        if ($m === 0) {
            $length = max(strlen($lf), strlen($rf));
            $m = strcmp(str_pad($lf, $length, '0'), str_pad($rf, $length, '0')) <=> 0;
        }
        return $ln ? -$m : $m;
    }

    private function decodeJsonColumns(object $row, array $columns): array
    {
        $data = (array) $row;
        foreach ($columns as $column)
            if (isset($data[$column]) && is_string($data[$column]))
                $data[$column] = json_decode($data[$column], true, 512, JSON_THROW_ON_ERROR);
        return $data;
    }

    /**
     * Read-only required-document compliance for a single Staff, computed
     * against the governed hr_document_types register (§5.3). A type only
     * counts as required when at least one of required_for_staff_types /
     * required_for_employment_types is populated and the Staff's current
     * staff_type/employment_type matches every populated dimension — an
     * empty register entry is treated as informational only, never as an
     * implicit "required for everyone" default that no admin opted into.
     * Held evidence excludes rejected documents; an expired requires_expiry
     * document is reported separately from a genuinely missing one.
     */
    private function documentCompliance(Staff $staff): array
    {
        $currentSpell = $staff->employmentSpells->first(fn($spell) => $spell->status === 'active');
        $employmentTypeCode = $currentSpell?->employment_type_id
            ? DB::table('hr_employment_types')->where('id', $currentSpell->employment_type_id)->value('code')
            : null;
        $today = now()->toDateString();
        $types = DB::table('hr_document_types')->where('company_id', $staff->company_id)->where('status', 'active')
            ->where('effective_from', '<=', $today)
            ->where(fn($range) => $range->whereNull('effective_until')->orWhere('effective_until', '>=', $today))
            ->orderBy('category')->orderBy('name')->get();
        $held = Document::withTrashed()->whereIn('documentable_type', ['staff', Staff::class])
            ->where('documentable_id', $staff->id)->where('status', '!=', 'rejected')
            ->get(['document_type', 'expiry_date'])->groupBy('document_type');

        $rows = [];
        foreach ($types as $type) {
            $decoded = $this->decodeJsonColumns($type, ['required_for_staff_types', 'required_for_employment_types']);
            $forStaffTypes = $decoded['required_for_staff_types'] ?? null;
            $forEmploymentTypes = $decoded['required_for_employment_types'] ?? null;
            if (empty($forStaffTypes) && empty($forEmploymentTypes))
                continue;
            if (!empty($forStaffTypes) && !in_array($staff->staff_type, $forStaffTypes, true))
                continue;
            if (!empty($forEmploymentTypes) && (!$employmentTypeCode || !in_array($employmentTypeCode, $forEmploymentTypes, true)))
                continue;

            $matches = $held->get($type->code, collect());
            $current = $matches->first(fn($document) => !$type->requires_expiry || !$document->expiry_date || $document->expiry_date->toDateString() >= $today);
            $status = $current ? 'satisfied' : ($matches->isNotEmpty() ? 'expired' : 'missing');
            $expiryDate = $current?->expiry_date?->toDateString();
            // §5.3 renewal reminder: a satisfied, expiry-tracked document within its own
            // type's configured renewal_reminder_days window is reported as expiring_soon
            // rather than satisfied. Read-only computed status only — no notification is sent;
            // an unconfigured renewal_reminder_days never implies a default reminder window.
            if ($status === 'satisfied' && $type->requires_expiry && $type->renewal_reminder_days && $expiryDate && $expiryDate <= now()->addDays((int) $type->renewal_reminder_days)->toDateString())
                $status = 'expiring_soon';
            $rows[] = ['document_type_id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'category' => $type->category, 'requires_expiry' => (bool) $type->requires_expiry, 'status' => $status, 'expiry_date' => $expiryDate];
        }

        return $rows;
    }

    private function employeeSummary(Staff $staff): array
    {
        $currentAssignment = $staff->employmentAssignments->first(fn($assignment) => $assignment->effective_from->lte(now()) && (!$assignment->effective_until || $assignment->effective_until->gt(now())));
        $currentSpell = $staff->employmentSpells->first(fn($spell) => $spell->status === 'active');
        $categories = collect($this->policySettings->approvedStaffCategories((string) $staff->company_id))->map(fn($category) => mb_strtolower(trim((string) $category)))->filter();
        $issues = collect([
            'employee_number' => $staff->code ? null : 'Employee number is missing.',
            'user_identity' => $staff->user_id ? null : 'Staff-to-User identity is missing.',
            'active_spell' => $currentSpell ? null : 'Active employment spell is missing.',
            'active_assignment' => $currentAssignment ? null : 'Current assignment is missing.',
        ])->filter()->values()->all();

        return [
            'id' => $staff->id,
            'employee_number' => $staff->code,
            'name' => trim(($staff->user?->first_name ?? '') . ' ' . ($staff->user?->last_name ?? '')),
            'email' => $staff->user?->email,
            'phone' => $staff->user?->phone,
            'company_id' => $staff->company_id,
            'company_name' => $staff->company?->name,
            'staff_category' => $staff->staff_type,
            'employment_status' => $currentSpell?->status ?? ($staff->employment_ended_at || $staff->trashed() ? 'former' : 'incomplete'),
            'employment_ended_at' => $staff->employment_ended_at,
            'current_spell_id' => $currentSpell?->id,
            'current_assignment' => $currentAssignment?->only(['id', 'position_id', 'organization_unit_id', 'manager_staff_id', 'dotted_line_manager_staff_id', 'location_code', 'cost_centre_code', 'payroll_group_code', 'default_shift_code', 'work_pattern_code', 'effective_from', 'effective_until']),
            'profile_complete' => $issues === [],
            'profile_issues' => $issues,
            'sales_category_configured' => $categories->isNotEmpty(),
            'is_sales_category' => $categories->contains(mb_strtolower(trim((string) $staff->staff_type))),
            'sales_profile_note' => 'Sales Profile enrollment and eligibility are managed separately in the Sales bounded context.',
        ];
    }
}
