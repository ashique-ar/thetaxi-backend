<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\Hr\Leave\LeaveWorkflowService;
use App\Services\Hr\Workforce\WorkforceWorkflowService;
use App\Services\StaffAccessService;
use App\Services\Hr\Ess\HrDomainRequestProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WorkforceController extends Controller
{
    public function leaveRequests(Request $r, StaffAccessService $access): JsonResponse
    {
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $q = DB::table('hr_leave_requests as request')->join('hr_leave_types as type', 'type.id', '=', 'request.leave_type_id')->whereIn('request.staff_id', $staff)->select(['request.id', 'request.staff_id', 'type.code as leave_type_code', 'type.name as leave_type_name', 'type.paid', 'request.start_date', 'request.end_date', 'request.unit', 'request.requested_minutes', 'request.status', 'request.current_approver_staff_id', 'request.requested_by', 'request.actual_return_date', 'request.recalled_at', 'request.created_at'])->when($r->status, fn($b, $v) => $b->where('request.status', $v))->latest('request.created_at');
        return response()->json(['status' => 'success', 'data' => $q->paginate($r->integer('per_page', 50))]);
    }
    public function leaveBalances(Request $r, StaffAccessService $access, LeaveWorkflowService $service): JsonResponse
    {
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $asOf = $r->date('as_of')?->toDateString() ?? now()->toDateString();
        $rows = DB::table('hr_leave_balance_accounts as account')->join('hr_leave_types as type', 'type.id', '=', 'account.leave_type_id')->whereIn('account.staff_id', $staff)->select(['account.id', 'account.staff_id', 'type.code', 'type.name', 'account.unit'])->get()->map(fn($row) => (array) $row + ['balance_minutes' => $service->balance($row->id, $asOf), 'as_of' => $asOf]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function teamCalendar(Request $r, StaffAccessService $access): JsonResponse
    {
        $d = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $rows = DB::table('hr_leave_requests as request')->join('hr_leave_types as type', 'type.id', '=', 'request.leave_type_id')->whereIn('request.staff_id', $staff)->where('request.status', 'approved')->whereDate('request.start_date', '<=', $d['to'])->whereDate('request.end_date', '>=', $d['from'])->select(['request.id', 'request.staff_id', 'request.start_date', 'request.end_date', 'type.name as absence_type', 'type.medical_confidential'])->get()->map(fn($row) => ['id' => $row->id, 'staff_id' => $row->staff_id, 'start_date' => $row->start_date, 'end_date' => $row->end_date, 'absence_type' => $row->medical_confidential ? 'Unavailable' : $row->absence_type]);
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
    public function decideLeave(Request $r, string $id, LeaveWorkflowService $service, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['action' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required', 'string', 'max:2000']]);
        $row = DB::table('hr_leave_requests')->find($id);
        abort_unless($row, 404);
        $this->company($r, $row->company_id);
        $row = $service->decide($id, $d['action'], $d['reason'], $r->user()->id, $r->user()->can('hr.leave.approve.override'));
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function cancelLeave(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['reason' => ['required', 'string', 'max:2000']]);
        $row = DB::table('hr_leave_requests')->find($id);
        abort_unless($row, 404);
        $access->authorize($r->user(), Staff::query()->findOrFail($row->staff_id), 'view');
        abort_unless($row->requested_by === $r->user()->id || $r->user()->can('hr.leave.approve'), 403, 'Only the requester or an authorized leave approver may cancel this request.');
        $row = $service->cancel($id, $d['reason'], $r->user()->id);
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function confirmLeaveReturn(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['actual_return_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $row = DB::table('hr_leave_requests')->find($id);
        abort_unless($row, 404);
        $access->authorize($r->user(), Staff::query()->findOrFail($row->staff_id), 'view');
        abort_unless($row->requested_by === $r->user()->id || $r->user()->can('hr.leave.approve'), 403, 'Only the requester or an authorized leave approver may confirm a return to work.');
        $row = $service->confirmReturn($id, $d['actual_return_date'], $d['notes'] ?? null, $r->user()->id);
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function extendLeave(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['end_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        $row = DB::table('hr_leave_requests')->find($id);
        abort_unless($row, 404);
        $access->authorize($r->user(), Staff::query()->findOrFail($row->staff_id), 'view');
        abort_unless($row->requested_by === $r->user()->id || $r->user()->can('hr.leave.approve'), 403, 'Only the requester or an authorized leave approver may extend this request.');
        $row = $service->extend($id, $d['end_date'], $d['reason'], $r->user()->id);
        $projection->leave($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function recallLeave(Request $r, string $id, LeaveWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['recall_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        $row = DB::table('hr_leave_requests')->find($id);
        abort_unless($row, 404);
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
        $staffIds = $access->scope(Staff::query(), $r->user())->select('id');
        $q = DB::table('hr_leave_policy_assignments as assignment')->join('hr_leave_policies as policy', 'policy.id', '=', 'assignment.policy_id')
            ->whereIn('assignment.staff_id', $staffIds)
            ->select(['assignment.id', 'assignment.staff_id', 'assignment.policy_id', 'policy.code as policy_code', 'assignment.effective_from', 'assignment.effective_until', 'assignment.reason', 'assignment.created_by', 'assignment.approved_by', 'assignment.approved_at'])
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
            foreach (['staff' => 'staff_id', 'hr_leave_policies' => 'policy_id'] as $table => $field)
                abort_unless(DB::table($table)->where('id', $d[$field])->where('company_id', $d['company_id'])->exists(), 422, 'Policy assignment references must share one legal entity.');
            $leaveType = DB::table('hr_leave_policies')->where('id', $d['policy_id'])->value('leave_type_id');
            $sameTypePolicies = DB::table('hr_leave_policies')->where('leave_type_id', $leaveType)->select('id');
            $overlap = DB::table('hr_leave_policy_assignments')->where('staff_id', $d['staff_id'])->whereIn('policy_id', $sameTypePolicies)->whereDate('effective_from', '<', $d['effective_until'] ?? '9999-12-31')->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $d['effective_from']))->exists();
            abort_if($overlap, 409, 'An overlapping policy assignment exists for this leave type.');
            $id = (string) Str::uuid();
            DB::table('hr_leave_policy_assignments')->insert($d + ['id' => $id, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_leave_policy_assignments')->find($id)], 201); });
    }
    public function approveLeaveAssignment(Request $r, string $id): JsonResponse
    {
        return $this->approveConfig($r, 'hr_leave_policy_assignments', $id);
    }

    public function workRequests(Request $r, StaffAccessService $access): JsonResponse
    {
        $staff = $access->scope(Staff::query(), $r->user())->select('id');
        $q = DB::table('hr_work_requests')->whereIn('staff_id', $staff)->select(['id', 'staff_id', 'request_kind', 'starts_at', 'ends_at', 'requested_minutes', 'rate_category', 'settlement_kind', 'status', 'requested_by', 'decided_at', 'created_at'])->when($r->request_kind, fn($b, $v) => $b->where('request_kind', $v))->when($r->status, fn($b, $v) => $b->where('status', $v))->latest('starts_at');
        return response()->json(['status' => 'success', 'data' => $q->paginate($r->integer('per_page', 50))]);
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
        $row = DB::table('hr_work_requests')->find($id);
        abort_unless($row, 404);
        $this->company($r, $row->company_id);
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
        return response()->json(['status' => 'success', 'data' => DB::table('hr_timesheets')->whereIn('staff_id', $staff)->latest('period_start')->paginate($r->integer('per_page', 50))]);
    }
    public function saveTimesheet(Request $r, WorkforceWorkflowService $service, StaffAccessService $access, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $d = $r->validate(['company_id' => ['required', 'uuid'], 'staff_id' => ['required', 'uuid'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'entries' => ['required', 'array', 'min:1'], 'entries.*.work_date' => ['required', 'date'], 'entries.*.started_at' => ['nullable', 'date'], 'entries.*.ended_at' => ['nullable', 'date'], 'entries.*.minutes' => ['nullable', 'integer', 'min:1'], 'entries.*.entry_mode' => ['required', Rule::in(['manual', 'timer'])], 'entries.*.cost_centre_code' => ['nullable', 'string', 'max:80'], 'entries.*.project_code' => ['nullable', 'string', 'max:80'], 'entries.*.booking_id' => ['nullable', 'uuid', 'exists:bookings,id'], 'entries.*.job_reference' => ['nullable', 'string', 'max:120'], 'entries.*.activity_code' => ['required', 'string', 'max:80'], 'entries.*.billable' => ['required', 'boolean'], 'entries.*.notes' => ['nullable', 'string', 'max:2000']]);
        $staff = Staff::query()->findOrFail($d['staff_id']);
        $access->authorize($r->user(), $staff, 'view');
        abort_unless($staff->company_id === $d['company_id'], 422, 'Staff and timesheet legal entities must match.');
        $row = $service->saveTimesheet($d, $r->user()->id);
        $projection->timesheet($row, $r->user()->id);
        return response()->json(['status' => 'success', 'data' => $row], 201);
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
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function payrollInputs(Request $r): JsonResponse
    {
        $company = $this->company($r, $r->input('company_id'));
        $q = DB::table('hr_payroll_input_facts')->where('company_id', $company)->when($r->staff_id, fn($b, $v) => $b->where('staff_id', $v))->when($r->status, fn($b, $v) => $b->where('status', $v))->latest('effective_date');
        return response()->json(['status' => 'success', 'data' => $q->paginate($r->integer('per_page', 50))]);
    }

    private function approveConfig(Request $r, string $table, string $id): JsonResponse
    {
        $this->enabled();
        return DB::transaction(function () use ($r, $table, $id) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->company($r, $row->company_id);
            abort_if($row->created_by === $r->user()->id, 409, 'The configuration creator cannot approve the same record.');
            if (property_exists($row, 'status'))
                abort_unless($row->status === 'pending_approval', 409, 'Only pending configuration may be approved.');
            else
                abort_if($row->approved_at, 409, 'This assignment is already approved.');
            $update = ['approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()];
            if (property_exists($row, 'status'))
                $update['status'] = 'approved';
            DB::table($table)->where('id', $id)->update($update);
            return response()->json(['status' => 'success', 'data' => DB::table($table)->find($id)]); });
    }
    private function company(Request $r, ?string $id): string
    {
        $actor = Staff::query()->where('user_id', $r->user()->id)->value('company_id');
        abort_unless($actor && (!$id || $actor === $id), 403, 'Workforce data is outside your legal entity.');
        return $actor;
    }
    private function enabled(): void
    {
        abort_unless(config('hr.features.leave_overtime', false), 409, 'Leave, overtime, and timesheet writes are not enabled.');
    }
}
