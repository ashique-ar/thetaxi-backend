<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\Hr\Leave\LeaveWorkflowService;
use App\Services\Hr\Workforce\WorkforceWorkflowService;
use App\Services\StaffAccessService;
use App\Services\Hr\Ess\HrDomainRequestProjectionService;
use App\Services\SingleCompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WorkforceController extends Controller
{
    public function companyOptions(Request $r): JsonResponse
    {
        $data = $r->validate(['search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid']]);
        $companyIds = $this->authorizedCompanyIds($r);
        $defaultCompanyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if (! $companyIds->contains($defaultCompanyId)) $defaultCompanyId = null;
        $companies = DB::table('companies')->whereIn('id', $companyIds)
            ->when($data['selected_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->when(! empty($data['search']), fn ($q) => $q->whereLikeInsensitive('name', trim($data['search'])))
            ->orderByDesc('is_default')->orderBy('name')->limit(25)->get(['id', 'name', 'is_default']);
        return response()->json(['status' => 'success', 'default_company_id' => $defaultCompanyId, 'data' => $companies->map(fn ($company) => [
            'value' => (string) $company->id, 'label' => $company->name,
            'is_default' => (bool) $company->is_default, 'status' => 'active',
        ])->values()]);
    }

    public function references(Request $r): JsonResponse
    {
        if ($r->filled('company_id')) {
            $companyId = $this->company($r, $r->input('company_id'));
        } elseif (! $r->user()->can('staff.view-all')) {
            $companyId = $this->company($r, null);
        } else {
            $allowed = $this->authorizedCompanyIds($r);
            abort_unless($allowed->isNotEmpty(), 403, 'The authenticated user has no active Staff legal-entity context.');
            $companyId = DB::table('companies')->whereIn('id', $allowed)
                ->where('is_active', true)->where('is_default', true)->whereNull('deleted_at')->value('id');
            abort_unless($companyId, 422, 'Select an authorized legal entity.');
        }
        return response()->json(['status' => 'success', 'data' => [
            'company_id' => $companyId,
        ]]);
    }

    public function leaveTypeOptions(Request $r): JsonResponse
    {
        $d = $r->validate([
            'company_id' => ['nullable', 'uuid'], 'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if (empty($d['company_id'])) return response()->json(['status' => 'success', 'data' => []]);
        $companyId = $this->company($r, $d['company_id']);
        $q = DB::table('hr_leave_types')->where('company_id', $companyId)
            ->when($d['status'] ?? null, fn ($types, $status) => $types->where('status', $status));
        if (isset($d['selected_id'])) $q->where('id', $d['selected_id']);
        elseif (!empty($d['search'])) {
            $term = '%'.strtolower(addcslashes(trim($d['search']), '%_\\')).'%';
            $q->where(fn ($types) => $types->whereRaw('LOWER(code) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term]));
        }
        $q->select(['id', 'code', 'name', 'category', 'unit', 'paid', 'status'])->orderBy('name')->orderBy('code')->orderBy('id');
        $map = fn ($type) => [
            'value' => (string) $type->id,
            'label' => $type->name.' - '.$type->code,
            'metadata' => ['category' => $type->category, 'unit' => $type->unit, 'paid' => (string) $type->paid],
            'status' => $type->status,
        ];
        if (isset($d['selected_id'])) return response()->json(['status' => 'success', 'data' => $q->limit(1)->get()->map($map)->values()]);
        $rows = $q->paginate($d['per_page'] ?? 25);
        $rows->getCollection()->transform($map);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function leavePolicyOptions(Request $r, StaffAccessService $access): JsonResponse
    {
        $d = $r->validate([
            'company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid'],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if (empty($d['company_id']) || empty($d['staff_id']) || empty($d['start_date']) || empty($d['end_date']) || $d['end_date'] < $d['start_date']) {
            return response()->json(['status' => 'success', 'data' => []]);
        }
        $companyId = $this->company($r, $d['company_id']);
        $staff = Staff::query()->whereKey($d['staff_id'])->where('company_id', $companyId)->whereNull('employment_ended_at')->firstOrFail();
        $access->authorize($r->user(), $staff, 'view');

        $q = DB::table('hr_leave_policies as policy')
            ->join('hr_leave_policy_assignments as assignment', function ($join) use ($d, $companyId) {
                $join->on('assignment.policy_id', '=', 'policy.id')
                    ->where('assignment.company_id', $companyId)->where('assignment.staff_id', $d['staff_id'])
                    ->whereNotNull('assignment.approved_at')->whereDate('assignment.effective_from', '<=', $d['start_date'])
                    ->where(fn ($dates) => $dates->whereNull('assignment.effective_until')->orWhereDate('assignment.effective_until', '>', $d['end_date']));
            })
            ->join('hr_leave_types as type', function ($join) use ($companyId) {
                $join->on('type.id', '=', 'policy.leave_type_id')->where('type.company_id', $companyId);
            })
            ->where('policy.company_id', $companyId)->where('policy.status', 'approved')
            ->whereDate('policy.effective_from', '<=', $d['start_date'])
            ->where(fn ($dates) => $dates->whereNull('policy.effective_until')->orWhereDate('policy.effective_until', '>', $d['end_date']));
        if (isset($d['selected_id'])) $q->where('policy.id', $d['selected_id']);
        elseif (!empty($d['search'])) {
            $term = '%'.mb_strtolower(trim($d['search'])).'%';
            $q->where(fn ($match) => $match->whereRaw('LOWER(policy.code) LIKE ?', [$term])
                ->orWhereRaw('LOWER(type.name) LIKE ?', [$term])->orWhereRaw('LOWER(type.code) LIKE ?', [$term]));
        }
        $q->select(['policy.id', 'policy.code', 'policy.version', 'type.code as type_code', 'type.name as type_name', 'type.unit'])
            ->distinct()->orderBy('type.name')->orderBy('policy.code')->orderByDesc('policy.version')->orderBy('policy.id');
        $map = fn ($policy) => [
            'value' => (string) $policy->id,
            'label' => $policy->type_name.' · '.$policy->code.' v'.$policy->version,
            'metadata' => ['leave_type_code' => $policy->type_code, 'unit' => $policy->unit],
            'status' => 'active',
        ];
        if (isset($d['selected_id'])) return response()->json(['status' => 'success', 'data' => $q->limit(1)->get()->map($map)->values()]);
        $rows = $q->paginate($d['per_page'] ?? 25);
        $rows->getCollection()->transform($map);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function leaveAssignmentPolicyOptions(Request $r): JsonResponse
    {
        $d = $r->validate([
            'company_id' => ['nullable', 'uuid'], 'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if (empty($d['company_id']) || empty($d['effective_from'])) return response()->json(['status' => 'success', 'data' => []]);
        $companyId = $this->company($r, $d['company_id']);
        $q = DB::table('hr_leave_policies as policy')->join('hr_leave_types as type', function ($join) use ($companyId) {
            $join->on('type.id', '=', 'policy.leave_type_id')->where('type.company_id', $companyId);
        })
            ->where('policy.company_id', $companyId)->where('policy.status', 'approved')->whereDate('policy.effective_from', '<=', $d['effective_from'])
            ->when(isset($d['effective_until']), fn ($policies) => $policies->where(fn ($dates) => $dates->whereNull('policy.effective_until')->orWhereDate('policy.effective_until', '>=', $d['effective_until'])),
                fn ($policies) => $policies->whereNull('policy.effective_until'));
        if (isset($d['selected_id'])) $q->where('policy.id', $d['selected_id']);
        elseif (!empty($d['search'])) {
            $term = '%'.strtolower(addcslashes(trim($d['search']), '%_\\')).'%';
            $q->where(fn ($match) => $match->whereRaw('LOWER(policy.code) LIKE ?', [$term])->orWhereRaw('LOWER(type.name) LIKE ?', [$term])->orWhereRaw('LOWER(type.code) LIKE ?', [$term]));
        }
        $q->select(['policy.id', 'policy.code', 'policy.version', 'type.code as type_code', 'type.name as type_name'])
            ->orderBy('type.name')->orderBy('policy.code')->orderByDesc('policy.version')->orderBy('policy.id');
        $map = fn ($policy) => ['value' => (string) $policy->id, 'label' => $policy->type_name.' · '.$policy->code.' v'.$policy->version, 'metadata' => ['leave_type_code' => $policy->type_code], 'status' => 'approved'];
        if (isset($d['selected_id'])) return response()->json(['status' => 'success', 'data' => $q->limit(1)->get()->map($map)->values()]);
        $rows = $q->paginate($d['per_page'] ?? 25);
        $rows->getCollection()->transform($map);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function workRequestPolicyOptions(Request $r, StaffAccessService $access): JsonResponse
    {
        $d = $r->validate([
            'company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid'],
            'request_kind' => ['nullable', Rule::in(['overtime', 'field_duty', 'remote_work', 'travel', 'standby', 'callout', 'on_call'])],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if (empty($d['company_id']) || empty($d['staff_id']) || empty($d['request_kind']) || empty($d['starts_at']) || empty($d['ends_at'])) {
            return response()->json(['status' => 'success', 'data' => []]);
        }
        $starts = CarbonImmutable::parse($d['starts_at']);
        $ends = CarbonImmutable::parse($d['ends_at']);
        if (!$ends->gt($starts)) return response()->json(['status' => 'success', 'data' => []]);
        $companyId = $this->company($r, $d['company_id']);
        $staff = Staff::query()->whereKey($d['staff_id'])->where('company_id', $companyId)->whereNull('employment_ended_at')->firstOrFail();
        $access->authorize($r->user(), $staff, 'view');

        $start = $starts->toDateString();
        $end = $ends->toDateString();
        $q = DB::table('hr_work_request_policies')->where('company_id', $companyId)
            ->where('request_kind', $d['request_kind'])->where('status', 'approved')
            ->whereDate('effective_from', '<=', $start)
            ->where(fn ($dates) => $dates->whereNull('effective_until')->orWhereDate('effective_until', '>', $end));
        if (isset($d['selected_id'])) $q->where('id', $d['selected_id']);
        elseif (!empty($d['search'])) $q->whereRaw('LOWER(code) LIKE ?', ['%'.strtolower(addcslashes(trim($d['search']), '%_\\')).'%']);
        $q->select(['id', 'code', 'version', 'request_kind', 'effective_from', 'effective_until'])
            ->orderBy('code')->orderByDesc('version')->orderBy('id');
        $map = fn ($policy) => [
            'value' => (string) $policy->id,
            'label' => $policy->code.' v'.$policy->version.' - '.$policy->request_kind,
            'metadata' => ['request_kind' => $policy->request_kind, 'effective_from' => $policy->effective_from, 'effective_until' => $policy->effective_until],
            'status' => 'active',
        ];
        if (isset($d['selected_id'])) return response()->json(['status' => 'success', 'data' => $q->limit(1)->get()->map($map)->values()]);
        $rows = $q->paginate($d['per_page'] ?? 25);
        $rows->getCollection()->transform($map);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function staffOptions(Request $r, StaffAccessService $access): JsonResponse
    {
        $data = $r->validate([
            'company_id' => ['required', 'uuid'], 'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyId = $this->company($r, $data['company_id']);
        abort_unless(DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
        $query = $access->scope(Staff::query()->with('user:id,first_name,last_name,email')
            ->where('company_id', $companyId)->whereNull('employment_ended_at'), $r->user());
        if (! empty($data['selected_id'])) {
            $query->whereKey($data['selected_id']);
        } elseif (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($staff) => $staff->where('code', 'like', $term)
                ->orWhereHas('user', fn ($user) => $user->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term)));
        }
        $rows = $query->orderBy('code')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(function (Staff $staff) {
            $name = trim(($staff->user?->first_name ?? '').' '.($staff->user?->last_name ?? ''));
            return [
                'value' => (string) $staff->id,
                'label' => $name !== '' ? $name.' · '.$staff->code : $staff->code,
                'metadata' => ['code' => $staff->code, 'email' => $staff->user?->email],
                'status' => 'active',
            ];
        });
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function leaveRequests(Request $r, StaffAccessService $access): JsonResponse
    {
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $q = DB::table('hr_leave_requests as request')->join('hr_leave_types as type', 'type.id', '=', 'request.leave_type_id')
            ->whereIn('request.staff_id', $staff)
            ->whereExists(fn ($scope) => $scope->selectRaw('1')->from('staff')->whereColumn('staff.id', 'request.staff_id')->whereColumn('staff.company_id', 'request.company_id'))
            ->whereColumn('type.company_id', 'request.company_id')
            ->whereExists(fn ($policy) => $policy->selectRaw('1')->from('hr_leave_policies')
                ->whereColumn('hr_leave_policies.id', 'request.policy_id')->whereColumn('hr_leave_policies.company_id', 'request.company_id')
                ->whereColumn('hr_leave_policies.leave_type_id', 'request.leave_type_id'))
            ->select(['request.id', 'type.name as leave_type_name', 'request.start_date', 'request.end_date', 'request.requested_minutes', 'request.status', 'request.requested_by', 'request.actual_return_date', 'request.recalled_at'])
            ->when($r->status, fn ($query, $status) => $query->where('request.status', $status))->latest('request.created_at');
        $rows = $q->paginate($r->integer('per_page', 50));
        $rows->getCollection()->transform(fn ($row) => [
            'id' => $row->id,
            'leave_type_name' => $row->leave_type_name,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'requested_minutes' => $row->requested_minutes,
            'status' => $row->status,
            'is_mine' => $row->requested_by === $r->user()->id,
            'actual_return_date' => $row->actual_return_date,
            'recalled_at' => $row->recalled_at,
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function leaveBalances(Request $r, StaffAccessService $access, LeaveWorkflowService $service): JsonResponse
    {
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $asOf = $r->date('as_of')?->toDateString() ?? now()->toDateString();
        $rows = DB::table('hr_leave_balance_accounts as account')->join('hr_leave_types as type', 'type.id', '=', 'account.leave_type_id')
            ->whereIn('account.staff_id', $staff)
            ->whereExists(fn ($scope) => $scope->selectRaw('1')->from('staff')->whereColumn('staff.id', 'account.staff_id')->whereColumn('staff.company_id', 'account.company_id'))
            ->whereColumn('type.company_id', 'account.company_id')
            ->select(['account.id', 'account.staff_id', 'type.code', 'type.name', 'account.unit'])->get()
            ->map(fn ($row) => (array) $row + ['balance_minutes' => $service->balance($row->id, $asOf), 'as_of' => $asOf]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function teamCalendar(Request $r, StaffAccessService $access): JsonResponse
    {
        $d = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $rows = DB::table('hr_leave_requests as request')->join('hr_leave_types as type', 'type.id', '=', 'request.leave_type_id')
            ->whereIn('request.staff_id', $staff)->where('request.status', 'approved')
            ->whereExists(fn ($scope) => $scope->selectRaw('1')->from('staff')->whereColumn('staff.id', 'request.staff_id')->whereColumn('staff.company_id', 'request.company_id'))
            ->whereColumn('type.company_id', 'request.company_id')
            ->whereExists(fn ($policy) => $policy->selectRaw('1')->from('hr_leave_policies')
                ->whereColumn('hr_leave_policies.id', 'request.policy_id')->whereColumn('hr_leave_policies.company_id', 'request.company_id')
                ->whereColumn('hr_leave_policies.leave_type_id', 'request.leave_type_id'))
            ->whereDate('request.start_date', '<=', $d['to'])->whereDate('request.end_date', '>=', $d['from'])
            ->select(['request.id', 'request.staff_id', 'request.start_date', 'request.end_date', 'type.name as absence_type', 'type.medical_confidential'])
            ->get()->map(fn ($row) => ['id' => $row->id, 'staff_id' => $row->staff_id, 'start_date' => $row->start_date, 'end_date' => $row->end_date, 'absence_type' => $row->medical_confidential ? 'Unavailable' : $row->absence_type]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function submitLeave(Request $r, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['company_id' => ['required', 'uuid'], 'staff_id' => ['required', 'uuid'], 'policy_id' => ['required', 'uuid'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'unit' => ['required', Rule::in(['day', 'half_day', 'hour'])], 'requested_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'reason' => ['required', 'string', 'max:2000'], 'coverage_snapshot' => ['nullable', 'array'], 'private_evidence' => ['nullable', 'array'], 'approver_staff_id' => ['nullable', 'uuid'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $staff = Staff::query()->findOrFail($d['staff_id']);
        $access->authorize($r->user(), $staff, 'view');
        abort_unless($staff->company_id === $d['company_id'], 422, 'Staff and leave legal entities must match.');
        abort_if($d['unit'] === 'hour' && !isset($d['requested_minutes']), 422, 'Hourly leave requires requested minutes.');
        $row = $service->submit($d, $r->user()->id);
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row], 201);
    }
    public function decideLeave(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['action' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required', 'string', 'max:2000']]);
        $this->leaveRequestForCompany($r, $id);
        $actorStaff = $access->currentActorStaff($r->user());
        $row = $service->decide($id, $d['action'], $d['reason'], $r->user()->id, $actorStaff->id, $r->user()->can('hr.leave.approve.override'));
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function cancelLeave(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['reason' => ['required', 'string', 'max:2000']]);
        $row = $this->leaveRequestForStaff($r, $id, $access);
        $access->authorize($r->user(), Staff::query()->findOrFail($row->staff_id), 'view');
        abort_unless($row->requested_by === $r->user()->id || $r->user()->can('hr.leave.approve'), 403, 'Only the requester or an authorized leave approver may cancel this request.');
        $row = $service->cancel($id, $d['reason'], $r->user()->id);
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function confirmLeaveReturn(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['actual_return_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $row = $this->leaveRequestForStaff($r, $id, $access);
        $access->authorize($r->user(), Staff::query()->findOrFail($row->staff_id), 'view');
        abort_unless($row->requested_by === $r->user()->id || $r->user()->can('hr.leave.approve'), 403, 'Only the requester or an authorized leave approver may confirm a return to work.');
        $row = $service->confirmReturn($id, $d['actual_return_date'], $d['notes'] ?? null, $r->user()->id);
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function extendLeave(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['end_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        $row = $this->leaveRequestForStaff($r, $id, $access);
        $access->authorize($r->user(), Staff::query()->findOrFail($row->staff_id), 'view');
        abort_unless($row->requested_by === $r->user()->id || $r->user()->can('hr.leave.approve'), 403, 'Only the requester or an authorized leave approver may extend this request.');
        $row = $service->extend($id, $d['end_date'], $d['reason'], $r->user()->id);
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function recallLeave(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['recall_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        $row = $this->leaveRequestForStaff($r, $id, $access);
        $access->authorize($r->user(), Staff::query()->findOrFail($row->staff_id), 'view');
        $row = $service->recall($id, $d['recall_date'], $d['reason'], $r->user()->id);
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function postBalance(Request $r, string $accountId, LeaveWorkflowService $service): JsonResponse
    {
        $d = $r->validate(['entry_type' => ['required', Rule::in(['opening', 'accrual', 'adjustment', 'carry_forward', 'expiry', 'encashment'])], 'minutes' => ['required', 'integer', 'not_in:0'], 'effective_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $account = DB::table('hr_leave_balance_accounts')->find($accountId);
        abort_unless($account, 404);
        $this->company($r, $account->company_id);
        return response()->json(['status' => 'success', 'data' => $service->postBalance($accountId, $d['entry_type'], $d['minutes'], $d['effective_date'], $d['reason'], $r->user()->id, $d['idempotency_key'])], 201);
    }

    /**
     * §5.9 leave configuration: storeLeaveType/storeLeavePolicy/assignLeavePolicy and their
     * approve actions were POST-only with no route anywhere to list types, list policies
     * (so a pending policy could never be found to approve it), or list policy assignments —
     * the exact "create exists, no read-back" gap already closed for Attendance calendars/
     * shifts/policies/rosters. These three read endpoints close it for Leave configuration.
     */
    public function leaveTypes(Request $r): JsonResponse
    {
        $companyId = $this->company($r, $r->input('company_id'));
        $q = DB::table('hr_leave_types')->where('company_id', $companyId)->when($r->status, fn($b, $v) => $b->where('status', $v))->orderBy('name');
        return response()->json(['status' => 'success', 'data' => $q->paginate($r->integer('per_page', 50))]);
    }
    public function leavePolicies(Request $r): JsonResponse
    {
        $companyId = $this->company($r, $r->input('company_id'));
        $q = DB::table('hr_leave_policies as policy')->join('hr_leave_types as type', 'type.id', '=', 'policy.leave_type_id')->where('policy.company_id', $companyId)
            ->select(['policy.id', 'policy.leave_type_id', 'type.code as leave_type_code', 'type.name as leave_type_name', 'policy.code', 'policy.version', 'policy.rules', 'policy.status', 'policy.effective_from', 'policy.effective_until', 'policy.created_by', 'policy.approved_by', 'policy.approved_at'])
            ->when($r->status, fn($b, $v) => $b->where('policy.status', $v))->latest('policy.created_at');
        $rows = $q->paginate($r->integer('per_page', 50));
        $rows->getCollection()->transform(fn($row) => (array) $row + ['rules' => json_decode($row->rules, true, 512, JSON_THROW_ON_ERROR)]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function leavePolicyAssignments(Request $r, StaffAccessService $access): JsonResponse
    {
        $companyId = $this->company($r, $r->input('company_id'));
        $staffIds = $access->scope(Staff::query(), $r->user())->where('company_id', $companyId)->select('id');
        $q = DB::table('hr_leave_policy_assignments as assignment')->join('hr_leave_policies as policy', 'policy.id', '=', 'assignment.policy_id')
            ->leftJoin('staff', 'staff.id', '=', 'assignment.staff_id')->leftJoin('users', 'users.id', '=', 'staff.user_id')
            ->where('assignment.company_id', $companyId)->whereIn('assignment.staff_id', $staffIds)
            ->select(['assignment.id', 'assignment.staff_id', 'assignment.policy_id', 'policy.code as policy_code', 'assignment.effective_from', 'assignment.effective_until', 'assignment.reason', 'assignment.created_by', 'assignment.approved_by', 'assignment.approved_at', 'staff.code as staff_code', 'users.first_name as staff_first_name', 'users.last_name as staff_last_name'])
            ->when($r->staff_id, fn($b, $v) => $b->where('assignment.staff_id', $v))->latest('assignment.created_at');
        return response()->json(['status' => 'success', 'data' => $q->paginate($r->integer('per_page', 50))]);
    }

    public function storeLeaveType(Request $r): JsonResponse
    {
        $this->enabled();
        $d = $r->validate(['company_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:255'], 'category' => ['required', Rule::in(['annual', 'sick', 'maternity', 'paternity', 'parental', 'no_pay', 'compassionate', 'study', 'lieu', 'duty', 'custom'])], 'unit' => ['required', Rule::in(['day', 'half_day', 'hour'])], 'paid' => ['required', 'boolean'], 'medical_confidential' => ['required', 'boolean'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']]);
        $this->company($r, $d['company_id']);
        $id = (string) Str::uuid();
        DB::table('hr_leave_types')->insert($d + ['id' => $id, 'status' => 'active', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_leave_types')->find($id)], 201);
    }
    public function storeLeavePolicy(Request $r): JsonResponse
    {
        $this->enabled();
        $d = $r->validate(['company_id' => ['required', 'uuid'], 'leave_type_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:80'], 'version' => ['required', 'integer', 'min:1'], 'rules' => ['required', 'array'], 'rules.minutes_per_day' => ['required', 'integer', 'min:1', 'max:1440'], 'rules.minimum_notice_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'rules.negative_balance_limit_minutes' => ['nullable', 'integer', 'min:0'], 'rules.weekend_days' => ['nullable', 'array'], 'rules.sandwich_rule_enabled' => ['nullable', 'boolean'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']]);
        $this->company($r, $d['company_id']);
        abort_unless(DB::table('hr_leave_types')->where('id', $d['leave_type_id'])->where('company_id', $d['company_id'])->exists(), 422, 'Leave type and policy legal entities must match.');
        $id = (string) Str::uuid();
        $d['rules'] = json_encode($d['rules'], JSON_THROW_ON_ERROR);
        DB::table('hr_leave_policies')->insert($d + ['id' => $id, 'status' => 'pending_approval', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_leave_policies')->find($id)], 201);
    }
    public function approveLeavePolicy(Request $r, string $id): JsonResponse
    {
        return $this->approveConfig($r, 'hr_leave_policies', $id);
    }
    public function assignLeavePolicy(Request $r): JsonResponse
    {
        $this->enabled();
        $d = $r->validate(['company_id' => ['required', 'uuid'], 'staff_id' => ['required', 'uuid'], 'policy_id' => ['required', 'uuid'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:500']]);
        $this->company($r, $d['company_id']);
        return DB::transaction(function () use ($r, $d) {
            DB::table('companies')->where('id', $d['company_id'])->lockForUpdate()->first();
            abort_unless(DB::table('staff')->where('id', $d['staff_id'])->where('company_id', $d['company_id'])->whereNull('employment_ended_at')->exists(), 422, 'Staff must be active in the selected legal entity.');
            $policy = DB::table('hr_leave_policies')->where('id', $d['policy_id'])->where('company_id', $d['company_id'])->where('status', 'approved')
                ->whereDate('effective_from', '<=', $d['effective_from'])
                ->where(fn ($dates) => isset($d['effective_until']) ? $dates->whereNull('effective_until')->orWhereDate('effective_until', '>=', $d['effective_until']) : $dates->whereNull('effective_until'))->first();
            abort_unless($policy, 422, 'An approved policy must cover the full assignment period.');
            $leaveType = $policy->leave_type_id;
            $sameTypePolicies = DB::table('hr_leave_policies')->where('company_id', $d['company_id'])->where('leave_type_id', $leaveType)->select('id');
            $overlap = DB::table('hr_leave_policy_assignments')->where('company_id', $d['company_id'])->where('staff_id', $d['staff_id'])->whereIn('policy_id', $sameTypePolicies)->whereDate('effective_from', '<', $d['effective_until'] ?? '9999-12-31')->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $d['effective_from']))->exists();
            abort_if($overlap, 409, 'An overlapping policy assignment exists for this leave type.');
            $id = (string) Str::uuid();
            DB::table('hr_leave_policy_assignments')->insert($d + ['id' => $id, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_leave_policy_assignments')->find($id)], 201); });
    }
    public function approveLeaveAssignment(Request $r, string $id): JsonResponse
    {
        return $this->approveConfig($r, 'hr_leave_policy_assignments', $id, function ($row) {
            $covered = DB::table('hr_leave_policies')->where('id', $row->policy_id)->where('company_id', $row->company_id)->where('status', 'approved')
                ->whereDate('effective_from', '<=', $row->effective_from)
                ->where(fn ($dates) => $row->effective_until ? $dates->whereNull('effective_until')->orWhereDate('effective_until', '>=', $row->effective_until) : $dates->whereNull('effective_until'))->exists();
            abort_unless($covered, 409, 'The approved policy must cover the full assignment period.');
        });
    }

    public function workRequests(Request $r, StaffAccessService $access): JsonResponse
    {
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $q = DB::table('hr_work_requests')->whereIn('staff_id', $staff)
            ->whereExists(fn ($scope) => $scope->selectRaw('1')->from('staff')->whereColumn('staff.id', 'hr_work_requests.staff_id')->whereColumn('staff.company_id', 'hr_work_requests.company_id'))
            ->whereExists(fn ($policy) => $policy->selectRaw('1')->from('hr_work_request_policies')
                ->whereColumn('hr_work_request_policies.id', 'hr_work_requests.policy_id')->whereColumn('hr_work_request_policies.company_id', 'hr_work_requests.company_id')
                ->whereColumn('hr_work_request_policies.request_kind', 'hr_work_requests.request_kind'))
            ->select(['id', 'request_kind', 'starts_at', 'ends_at', 'requested_minutes', 'settlement_kind', 'status', 'requested_by'])
            ->when($r->request_kind, fn ($query, $kind) => $query->where('request_kind', $kind))
            ->when($r->status, fn ($query, $status) => $query->where('status', $status))->latest('starts_at');
        $rows = $q->paginate($r->integer('per_page', 50));
        $rows->getCollection()->transform(fn ($row) => [
            'id' => $row->id,
            'request_kind' => $row->request_kind,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'requested_minutes' => $row->requested_minutes,
            'settlement_kind' => $row->settlement_kind,
            'status' => $row->status,
            'is_mine' => $row->requested_by === $r->user()->id,
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function submitWork(Request $r, WorkforceWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['company_id' => ['required', 'uuid'], 'staff_id' => ['required', 'uuid'], 'policy_id' => ['required', 'uuid'], 'request_kind' => ['required', Rule::in(['overtime', 'field_duty', 'remote_work', 'travel', 'standby', 'callout', 'on_call'])], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'rate_category' => ['nullable', 'string', 'max:60'], 'settlement_kind' => ['nullable', Rule::in(['pay', 'time_off', 'informational'])], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $staff = Staff::query()->findOrFail($d['staff_id']);
        $access->authorize($r->user(), $staff, 'view');
        abort_unless($staff->company_id === $d['company_id'], 422, 'Staff and request legal entities must match.');
        $row = $service->submitWorkRequest($d, $r->user()->id);
        $projection->work($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row], 201);
    }
    public function decideWork(Request $r, string $id, WorkforceWorkflowService $service, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['action' => ['required', Rule::in(['approve', 'reject'])], 'decision_note' => ['required', 'string', 'max:2000']]);
        $this->workRequestForCompany($r, $id);
        $row = $service->decideWorkRequest($id, $d['action'], $d['decision_note'], $r->user()->id);
        $projection->work($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    /**
     * §5.8/§5.9-adjacent "other domains" administration gap named in the
     * QH3-01 status: `storeWorkPolicy()`/`approveWorkPolicy()` existed with
     * no route to list a policy, so a pending policy could never be found to
     * approve and `submitWork()`'s required `policy_id` had no discovery
     * path — the same create-but-no-read shape already closed for leave
     * types/policies/assignments.
     */
    public function workRequestPolicies(Request $r): JsonResponse
    {
        $companyId = $this->company($r, $r->input('company_id'));
        $q = DB::table('hr_work_request_policies')->where('company_id', $companyId)
            ->select(['id', 'company_id', 'request_kind', 'code', 'version', 'rules', 'status', 'effective_from', 'effective_until', 'created_by', 'approved_by', 'approved_at'])
            ->when($r->request_kind, fn($b, $v) => $b->where('request_kind', $v))->when($r->status, fn($b, $v) => $b->where('status', $v))->latest('created_at');
        $rows = $q->paginate($r->integer('per_page', 50));
        $rows->getCollection()->transform(fn($row) => (array) $row + ['rules' => json_decode($row->rules, true, 512, JSON_THROW_ON_ERROR)]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function storeWorkPolicy(Request $r): JsonResponse
    {
        $this->enabled();
        $d = $r->validate(['company_id' => ['required', 'uuid'], 'request_kind' => ['required', Rule::in(['overtime', 'field_duty', 'remote_work', 'travel', 'standby', 'callout', 'on_call'])], 'code' => ['required', 'string', 'max:80'], 'version' => ['required', 'integer', 'min:1'], 'rules' => ['required', 'array'], 'rules.maximum_request_minutes' => ['nullable', 'integer', 'min:1'], 'rules.default_rate_category' => ['nullable', 'string', 'max:60'], 'rules.default_settlement_kind' => ['nullable', Rule::in(['pay', 'time_off', 'informational'])], 'rules.time_off_leave_type_id' => ['nullable', 'uuid'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']]);
        $this->company($r, $d['company_id']);
        if (!empty($d['rules']['time_off_leave_type_id']))
            abort_unless(DB::table('hr_leave_types')->where('id', $d['rules']['time_off_leave_type_id'])->where('company_id', $d['company_id'])->exists(), 422, 'Time-off leave type must belong to the same legal entity.');
        $id = (string) Str::uuid();
        $d['rules'] = json_encode($d['rules'], JSON_THROW_ON_ERROR);
        DB::table('hr_work_request_policies')->insert($d + ['id' => $id, 'status' => 'pending_approval', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_work_request_policies')->find($id)], 201);
    }
    public function approveWorkPolicy(Request $r, string $id): JsonResponse
    {
        return $this->approveConfig($r, 'hr_work_request_policies', $id);
    }

    public function timesheets(Request $r, StaffAccessService $access): JsonResponse
    {
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $rows = DB::table('hr_timesheets as timesheet')->whereIn('timesheet.staff_id', $staff)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('staff')
                ->whereColumn('staff.id', 'timesheet.staff_id')
                ->whereColumn('staff.company_id', 'timesheet.company_id'))
            ->select(['timesheet.id', 'timesheet.submitted_by', 'timesheet.period_start', 'timesheet.period_end', 'timesheet.status', 'timesheet.version', 'timesheet.submitted_at'])
            ->latest('timesheet.period_start')->paginate($r->integer('per_page', 50));
        $rows->getCollection()->transform(fn ($row) => [
            'id' => $row->id,
            'period_start' => $row->period_start,
            'period_end' => $row->period_end,
            'status' => $row->status,
            'version' => $row->version,
            'submitted_at' => $row->submitted_at,
            'can_decide' => $row->submitted_by !== $r->user()->id,
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function saveTimesheet(Request $r, WorkforceWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['company_id' => ['required', 'uuid'], 'staff_id' => ['required', 'uuid'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'entries' => ['required', 'array', 'min:1'], 'entries.*.work_date' => ['required', 'date'], 'entries.*.started_at' => ['nullable', 'date'], 'entries.*.ended_at' => ['nullable', 'date'], 'entries.*.minutes' => ['nullable', 'integer', 'min:1'], 'entries.*.entry_mode' => ['required', Rule::in(['manual', 'timer'])], 'entries.*.cost_centre_code' => ['nullable', 'string', 'max:80'], 'entries.*.project_code' => ['nullable', 'string', 'max:80'], 'entries.*.booking_id' => ['nullable', 'uuid', 'exists:bookings,id'], 'entries.*.job_reference' => ['nullable', 'string', 'max:120'], 'entries.*.activity_code' => ['required', 'string', 'max:80'], 'entries.*.billable' => ['required', 'boolean'], 'entries.*.notes' => ['nullable', 'string', 'max:2000']]);
        $staff = Staff::query()->findOrFail($d['staff_id']);
        $access->authorize($r->user(), $staff, 'view');
        abort_unless($staff->company_id === $d['company_id'], 422, 'Staff and timesheet legal entities must match.');
        $row = $service->saveTimesheet($d, $r->user()->id);
        $projection->timesheet($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => ['id' => $row->id, 'status' => $row->status, 'version' => $row->version]], 201);
    }
    public function transitionTimesheet(Request $r, string $id, WorkforceWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['action' => ['required', Rule::in(['submit', 'approve', 'return', 'lock', 'reopen'])], 'reason' => ['required', 'string', 'max:2000']]);
        $row = DB::table('hr_timesheets')->find($id);
        abort_unless($row, 404);
        $staff = Staff::query()->findOrFail($row->staff_id);
        if ($d['action'] === 'submit') {
            $access->authorize($r->user(), $staff, 'view');
            abort_unless($r->user()->can('hr.timesheets.manage'), 403, 'Submitting timesheets requires manage authority.');
        } else {
            $this->company($r, $row->company_id);
            $permission = in_array($d['action'], ['approve', 'return'], true) ? 'hr.timesheets.approve' : 'hr.timesheets.lock';
            abort_unless($r->user()->can($permission), 403, 'This timesheet transition requires separate authority.');
        }
        $row = $service->transitionTimesheet($id, $d['action'], $d['reason'], $r->user()->id);
        $projection->timesheet($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => ['id' => $row->id, 'status' => $row->status, 'version' => $row->version]]);
    }
    public function payrollInputs(Request $r, StaffAccessService $access): JsonResponse
    {
        $data = $r->validate(['company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid']]);
        $company = $this->company($r, $data['company_id'] ?? null);
        $staff = $access->scope(Staff::query()->where('company_id', $company)->select('staff.id'), $r->user());
        $q = DB::table('hr_payroll_input_facts')->where('company_id', $company)->whereIn('staff_id', $staff)
            ->when($data['staff_id'] ?? null, fn ($query, $staffId) => $query->where('staff_id', $staffId))
            ->when($r->status, fn ($query, $status) => $query->where('status', $status))
            ->latest('effective_date')->select([
                'id', 'staff_id', 'fact_kind', 'effective_date', 'quantity_minutes',
                'quantity_units', 'rate_category', 'status', 'created_at', 'updated_at',
            ]);
        return response()->json(['status' => 'success', 'data' => $q->paginate($r->integer('per_page', 50))]);
    }

    private function approveConfig(Request $r, string $table, string $id, ?callable $validate = null): JsonResponse
    {
        $this->enabled();
        return DB::transaction(function () use ($r, $table, $id, $validate) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->company($r, $row->company_id);
            abort_if($row->created_by === $r->user()->id, 409, 'The configuration creator cannot approve the same record.');
            if (property_exists($row, 'status'))
                abort_unless($row->status === 'pending_approval', 409, 'Only pending configuration may be approved.');
            else
                abort_if($row->approved_at, 409, 'This assignment is already approved.');
            if ($validate) $validate($row);
            $update = ['approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()];
            if (property_exists($row, 'status'))
                $update['status'] = 'approved';
            DB::table($table)->where('id', $id)->update($update);
            return response()->json(['status' => 'success', 'data' => DB::table($table)->find($id)]); });
    }
    private function company(Request $r, ?string $id): string
    {
        $allowed = $this->authorizedCompanyIds($r);
        abort_unless($allowed->isNotEmpty(), 403, 'The authenticated user has no active Staff legal-entity context.');
        if ($id) {
            abort_unless($allowed->contains($id), 403, 'Workforce data is outside your legal entity.');
            return $id;
        }
        if ($allowed->count() === 1 && ! $r->user()->can('staff.view-all')) return (string) $allowed->first();

        $defaultCompanyId = DB::table('companies')->whereIn('id', $allowed)
            ->where('is_active', true)->where('is_default', true)->whereNull('deleted_at')->value('id');
        abort_unless($defaultCompanyId, 422, 'Select an authorized legal entity.');
        return (string) $defaultCompanyId;
    }

    private function authorizedCompanyIds(Request $r)
    {
        if ($r->user()->can('staff.view-all')) {
            return DB::table('companies')->where('is_active', true)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('name')->pluck('id');
        }

        $contexts = DB::table('user_contexts')->where('user_id', $r->user()->id)
            ->where('context_type', 'staff')->where('is_active', true)->whereNull('deleted_at');
        $type = $r->header('X-Active-Context-Type');
        $contextId = $r->header('X-Active-Context-Id');
        if ($type === 'staff') {
            abort_unless($contextId && Str::isUuid($contextId), 403, 'Select an active Staff context.');
            $contexts->where('id', $contextId);
        } elseif ($type !== null && $type !== 'internal') {
            abort(403, 'Select an active Staff context.');
        }

        return DB::table('companies')->whereIn('id', Staff::query()->where('user_id', $r->user()->id)
            ->whereIn('id', $contexts->pluck('context_id'))->whereNotNull('company_id')
            ->where(fn ($q) => $q->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->select('company_id'))
            ->where('is_active', true)->whereNull('deleted_at')->pluck('id');
    }

    private function leaveRequestForCompany(Request $r, string $id): object
    {
        $row = DB::table('hr_leave_requests')->where('id', $id)
            ->whereIn('company_id', $this->authorizedCompanyIds($r))->first();
        abort_unless($row, 404);
        return $row;
    }

    private function workRequestForCompany(Request $r, string $id): object
    {
        $row = DB::table('hr_work_requests')->where('id', $id)
            ->whereIn('company_id', $this->authorizedCompanyIds($r))->first();
        abort_unless($row, 404);
        return $row;
    }

    private function leaveRequestForStaff(Request $r, string $id, StaffAccessService $access): object
    {
        $staffIds = $access->scope(Staff::query(), $r->user())->select('id');
        $row = DB::table('hr_leave_requests')->where('id', $id)->whereIn('staff_id', $staffIds)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function enabled(): void
    {
        abort_unless(config('hr.features.leave_overtime', false), 409, 'Leave, overtime, and timesheet writes are not enabled.');
    }
}
