<?php

namespace App\Services\Hr\Leave;

use App\Models\Hr\Leave\LeaveBalanceEntry;
use App\Models\Hr\PayrollInputFact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeaveWorkflowService
{
    public function submit(array $data, string $actorUserId): object
    {
        $this->enabled();
        $checksum = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($existing = DB::table('hr_leave_requests')->where('idempotency_key', $data['idempotency_key'])->first()) {
            abort_unless(hash_equals($existing->request_checksum, $checksum), 409, 'Leave idempotency key was reused with different evidence.');
            return $existing;
        }
        return DB::transaction(function () use ($data, $actorUserId, $checksum) {
            DB::table('staff')->where('id', $data['staff_id'])->lockForUpdate()->first();
            $policy = DB::table('hr_leave_policies')->where('id', $data['policy_id'])->where('company_id', $data['company_id'])->where('status', 'approved')->whereDate('effective_from', '<=', $data['start_date'])->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $data['end_date']))->first();
            abort_unless($policy, 422, 'No approved leave policy covers the requested interval.');
            abort_unless(DB::table('hr_leave_policy_assignments')->where('staff_id', $data['staff_id'])->where('policy_id', $policy->id)->whereNotNull('approved_at')->whereDate('effective_from', '<=', $data['start_date'])->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $data['end_date']))->exists(), 422, 'The leave policy is not assigned for the full interval.');
            $rules = json_decode($policy->rules, true, 512, JSON_THROW_ON_ERROR);
            $days = $this->days($data, $rules);
            $minutes = array_sum(array_column($days, 'minutes'));
            abort_if($minutes <= 0, 422, 'The request contains no eligible leave time.');
            abort_if(count($days) > (int) ($rules['maximum_consecutive_days'] ?? 366), 422, 'The request exceeds the policy consecutive-day limit.');
            $notice = (int) ($rules['minimum_notice_days'] ?? 0);
            abort_if(now()->startOfDay()->addDays($notice)->gt(CarbonImmutable::parse($data['start_date'])), 422, 'The leave policy notice period is not met.');
            $blackouts = $rules['blackout_dates'] ?? [];
            abort_if(collect($days)->contains(fn($day) => in_array($day['date'], $blackouts, true)), 422, 'The request includes a policy blackout date.');
            if (($rules['coverage_required'] ?? false) && empty($data['coverage_snapshot']))
                abort(422, 'Coverage details are required for this leave policy.');
            if ($minutes > (int) ($rules['supporting_document_after_minutes'] ?? PHP_INT_MAX) && empty($data['private_evidence']))
                abort(422, 'Supporting evidence is required for this leave duration.');
            if (!($rules['allow_during_probation'] ?? true)) {
                $spell = DB::table('hr_employment_spells')->where('staff_id', $data['staff_id'])->where('status', 'active')->latest('joined_at')->first();
                abort_if($spell && $spell->confirmation_date && CarbonImmutable::parse($data['start_date'])->lt(CarbonImmutable::parse($spell->confirmation_date)), 422, 'This leave type is not available before confirmation.');
            }
            $account = $this->account($data['company_id'], $data['staff_id'], $policy->leave_type_id, $data['unit']);
            $balance = $this->balance($account->id, $data['start_date']);
            $negative = (int) ($rules['negative_balance_limit_minutes'] ?? 0);
            abort_if($balance - $minutes < -$negative, 409, 'Insufficient available leave balance.');
            $overlap = DB::table('hr_leave_requests')->where('staff_id', $data['staff_id'])->whereIn('status', ['pending_approval', 'approved'])->whereDate('start_date', '<=', $data['end_date'])->whereDate('end_date', '>=', $data['start_date'])->exists();
            abort_if($overlap, 409, 'An active leave request overlaps this interval.');
            $id = (string) Str::uuid();
            $snapshot = ['policy_id' => $policy->id, 'policy_version' => $policy->version, 'rules' => $rules, 'balance_before_minutes' => $balance, 'calculated_days' => $days];
            DB::table('hr_leave_requests')->insert(['id' => $id, 'company_id' => $data['company_id'], 'staff_id' => $data['staff_id'], 'leave_type_id' => $policy->leave_type_id, 'policy_id' => $policy->id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'unit' => $data['unit'], 'requested_minutes' => $minutes, 'reserved_minutes' => $minutes, 'status' => 'pending_approval', 'reason' => $data['reason'], 'calculation_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'coverage_snapshot' => json_encode($data['coverage_snapshot'] ?? null, JSON_THROW_ON_ERROR), 'private_evidence' => isset($data['private_evidence']) ? encrypt($data['private_evidence']) : null, 'request_checksum' => $checksum, 'idempotency_key' => $data['idempotency_key'], 'requested_by' => $actorUserId, 'current_approver_staff_id' => $data['approver_staff_id'] ?? null, 'approval_level' => 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($days as $day)
                DB::table('hr_leave_request_days')->insert(['id' => (string) Str::uuid(), 'leave_request_id' => $id, 'leave_date' => $day['date'], 'minutes' => $day['minutes'], 'day_kind' => $day['kind'], 'rule_evidence' => json_encode($day, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $this->entry($account->id, $id, 'reservation', -$minutes, $data['start_date'], 'leave_request', $id, 'Leave request reservation', $snapshot, $actorUserId);
            $this->event($id, 'submitted', null, 'pending_approval', $data['reason'], $snapshot, $actorUserId);
            return DB::table('hr_leave_requests')->find($id);
        });
    }

    /**
     * $overrideAuthorized lets an actor holding the separate, deny-by-default
     * hr.leave.approve.override permission decide a request assigned to a
     * different approver — e.g. the assigned approver is unavailable. It never
     * bypasses the requester-cannot-decide-own-request or pending-status
     * checks, and every override is recorded on the decision event/snapshot
     * for audit rather than silently applied.
     */
    public function decide(string $requestId, string $action, string $reason, string $actorUserId, bool $overrideAuthorized = false): object
    {
        $this->enabled();
        return DB::transaction(function () use ($requestId, $action, $reason, $actorUserId, $overrideAuthorized) {
            $row = DB::table('hr_leave_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->requested_by === $actorUserId, 409, 'The leave requester cannot decide the same request.');
            abort_unless($row->status === 'pending_approval', 409, 'Only pending leave may be decided.');
            abort_unless(in_array($action, ['approve', 'reject'], true), 422, 'Unsupported leave decision.');
            $actorStaff = DB::table('staff')->where('user_id', $actorUserId)->value('id');
            $overrideUsed = false;
            $delegateUsed = false;
            if ($row->current_approver_staff_id && $actorStaff !== $row->current_approver_staff_id) {
                $delegateUsed = DB::table('hr_approval_delegations')->where('delegator_staff_id', $row->current_approver_staff_id)->where('delegate_staff_id', $actorStaff)->where('status', 'approved')->whereDate('effective_from', '<=', now())->whereDate('effective_until', '>=', now())->get(['request_types'])->contains(fn($delegation) => in_array('leave', json_decode($delegation->request_types, true), true));
                if (!$delegateUsed) {
                    abort_unless($overrideAuthorized, 403, 'This leave request is assigned to a different approver.');
                    $overrideUsed = true;
                }
            }
            $snapshot = json_decode($row->calculation_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if ($overrideUsed)
                $snapshot['hr_override'] = ['assigned_approver_staff_id' => $row->current_approver_staff_id, 'overridden_by' => $actorUserId];
            if ($delegateUsed)
                $snapshot['hr_delegate_decision'] = ['delegator_staff_id' => $row->current_approver_staff_id, 'decided_by' => $actorUserId];
            $levels = max(1, (int) ($snapshot['rules']['approval_levels'] ?? 1));
            if ($action === 'approve' && $row->approval_level < $levels) {
                $nextLevel = $row->approval_level + 1;
                $approvers = $snapshot['rules']['approver_staff_ids'] ?? [];
                $nextApprover = $approvers[$nextLevel - 1] ?? null;
                $this->event($row->id, 'approval_level_completed', 'pending_approval', 'pending_approval', $reason, $snapshot + ['completed_level' => $row->approval_level], $actorUserId);
                DB::table('hr_leave_requests')->where('id', $row->id)->update(['approval_level' => $nextLevel, 'current_approver_staff_id' => $nextApprover, 'updated_at' => now()]);
                return DB::table('hr_leave_requests')->find($row->id);
            }$locked = DB::table('hr_attendance_periods')->where('company_id', $row->company_id)->where('status', 'locked')->whereDate('period_start', '<=', $row->end_date)->whereDate('period_end', '>=', $row->start_date)->exists();
            abort_if($locked && $action === 'approve', 409, 'Reopen the overlapping locked attendance period before approving leave.');
            $account = $this->account($row->company_id, $row->staff_id, $row->leave_type_id, $row->unit);
            $this->entry($account->id, $row->id, 'reservation_release', $row->reserved_minutes, $row->start_date, 'leave_request', $row->id, 'Release pending reservation', $snapshot, $actorUserId);
            $next = $action === 'approve' ? 'approved' : 'rejected';
            if ($next === 'approved') {
                $this->entry($account->id, $row->id, 'consumption', -$row->requested_minutes, $row->start_date, 'leave_approval', $row->id, 'Approved leave consumption', $snapshot, $actorUserId);
                $type = DB::table('hr_leave_types')->find($row->leave_type_id);
                if (!$type->paid)
                    $this->payrollFact($row->company_id, $row->staff_id, 'unpaid_leave', $row->start_date, $row->requested_minutes, 'leave_request', $row->id, ['request' => $row, 'leave_type' => $type], $actorUserId);
            }$this->event($row->id, $action, $row->status, $next, $reason, $snapshot, $actorUserId);
            DB::table('hr_leave_requests')->where('id', $row->id)->update(['status' => $next, 'decided_at' => now(), 'decided_by' => $actorUserId, 'updated_at' => now()]);
            return DB::table('hr_leave_requests')->find($row->id);
        });
    }

    public function cancel(string $requestId, string $reason, string $actorUserId): object
    {
        $this->enabled();
        return DB::transaction(function () use ($requestId, $reason, $actorUserId) {
            $row = DB::table('hr_leave_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(in_array($row->status, ['pending_approval', 'approved'], true), 409, 'This leave request cannot be cancelled.');
            abort_if($row->recalled_at !== null, 409, 'This leave request was already recalled; the recalled portion cannot also be cancelled.');
            $locked = DB::table('hr_attendance_periods')->where('company_id', $row->company_id)->where('status', 'locked')->whereDate('period_start', '<=', $row->end_date)->whereDate('period_end', '>=', $row->start_date)->exists();
            abort_if($locked && $row->status === 'approved', 409, 'Reopen the overlapping locked attendance period before cancelling approved leave.');
            $account = $this->account($row->company_id, $row->staff_id, $row->leave_type_id, $row->unit);
            $snapshot = json_decode($row->calculation_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if ($row->status === 'pending_approval')
                $this->entry($account->id, $row->id, 'reservation_release', $row->reserved_minutes, $row->start_date, 'leave_cancel', $row->id, 'Cancelled reservation', $snapshot, $actorUserId);
            else {
                $this->entry($account->id, $row->id, 'consumption_reversal', $row->requested_minutes, $row->start_date, 'leave_cancel', $row->id, 'Cancelled approved leave', $snapshot, $actorUserId);
                $fact = DB::table('hr_payroll_input_facts')->where('source_type', 'leave_request')->where('source_id', $row->id)->where('status', 'staged')->first();
                if ($fact)
                    $this->payrollFact($row->company_id, $row->staff_id, 'unpaid_leave_reversal', $row->start_date, -$row->requested_minutes, 'leave_cancel', $row->id, ['reverses_fact_id' => $fact->id, 'reason' => $reason], $actorUserId);
            }$this->event($row->id, 'cancel', $row->status, 'cancelled', $reason, $snapshot, $actorUserId);
            DB::table('hr_leave_requests')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            return DB::table('hr_leave_requests')->find($row->id);
        });
    }

    /**
     * §5.9 return-to-work: confirms the actual return date for an approved
     * leave request. An on-time or early return re-credits the unused
     * reserved days (found via `hr_leave_request_days`) back to the balance
     * and reverses the matching portion of any staged unpaid-leave payroll
     * fact. A later-than-planned return is out of scope here — it requires
     * the separate, not-yet-implemented extension workflow, which must
     * revalidate notice/blackout/coverage rules for the added days rather
     * than silently stretching an already-approved reservation.
     */
    public function confirmReturn(string $requestId, string $actualReturnDate, ?string $notes, string $actorUserId): object
    {
        $this->enabled();
        return DB::transaction(function () use ($requestId, $actualReturnDate, $notes, $actorUserId) {
            $row = DB::table('hr_leave_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'approved', 409, 'Only an approved leave request can confirm a return to work.');
            abort_if($row->actual_return_date !== null, 409, 'A return to work was already confirmed for this request.');
            abort_if($row->recalled_at !== null, 409, 'This leave request was already recalled; it cannot also confirm a self-reported return.');
            abort_if($actualReturnDate < $row->start_date, 422, 'The return date cannot be before the leave started.');
            abort_if($actualReturnDate > $row->end_date, 422, 'A return after the approved end date requires the separate extension workflow, not a return-to-work confirmation.');
            $snapshot = json_decode($row->calculation_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $unusedDays = DB::table('hr_leave_request_days')->where('leave_request_id', $row->id)->whereDate('leave_date', '>', $actualReturnDate)->get();
            $reversedMinutes = (int) $unusedDays->sum('minutes');
            $account = $this->account($row->company_id, $row->staff_id, $row->leave_type_id, $row->unit);
            if ($reversedMinutes > 0) {
                $this->entry($account->id, $row->id, 'return_release', $reversedMinutes, $actualReturnDate, 'leave_return', $row->id, 'Unused reserved leave released on early return', $snapshot + ['actual_return_date' => $actualReturnDate, 'unused_days' => $unusedDays->pluck('leave_date')], $actorUserId);
                $type = DB::table('hr_leave_types')->find($row->leave_type_id);
                if (!$type->paid) {
                    $fact = DB::table('hr_payroll_input_facts')->where('source_type', 'leave_request')->where('source_id', $row->id)->where('status', 'staged')->first();
                    if ($fact)
                        $this->payrollFact($row->company_id, $row->staff_id, 'unpaid_leave_reversal', $actualReturnDate, -$reversedMinutes, 'leave_return', $row->id, ['reverses_fact_id' => $fact->id, 'actual_return_date' => $actualReturnDate], $actorUserId);
                }
            }
            $this->event($row->id, 'return_confirmed', $row->status, $row->status, $notes ?? 'Return to work confirmed', $snapshot + ['actual_return_date' => $actualReturnDate, 'reversed_minutes' => $reversedMinutes], $actorUserId);
            DB::table('hr_leave_requests')->where('id', $row->id)->update(['actual_return_date' => $actualReturnDate, 'return_confirmed_by' => $actorUserId, 'return_confirmed_at' => now(), 'updated_at' => now()]);
            return DB::table('hr_leave_requests')->find($row->id);
        });
    }

    /**
     * Extends an approved request's end date by re-validating policy/notice/
     * blackout/balance/consecutive-day rules over only the added days, then
     * appending those days rather than silently stretching the existing
     * reservation — this is the workflow confirmReturn() points to for a
     * later-than-approved return. Hour-unit leave is out of scope: extending
     * an end date has no clear meaning for a single-day hourly request, so a
     * new request is required instead of inventing that semantic here.
     */
    public function extend(string $requestId, string $newEndDate, string $reason, string $actorUserId): object
    {
        $this->enabled();
        return DB::transaction(function () use ($requestId, $newEndDate, $reason, $actorUserId) {
            $row = DB::table('hr_leave_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'approved', 409, 'Only an approved leave request can be extended.');
            abort_if($row->actual_return_date !== null, 409, 'A return to work was already confirmed for this request.');
            abort_if($row->recalled_at !== null, 409, 'This leave request was already recalled and cannot be extended.');
            abort_if($row->unit === 'hour', 422, 'Hourly leave cannot be extended by end date; submit a new request for additional hours.');
            abort_unless($newEndDate > $row->end_date, 422, 'An extension must move the end date later than the current approved end date.');
            $extensionStart = CarbonImmutable::parse($row->end_date)->addDay()->toDateString();
            $locked = DB::table('hr_attendance_periods')->where('company_id', $row->company_id)->where('status', 'locked')->whereDate('period_start', '<=', $newEndDate)->whereDate('period_end', '>=', $extensionStart)->exists();
            abort_if($locked, 409, 'Reopen the overlapping locked attendance period before extending approved leave.');
            $policy = DB::table('hr_leave_policies')->where('id', $row->policy_id)->where('company_id', $row->company_id)->where('status', 'approved')->whereDate('effective_from', '<=', $row->start_date)->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $newEndDate))->first();
            abort_unless($policy, 422, 'No approved leave policy covers the extended interval.');
            abort_unless(DB::table('hr_leave_policy_assignments')->where('staff_id', $row->staff_id)->where('policy_id', $policy->id)->whereNotNull('approved_at')->whereDate('effective_from', '<=', $row->start_date)->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $newEndDate))->exists(), 422, 'The leave policy is not assigned for the full extended interval.');
            $rules = json_decode($policy->rules, true, 512, JSON_THROW_ON_ERROR);
            $newDays = $this->days(['company_id' => $row->company_id, 'unit' => $row->unit, 'start_date' => $extensionStart, 'end_date' => $newEndDate], $rules);
            $addedMinutes = array_sum(array_column($newDays, 'minutes'));
            abort_if($addedMinutes <= 0, 422, 'The extension contains no additional eligible leave time.');
            $existingDayCount = DB::table('hr_leave_request_days')->where('leave_request_id', $row->id)->count();
            abort_if($existingDayCount + count($newDays) > (int) ($rules['maximum_consecutive_days'] ?? 366), 422, 'The extension exceeds the policy consecutive-day limit.');
            $blackouts = $rules['blackout_dates'] ?? [];
            abort_if(collect($newDays)->contains(fn($day) => in_array($day['date'], $blackouts, true)), 422, 'The extension includes a policy blackout date.');
            $overlap = DB::table('hr_leave_requests')->where('staff_id', $row->staff_id)->where('id', '!=', $row->id)->whereIn('status', ['pending_approval', 'approved'])->whereDate('start_date', '<=', $newEndDate)->whereDate('end_date', '>=', $extensionStart)->exists();
            abort_if($overlap, 409, 'Another active leave request overlaps the extension interval.');
            $account = $this->account($row->company_id, $row->staff_id, $row->leave_type_id, $row->unit);
            $balance = $this->balance($account->id, $row->start_date);
            $negative = (int) ($rules['negative_balance_limit_minutes'] ?? 0);
            abort_if($balance - $addedMinutes < -$negative, 409, 'Insufficient available leave balance for the extension.');
            foreach ($newDays as $day)
                DB::table('hr_leave_request_days')->insert(['id' => (string) Str::uuid(), 'leave_request_id' => $row->id, 'leave_date' => $day['date'], 'minutes' => $day['minutes'], 'day_kind' => $day['kind'], 'rule_evidence' => json_encode($day, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $snapshot = json_decode($row->calculation_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $this->entry($account->id, $row->id, 'reservation', -$addedMinutes, $extensionStart, 'leave_extension', $row->id, 'Leave extension reservation', $snapshot + ['extended_to' => $newEndDate], $actorUserId);
            $type = DB::table('hr_leave_types')->find($row->leave_type_id);
            if (!$type->paid)
                $this->payrollFact($row->company_id, $row->staff_id, 'unpaid_leave', $extensionStart, $addedMinutes, 'leave_extension', $row->id, ['request' => $row, 'leave_type' => $type, 'extended_to' => $newEndDate], $actorUserId);
            $this->event($row->id, 'extended', 'approved', 'approved', $reason, $snapshot + ['previous_end_date' => $row->end_date, 'extended_to' => $newEndDate, 'added_minutes' => $addedMinutes], $actorUserId);
            DB::table('hr_leave_requests')->where('id', $row->id)->update(['end_date' => $newEndDate, 'requested_minutes' => $row->requested_minutes + $addedMinutes, 'reserved_minutes' => $row->reserved_minutes + $addedMinutes, 'updated_at' => now()]);
            return DB::table('hr_leave_requests')->find($row->id);
        });
    }

    /**
     * §5.9 recall: employer-initiated early termination of an already-approved
     * leave request from a specified date, distinct from confirmReturn()
     * (employee self-reported actual return) and cancel() (full withdrawal by
     * requester or approver at any time). Recall is never self-actionable —
     * the requester cannot recall their own request — and is mutually
     * exclusive with a self-confirmed return-to-work on the same request.
     */
    public function recall(string $requestId, string $recallDate, string $reason, string $actorUserId): object
    {
        $this->enabled();
        return DB::transaction(function () use ($requestId, $recallDate, $reason, $actorUserId) {
            $row = DB::table('hr_leave_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->requested_by === $actorUserId, 403, 'The leave requester cannot recall their own request; recall is an employer-initiated action.');
            abort_unless($row->status === 'approved', 409, 'Only an approved leave request can be recalled.');
            abort_if($row->actual_return_date !== null, 409, 'A return to work was already confirmed for this request.');
            abort_if($row->recalled_at !== null, 409, 'This leave request was already recalled.');
            abort_if($recallDate < $row->start_date, 422, 'The recall date cannot be before the leave started.');
            abort_if($recallDate > $row->end_date, 422, 'The recall date cannot be after the approved end date; use extension or cancellation instead.');
            $snapshot = json_decode($row->calculation_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $unusedDays = DB::table('hr_leave_request_days')->where('leave_request_id', $row->id)->whereDate('leave_date', '>', $recallDate)->get();
            $reversedMinutes = (int) $unusedDays->sum('minutes');
            abort_if($reversedMinutes <= 0, 422, 'The recall date leaves no unused reserved leave to release.');
            $account = $this->account($row->company_id, $row->staff_id, $row->leave_type_id, $row->unit);
            $this->entry($account->id, $row->id, 'return_release', $reversedMinutes, $recallDate, 'leave_recall', $row->id, 'Unused reserved leave released on recall', $snapshot + ['recall_date' => $recallDate, 'unused_days' => $unusedDays->pluck('leave_date')], $actorUserId);
            $type = DB::table('hr_leave_types')->find($row->leave_type_id);
            if (!$type->paid) {
                $fact = DB::table('hr_payroll_input_facts')->where('source_type', 'leave_request')->where('source_id', $row->id)->where('status', 'staged')->first();
                if ($fact)
                    $this->payrollFact($row->company_id, $row->staff_id, 'unpaid_leave_reversal', $recallDate, -$reversedMinutes, 'leave_recall', $row->id, ['reverses_fact_id' => $fact->id, 'recall_date' => $recallDate], $actorUserId);
            }
            $this->event($row->id, 'recalled', $row->status, $row->status, $reason, $snapshot + ['recall_date' => $recallDate, 'reversed_minutes' => $reversedMinutes], $actorUserId);
            DB::table('hr_leave_requests')->where('id', $row->id)->update(['recall_date' => $recallDate, 'recalled_by' => $actorUserId, 'recalled_at' => now(), 'updated_at' => now()]);
            return DB::table('hr_leave_requests')->find($row->id);
        });
    }

    public function postBalance(string $accountId, string $entryType, int $minutes, string $effectiveDate, string $reason, string $actorUserId, string $idempotencyKey): LeaveBalanceEntry
    {
        $this->enabled();
        abort_unless(in_array($entryType, ['opening', 'accrual', 'adjustment', 'carry_forward', 'expiry', 'encashment'], true), 422, 'Unsupported balance entry type.');
        return DB::transaction(function () use ($accountId, $entryType, $minutes, $effectiveDate, $reason, $actorUserId, $idempotencyKey) {
            abort_unless(DB::table('hr_leave_balance_accounts')->where('id', $accountId)->lockForUpdate()->first(), 404);
            if ($existing = LeaveBalanceEntry::query()->where('source_type', 'manual_balance')->where('source_id', $idempotencyKey)->where('entry_type', $entryType)->first()) {
                abort_unless($existing->account_id === $accountId && (int) $existing->minutes === $minutes && $existing->effective_date->toDateString() === $effectiveDate && $existing->reason === $reason, 409, 'Balance idempotency key was reused with different evidence.');
                return $existing;
            }return $this->entry($accountId, null, $entryType, $minutes, $effectiveDate, 'manual_balance', $idempotencyKey, $reason, ['idempotency_key' => $idempotencyKey], $actorUserId);
        });
    }

    public function balance(string $accountId, string $asOf): int
    {
        return (int) DB::table('hr_leave_balance_entries')->where('account_id', $accountId)->whereDate('effective_date', '<=', $asOf)->where(fn($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $asOf))->sum('minutes');
    }
    public function postAutomatedBalance(string $accountId, string $entryType, int $minutes, string $effectiveDate, ?string $expiresOn, string $sourceType, string $sourceId, string $reason, array $snapshot, string $actorUserId): LeaveBalanceEntry
    {
        $this->enabled();
        return DB::transaction(function () use ($accountId, $entryType, $minutes, $effectiveDate, $expiresOn, $sourceType, $sourceId, $reason, $snapshot, $actorUserId) {
            abort_unless(DB::table('hr_leave_balance_accounts')->where('id', $accountId)->lockForUpdate()->first(), 404);
            if ($existing = LeaveBalanceEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('entry_type', $entryType)->first()) {
                abort_unless((int) $existing->minutes === $minutes && $existing->effective_date->toDateString() === $effectiveDate && $existing->expires_on?->toDateString() === $expiresOn, 409, 'Automated leave source was reused with different evidence.');
                return $existing;
            }return $this->entry($accountId, null, $entryType, $minutes, $effectiveDate, $sourceType, $sourceId, $reason, $snapshot, $actorUserId, $expiresOn);
        });
    }
    private function account(string $company, string $staff, string $type, string $unit): object
    {
        $row = DB::table('hr_leave_balance_accounts')->where('staff_id', $staff)->where('leave_type_id', $type)->lockForUpdate()->first();
        if ($row)
            return $row;
        $id = (string) Str::uuid();
        DB::table('hr_leave_balance_accounts')->insert(['id' => $id, 'company_id' => $company, 'staff_id' => $staff, 'leave_type_id' => $type, 'unit' => $unit, 'opened_at' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('hr_leave_balance_accounts')->find($id);
    }
    private function days(array $data, array $rules): array
    {
        $start = CarbonImmutable::parse($data['start_date']);
        $end = CarbonImmutable::parse($data['end_date']);
        $minutes = (int) ($rules['minutes_per_day'] ?? 480);
        $out = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $weekend = in_array(strtolower($day->format('l')), array_map('strtolower', $rules['weekend_days'] ?? ['saturday', 'sunday']), true);
            $holiday = DB::table('hr_work_calendar_days as d')->join('hr_work_calendars as c', 'c.id', '=', 'd.calendar_id')->where('c.company_id', $data['company_id'])->whereDate('d.calendar_date', $day)->whereIn('d.day_type', ['holiday', 'rest_day'])->exists();
            $include = (!$weekend && !$holiday) || ($rules['sandwich_rule_enabled'] ?? false);
            if ($include)
                $out[] = ['date' => $day->toDateString(), 'minutes' => $data['unit'] === 'half_day' ? (int) floor($minutes / 2) : ($data['unit'] === 'hour' ? (int) $data['requested_minutes'] : $minutes), 'kind' => $weekend || $holiday ? 'sandwich' : 'working'];
            if ($data['unit'] === 'hour')
                break;
        }
        return $out;
    }
    private function entry(string $account, ?string $request, string $type, int $minutes, string $date, string $sourceType, string $sourceId, string $reason, array $snapshot, string $actor, ?string $expiresOn = null): LeaveBalanceEntry
    {
        $payload = compact('account', 'request', 'type', 'minutes', 'date', 'sourceType', 'sourceId', 'reason', 'snapshot', 'expiresOn');
        return LeaveBalanceEntry::create(['account_id' => $account, 'leave_request_id' => $request, 'entry_type' => $type, 'minutes' => $minutes, 'effective_date' => $date, 'expires_on' => $expiresOn, 'source_type' => $sourceType, 'source_id' => $sourceId, 'reason' => $reason, 'rule_snapshot' => $snapshot, 'entry_checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'posted_by' => $actor, 'posted_at' => now()]);
    }
    private function event(string $id, string $type, ?string $from, string $to, string $reason, array $snapshot, string $actor): void
    {
        DB::table('hr_leave_request_events')->insert(['id' => (string) Str::uuid(), 'leave_request_id' => $id, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'actor_user_id' => $actor, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    private function payrollFact(string $company, string $staff, string $kind, string $date, int $minutes, string $sourceType, string $sourceId, array $snapshot, string $actor): PayrollInputFact
    {
        $payload = compact('company', 'staff', 'kind', 'date', 'minutes', 'sourceType', 'sourceId', 'snapshot');
        return PayrollInputFact::create(['company_id' => $company, 'staff_id' => $staff, 'fact_kind' => $kind, 'effective_date' => $date, 'quantity_minutes' => $minutes, 'source_type' => $sourceType, 'source_id' => $sourceId, 'status' => 'staged', 'source_snapshot' => $snapshot, 'fact_checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'created_by' => $actor]);
    }
    private function enabled(): void
    {
        abort_unless(config('hr.features.leave_overtime', false), 409, 'Leave, overtime, and timesheet writes are not enabled.');
    }
}
