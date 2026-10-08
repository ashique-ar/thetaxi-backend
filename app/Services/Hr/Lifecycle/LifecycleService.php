<?php

namespace App\Services\Hr\Lifecycle;

use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Ess\HrRequestIndexService;
use App\Services\Hr\PeopleCoreService;
use App\Services\StaffIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LifecycleService
{
    public function __construct(private readonly PeopleCoreService $people, private readonly StaffIdentityService $identities, private readonly HrRequestIndexService $requests)
    {
    }
    public function openCase(array $data, string $actor): object
    {
        return DB::transaction(function () use ($data, $actor) {
            $template = DB::table('hr_lifecycle_templates')->where('id', $data['template_id'])->where('company_id', $data['company_id'])->where('status', 'approved')->first();
            abort_unless($template, 422, 'An approved lifecycle template is required.');
            $hasStaff = ! empty($data['staff_id']);
            $hasApplication = ! empty($data['application_id']);
            abort_unless(($hasStaff xor $hasApplication), 422, 'Select exactly one lifecycle Staff member or candidate application.');
            if ($hasStaff) {
                abort_unless(Staff::query()->whereKey($data['staff_id'])->where('company_id', $data['company_id'])->exists(), 422, 'The lifecycle Staff member is outside the selected legal entity.');
            } else {
                abort_unless(DB::table('hr_candidate_applications')->where('id', $data['application_id'])->where('company_id', $data['company_id'])->exists(), 422, 'The lifecycle application is outside the selected legal entity.');
            }
            $taskDefinitions = json_decode($template->task_definitions, true, 512, JSON_THROW_ON_ERROR);
            $ownerIds = collect($taskDefinitions)->pluck('owner_staff_id')->filter(fn ($id) => $id !== null && $id !== '')->unique()->values();
            foreach ($ownerIds as $ownerId) {
                abort_unless(Str::isUuid((string) $ownerId), 422, 'Lifecycle task owners must be valid Staff records.');
            }
            if ($ownerIds->isNotEmpty()) {
                $activeOwnerIds = Staff::query()->whereIn('id', $ownerIds)->where('company_id', $data['company_id'])
                    ->whereNull('employment_ended_at')->lockForUpdate()->pluck('id')->map(fn ($id) => (string) $id);
                abort_unless($activeOwnerIds->count() === $ownerIds->count(), 422, 'Every lifecycle task owner must be active Staff in the selected legal entity.');
            }
            $id = (string) Str::uuid();
            DB::table('hr_lifecycle_cases')->insert(['id' => $id, 'company_id' => $data['company_id'], 'staff_id' => $data['staff_id'] ?? null, 'application_id' => $data['application_id'] ?? null, 'template_id' => $template->id, 'case_type' => $template->case_type, 'status' => 'open', 'effective_date' => $data['effective_date'], 'case_snapshot' => json_encode(['template_version' => json_decode($template->task_definitions, true), 'input' => $data], JSON_THROW_ON_ERROR), 'opened_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($taskDefinitions as $task)
                DB::table('hr_lifecycle_tasks')->insert(['id' => (string) Str::uuid(), 'case_id' => $id, 'task_code' => $task['code'], 'title' => $task['title'], 'owner_kind' => $task['owner_kind'] ?? 'hr', 'owner_staff_id' => $task['owner_staff_id'] ?? null, 'due_date' => isset($task['due_days']) ? now()->addDays((int) $task['due_days'])->toDateString() : null, 'dependency_codes' => json_encode($task['depends_on'] ?? [], JSON_THROW_ON_ERROR), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            return DB::table('hr_lifecycle_cases')->find($id); });
    }
    public function completeTask(string $id, array $evidence, string $actor, string $companyId, string $key): array
    {
        $checksum = hash('sha256', json_encode([
            'task_id' => $id, 'company_id' => $companyId, 'actor_id' => $actor, 'evidence' => $evidence,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($id, $evidence, $actor, $companyId, $key, $checksum) {
                $task = DB::table('hr_lifecycle_tasks as task')
                    ->join('hr_lifecycle_cases as lifecycle_case', 'lifecycle_case.id', '=', 'task.case_id')
                    ->where('task.id', $id)
                    ->where('lifecycle_case.company_id', $companyId)
                    ->select('task.*')
                    ->lockForUpdate()
                    ->first();
                abort_unless($task, 404);
                if ($task->status === 'completed') {
                    abort_unless($task->idempotency_key === $key
                        && $task->completed_by === $actor
                        && hash_equals((string) $task->completion_checksum, $checksum), 409,
                        'This lifecycle task was completed with different evidence or context.');
                    return ['task' => DB::table('hr_lifecycle_tasks')->select(['id', 'status', 'completed_at'])->find($id), 'replayed' => true];
                }
                abort_unless($task->status === 'pending', 409);
                abort_if(DB::table('hr_lifecycle_tasks')->where('idempotency_key', $key)->exists(), 409,
                    'This lifecycle task key was already used.');
                $deps = json_decode($task->dependency_codes ?: '[]', true, 512, JSON_THROW_ON_ERROR);
                if ($deps) {
                    abort_if(DB::table('hr_lifecycle_tasks')->where('case_id', $task->case_id)->whereIn('task_code', $deps)->where('status', '!=', 'completed')->exists(), 409, 'Dependent lifecycle tasks are incomplete.');
                }
                DB::table('hr_lifecycle_tasks')->where('id', $id)->update([
                    'status' => 'completed', 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
                    'idempotency_key' => $key, 'completion_checksum' => $checksum,
                    'completed_at' => now(), 'completed_by' => $actor, 'updated_at' => now(),
                ]);
                if (! DB::table('hr_lifecycle_tasks')->where('case_id', $task->case_id)->where('status', '!=', 'completed')->exists()) {
                    DB::table('hr_lifecycle_cases')->where('id', $task->case_id)->update([
                        'status' => 'completed', 'closed_at' => now(), 'closed_by' => $actor, 'updated_at' => now(),
                    ]);
                }
                activity('hr-lifecycle')->causedBy(User::query()->findOrFail($actor))->withProperties([
                    'task_id' => $id, 'case_id' => $task->case_id, 'company_id' => $companyId,
                ])->log('lifecycle_task_completed');
                return ['task' => DB::table('hr_lifecycle_tasks')->select(['id', 'status', 'completed_at'])->find($id), 'replayed' => false];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = DB::table('hr_lifecycle_tasks')->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless($existing->id === $id && $existing->completed_by === $actor
                    && hash_equals((string) $existing->completion_checksum, $checksum), 409,
                    'This lifecycle task key was already used with different evidence or context.');
                return ['task' => DB::table('hr_lifecycle_tasks')->select(['id', 'status', 'completed_at'])->find($id), 'replayed' => true];
            }
            throw $e;
        }
    }
    public function requestChange(Staff $staff, array $data, string $actor): object
    {
        return DB::transaction(function () use ($staff, $data, $actor) {
            $ownerStaffId = null;
            if (! empty($data['approver_staff_id'])) {
                $owner = Staff::query()->whereKey($data['approver_staff_id'])
                    ->where('company_id', $staff->company_id)
                    ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
                    ->lockForUpdate()->first();
                abort_unless($owner, 422, 'The selected approver must be active Staff in the employee legal entity.');
                $ownerStaffId = $owner->id;
            }
            $current = DB::table('hr_employment_assignments')->where('staff_id', $staff->id)->whereNull('effective_until')->lockForUpdate()->first();
            abort_unless($current, 422, 'An active assignment is required.');
            $evidence = ['staff_id' => $staff->id, 'change_type' => $data['change_type'], 'effective_date' => $data['effective_date'], 'proposed_snapshot' => $data['proposed_snapshot'], 'impact_snapshot' => $data['impact_snapshot'], 'reason' => $data['reason']];
            if ($existing = DB::table('hr_employee_change_requests')->where('idempotency_key', $data['idempotency_key'])->first()) {
                $prior = ['staff_id' => $existing->staff_id, 'change_type' => $existing->change_type, 'effective_date' => (string) $existing->effective_date, 'proposed_snapshot' => json_decode($existing->proposed_snapshot, true), 'impact_snapshot' => json_decode($existing->impact_snapshot, true), 'reason' => $existing->reason];
                abort_unless(hash_equals(hash('sha256', json_encode($prior, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), hash('sha256', json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))), 409, 'Change key was reused with different evidence.');
                return $existing; }$id = (string) Str::uuid();
            DB::table('hr_employee_change_requests')->insert(['id' => $id, 'company_id' => $staff->company_id, 'staff_id' => $staff->id, 'change_type' => $data['change_type'], 'effective_date' => $data['effective_date'], 'before_snapshot' => json_encode($current, JSON_THROW_ON_ERROR), 'proposed_snapshot' => json_encode($data['proposed_snapshot'], JSON_THROW_ON_ERROR), 'impact_snapshot' => json_encode($data['impact_snapshot'], JSON_THROW_ON_ERROR), 'status' => 'pending_approval', 'reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key'], 'requested_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->requests->register($staff->company_id, $staff->id, $data['change_type'], 'employee_change', $id, 'pending_approval', ucwords(str_replace('_', ' ', $data['change_type'])) . ' request', $ownerStaffId, $data['sla_hours'] ?? null, $actor, ['people_core' => true, 'payroll' => config('hr.features.payroll', false)]);
            return DB::table('hr_employee_change_requests')->find($id); });
    }
    public function approveChange(string $id, string $actor, string $companyId): object
    {
        return DB::transaction(function () use ($id, $actor, $companyId) {
            $company = DB::table('companies')->where('id', $companyId)->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 409, 'Employee changes require an active legal entity.');
            $row = DB::table('hr_employee_change_requests')->where('id', $id)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status === 'approved') {
                abort_unless($row->approved_by !== null && (string) $row->approved_by === $actor, 409, 'Employee change was approved by another user.');

                return $row;
            }
            abort_unless($row->status === 'pending_approval', 409);
            abort_if((string) $row->requested_by === $actor, 409, 'Requester cannot approve the same employee change.');
            $staff = Staff::query()->findOrFail($row->staff_id);
            $proposed = json_decode($row->proposed_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $assignment = $this->people->applyApprovedAssignmentChange($staff, $proposed + ['effective_from' => $row->effective_date, 'change_type' => $row->change_type], $actor, $row->id);
            DB::table('hr_employee_change_requests')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'result_assignment_id' => $assignment->id, 'updated_at' => now()]);
            $this->requests->transition('employee_change', $id, 'approved', 'Employee change approved.', $actor, null, 'employment_assignment', $assignment->id);
            return DB::table('hr_employee_change_requests')->find($id); });
    }
    public function approveExit(string $id, string $actor, string $companyId): object
    {
        return DB::transaction(function () use ($id, $actor, $companyId) {
            $company = DB::table('companies')->where('id', $companyId)->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 409, 'Exit approval requires an active legal entity.');
            $exit = DB::table('hr_exit_cases')->where('id', $id)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($exit, 404);
            if (in_array($exit->status, ['clearance', 'completed'], true)) {
                abort_unless($exit->approved_by !== null && (string) $exit->approved_by === $actor, 409, 'Exit case was approved by another user.');

                return DB::table('hr_exit_cases')->select(['id', 'company_id', 'staff_id', 'exit_type', 'reason_code', 'status', 'approved_last_working_date', 'approved_at'])->find($id);
            }
            abort_unless($exit->status === 'pending_approval', 409);
            abort_if($exit->opened_by === $actor, 409, 'Exit initiator cannot approve the same case.');
            $staff = Staff::query()->whereKey($exit->staff_id)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($staff, 409, 'Exit case employee is outside this legal entity.');
            DB::table('hr_exit_cases')->where('id', $id)->update(['status' => 'clearance', 'approved_last_working_date' => $exit->proposed_last_working_date, 'approved_by' => $actor, 'approved_at' => now(), 'updated_at' => now()]);
            $this->requests->transition('exit_case', $id, 'clearance', 'Exit case approved; clearance started.', $actor);
            $items = DB::table('hr_custody_assignments')->where('company_id', $companyId)->where('staff_id', $exit->staff_id)->where('status', 'assigned')->lockForUpdate()->get();
            foreach ($items as $item)
                $this->clearance($id, $item->custody_type, 'Return ' . $item->item_name, $item->id);
            foreach (['handover' => 'Complete handover', 'attendance' => 'Finalize attendance and leave', 'payroll' => 'Confirm final settlement boundary', 'access' => 'Revoke all remaining system and physical access'] as $type => $title)
                $this->clearance($id, $type, $title, null);
            return DB::table('hr_exit_cases')->select(['id', 'company_id', 'staff_id', 'exit_type', 'reason_code', 'status', 'approved_last_working_date', 'approved_at'])->find($id); });
    }
    public function completeClearance(string $id, string $resolution, string $actor, string $companyId): array
    {
        return DB::transaction(function () use ($id, $resolution, $actor, $companyId) {
            $company = DB::table('companies')->where('id', $companyId)->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 409, 'Exit clearance requires an active legal entity.');
            $item = DB::table('hr_exit_clearance_items as clearance')
                ->join('hr_exit_cases as exit_case', 'exit_case.id', '=', 'clearance.exit_case_id')
                ->where('clearance.id', $id)
                ->where('exit_case.company_id', $companyId)
                ->select('clearance.*')
                ->lockForUpdate()
                ->first();
            abort_unless($item, 404);
            if ($item->status === 'completed') {
                abort_unless($item->completed_by === $actor && hash_equals((string) $item->resolution, $resolution), 409,
                    'This clearance item was completed with different resolution or context.');
                return ['item' => DB::table('hr_exit_clearance_items')->select(['id', 'status', 'completed_at'])->find($id), 'replayed' => true];
            }
            abort_unless($item->status === 'pending', 409);

            if ($item->custody_assignment_id) {
                $custody = DB::table('hr_custody_assignments')->where('id', $item->custody_assignment_id)->where('company_id', $companyId)->lockForUpdate()->first();
                abort_unless($custody, 409, 'The linked custody assignment is outside this lifecycle legal entity.');
                if (config('hr.features.advanced_assets', false) && $custody?->asset_item_id) {
                    abort_unless(in_array($custody->status, ['returned', 'recovery_approved', 'exception_approved'], true), 409, 'Complete the governed asset return or approved recovery/exception before clearance.');
                }

                if (! (config('hr.features.advanced_assets', false) && $custody->asset_item_id)) {
                    DB::table('hr_custody_assignments')->where('id', $item->custody_assignment_id)->where('company_id', $companyId)->update([
                        'status' => 'returned', 'returned_at' => now(), 'received_by' => $actor, 'updated_at' => now(),
                    ]);
                }
            }

            DB::table('hr_exit_clearance_items')->where('id', $id)->update([
                'status' => 'completed', 'resolution' => $resolution, 'completed_at' => now(),
                'completed_by' => $actor, 'updated_at' => now(),
            ]);
            activity('hr-lifecycle')->causedBy(User::query()->findOrFail($actor))->withProperties([
                'clearance_item_id' => $id, 'exit_case_id' => $item->exit_case_id, 'company_id' => $companyId,
            ])->log('exit_clearance_completed');

            return [
                'item' => DB::table('hr_exit_clearance_items')->select(['id', 'status', 'completed_at'])->find($id),
                'replayed' => false,
            ];
        });
    }
    public function finalizeExit(string $id, User $actor, string $companyId): array
    {
        return DB::transaction(function () use ($id, $actor, $companyId) {
            $company = DB::table('companies')->where('id', $companyId)->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 409, 'Exit finalization requires an active legal entity.');
            $exit = DB::table('hr_exit_cases')->where('id', $id)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($exit, 404);
            if ($exit->status === 'completed') {
                $termination = DB::table('staff_context_termination_events')->where('company_id', $companyId)
                    ->where('staff_id', $exit->staff_id)->where('idempotency_key', $id)->first();
                abort_unless($termination && (string) $termination->actor_user_id === (string) $actor->id, 409, 'Exit was finalized by another user or lacks replay evidence.');

                return json_decode((string) $termination->outcome_snapshot, true, 512, JSON_THROW_ON_ERROR);
            }
            abort_unless($exit->status === 'clearance', 409);
            $clearances = DB::table('hr_exit_clearance_items')->where('exit_case_id', $id)->lockForUpdate()->get(['id', 'status']);
            abort_unless($clearances->isNotEmpty() && $clearances->every(fn ($item) => $item->status === 'completed'), 409, 'Every exit clearance item must be completed.');
            abort_if(now()->toDateString() < $exit->approved_last_working_date, 409, 'Scheduled exit date has not arrived.');
            $staff = Staff::query()->whereKey($exit->staff_id)->where('company_id', $companyId)->lockForUpdate()->firstOrFail();
            $result = $this->identities->terminate($staff, $actor, 'Exit case ' . $id . ': ' . $exit->reason_code, $id);
            DB::table('hr_exit_cases')->where('id', $id)->update(['status' => 'completed', 'updated_at' => now()]);
            $this->requests->transition('exit_case', $id, 'completed', 'Exit clearance completed.', $actor->id, null, 'staff', $staff->id);
            return $result; });
    }
    private function clearance(string $exit, string $type, string $title, ?string $custody): void
    {
        DB::table('hr_exit_clearance_items')->insert(['id' => (string) Str::uuid(), 'exit_case_id' => $exit, 'clearance_type' => $type, 'custody_assignment_id' => $custody, 'title' => $title, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }
}
