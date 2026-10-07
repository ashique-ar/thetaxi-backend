<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\StaffAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EssController extends Controller
{
    public function myRequests(Request $r): JsonResponse
    {
        $staff = $this->currentStaff($r);
        $query = DB::table('hr_request_index as i')
            ->leftJoin('staff as owner', function ($join) {
                $join->on('owner.id', '=', 'i.current_owner_staff_id')
                    ->on('owner.company_id', '=', 'i.company_id');
            })
            ->leftJoin('users as owner_user', 'owner_user.id', '=', 'owner.user_id')
            ->where('i.requester_staff_id', $staff->id)
            ->where('i.company_id', $staff->company_id)
            ->where(fn ($query) => $query->whereNull('i.current_owner_staff_id')->orWhere('owner.company_id', $staff->company_id))
            ->select(['i.id', 'i.request_type', 'i.source_type', 'i.source_id', 'i.status', 'i.summary', 'i.current_owner_staff_id', 'i.due_at', 'i.result_type', 'i.result_id', 'i.submitted_at', 'i.closed_at', 'owner.code as current_owner_staff_code', 'owner_user.first_name as current_owner_first_name', 'owner_user.last_name as current_owner_last_name'])
            ->latest('i.submitted_at');

        return response()->json(['status' => 'success', 'data' => $query->paginate($r->integer('per_page', 50))]);
    }

    public function requestHistory(Request $r, string $id): JsonResponse
    {
        $staff = $this->currentStaff($r);
        $index = DB::table('hr_request_index')->where('id', $id)->where('company_id', $staff->company_id)
            ->where('requester_staff_id', $staff->id)->first();
        abort_unless($index, 404);
        $events = DB::table('hr_request_events')->where('request_index_id', $id)
            ->select(['id', 'event_type', 'from_status', 'to_status', 'visible_to_employee_message', 'occurred_at'])
            ->orderBy('occurred_at')->get();

        return response()->json(['status' => 'success', 'data' => ['request' => $index, 'events' => $events]]);
    }

    public function inbox(Request $r): JsonResponse
    {
        $staff = $this->currentStaff($r);
        $delegations = DB::table('hr_approval_delegations as delegation')
            ->join('staff as delegator', 'delegator.id', '=', 'delegation.delegator_staff_id')
            ->join('staff as delegate', 'delegate.id', '=', 'delegation.delegate_staff_id')
            ->join('staff as approver', function ($join) { $join->on('approver.id', '=', 'delegation.approved_by_staff_id')->on('approver.user_id', '=', 'delegation.approved_by'); })
            ->where('delegation.company_id', $staff->company_id)->where('delegator.company_id', $staff->company_id)
            ->where('delegate.company_id', $staff->company_id)->where('delegate.id', $staff->id)->where('approver.company_id', $staff->company_id)
            ->whereNotNull('delegation.approved_by_staff_id')->whereNotNull('delegation.approved_at')
            ->where('delegation.status', 'approved')->whereDate('delegation.effective_from', '<=', now())
            ->whereDate('delegation.effective_until', '>=', now())->get(['delegation.delegator_staff_id', 'delegation.request_types']);
        $delegatedOwnersByType = [];
        foreach ($delegations as $delegation) {
            $requestTypes = json_decode($delegation->request_types, true);
            if (is_array($requestTypes) && in_array('leave', $requestTypes, true)) {
                $delegatedOwnersByType['leave'][$delegation->delegator_staff_id] = $delegation->delegator_staff_id;
            }
        }
        $query = DB::table('hr_request_index')->leftJoin('staff as requester_staff', 'requester_staff.id', '=', 'hr_request_index.requester_staff_id')
            ->leftJoin('users as requester_user', 'requester_user.id', '=', 'requester_staff.user_id')
            ->where('hr_request_index.company_id', $staff->company_id)
            ->where('requester_staff.company_id', $staff->company_id)
            ->whereIn('hr_request_index.status', ['pending', 'pending_approval', 'returned'])
            ->where(function ($q) use ($staff, $delegatedOwnersByType) {
                $q->where('hr_request_index.current_owner_staff_id', $staff->id);
                foreach ($delegatedOwnersByType as $type => $owners) {
                    $q->orWhere(fn ($delegated) => $delegated->where('hr_request_index.request_type', $type)->whereIn('hr_request_index.current_owner_staff_id', $owners));
                }
            })
            ->select(['hr_request_index.id', 'hr_request_index.requester_staff_id', 'hr_request_index.request_type', 'hr_request_index.source_type', 'hr_request_index.source_id', 'hr_request_index.status', 'hr_request_index.summary', 'hr_request_index.current_owner_staff_id', 'hr_request_index.due_at', 'hr_request_index.submitted_at', 'requester_staff.code as requester_staff_code', DB::raw("TRIM(CONCAT_WS(' ', requester_user.first_name, requester_user.last_name)) as requester_name")])
            ->orderByRaw('hr_request_index.due_at asc nulls last');

        return response()->json(['status' => 'success', 'data' => $query->paginate($r->integer('per_page', 50))]);
    }

    public function delegationOptions(Request $r): JsonResponse
    {
        $staff = $this->currentStaff($r);
        $data = $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim($data['search'] ?? '');
        $rows = Staff::query()->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.company_id', $staff->company_id)->where('staff.id', '!=', $staff->id)
            ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')->where('users.is_active', true)
            ->where(fn ($query) => $query->whereHas('user.permissions', fn ($permission) => $permission->where('name', 'hr.mss.approve'))
                ->orWhereHas('user.roles.permissions', fn ($permission) => $permission->where('name', 'hr.mss.approve')))
            ->where(fn ($query) => $query->whereHas('user.permissions', fn ($permission) => $permission->where('name', 'hr.leave.approve'))
                ->orWhereHas('user.roles.permissions', fn ($permission) => $permission->where('name', 'hr.leave.approve')))
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                ->where('staff.code', 'like', '%'.$search.'%')
                ->orWhere('users.first_name', 'like', '%'.$search.'%')
                ->orWhere('users.last_name', 'like', '%'.$search.'%')))
            ->orderBy('users.first_name')->orderBy('users.last_name')->orderBy('staff.code')->limit(25)
            ->get(['staff.id', 'staff.code', 'users.first_name', 'users.last_name']);

        return response()->json(['status' => 'success', 'data' => $rows->map(fn ($row) => [
            'value' => (string) $row->id,
            'label' => (trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: 'Staff member').' ('.$row->code.')',
            'status' => 'active',
        ])->values()]);
    }

    public function delegations(Request $r): JsonResponse
    {
        $staff = $this->currentStaff($r);
        $canApprove = $r->user()->can('hr.mss.delegations.approve');
        $rows = DB::table('hr_approval_delegations as delegation')
            ->join('staff as delegator', function ($join) {
                $join->on('delegator.id', '=', 'delegation.delegator_staff_id')
                    ->on('delegator.company_id', '=', 'delegation.company_id');
            })
            ->leftJoin('users as delegator_user', 'delegator_user.id', '=', 'delegator.user_id')
            ->join('staff as delegate', function ($join) {
                $join->on('delegate.id', '=', 'delegation.delegate_staff_id')
                    ->on('delegate.company_id', '=', 'delegation.company_id');
            })
            ->leftJoin('users as delegate_user', 'delegate_user.id', '=', 'delegate.user_id')
            ->where('delegation.company_id', $staff->company_id)
            ->where(function ($query) use ($staff, $canApprove): void {
                $query->where('delegation.delegator_staff_id', $staff->id)->orWhere('delegation.delegate_staff_id', $staff->id);
                if ($canApprove) $query->orWhere('delegation.status', 'pending_approval');
            })
            ->select(['delegation.id', 'delegation.request_types', 'delegation.effective_from',
                'delegation.effective_until', 'delegation.reason', 'delegation.status',
                'delegation.approved_at', 'delegation.created_at',
                'delegator.code as delegator_code', 'delegator_user.first_name as delegator_first_name',
                'delegator_user.last_name as delegator_last_name', 'delegate.code as delegate_code',
                'delegate_user.first_name as delegate_first_name', 'delegate_user.last_name as delegate_last_name'])
            ->latest('delegation.created_at')->limit(100)->get()
            ->map(function ($row): array {
                $row->request_types = json_decode($row->request_types, true) ?: [];
                $row->delegator_label = (trim(($row->delegator_first_name ?? '').' '.($row->delegator_last_name ?? '')) ?: 'Staff member')
                    .' ('.$row->delegator_code.')';
                $row->delegate_label = (trim(($row->delegate_first_name ?? '').' '.($row->delegate_last_name ?? '')) ?: 'Staff member')
                    .' ('.$row->delegate_code.')';
                unset($row->delegator_first_name, $row->delegator_last_name, $row->delegator_code,
                    $row->delegate_first_name, $row->delegate_last_name, $row->delegate_code);

                return (array) $row;
            });

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function delegate(Request $r): JsonResponse
    {
        $d = $r->validate(['delegate_staff_id' => ['required', 'uuid'], 'request_types' => ['required', 'array', 'min:1'], 'request_types.*' => ['required', Rule::in(['leave']), 'distinct'], 'effective_from' => ['required', 'date'], 'effective_until' => ['required', 'date', 'after_or_equal:effective_from'], 'reason' => ['required', 'string', 'max:1000'], 'idempotency_key' => ['required', 'uuid']]);
        $staff = $this->currentStaff($r);
        $checksum = hash('sha256', json_encode([
            'delegator_staff_id' => (string) $staff->id,
            'delegate_staff_id' => (string) $d['delegate_staff_id'],
            'request_types' => array_values($d['request_types']),
            'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'],
            'reason' => trim($d['reason']),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($d, $r, $staff, $checksum): JsonResponse {
                $actor = Staff::query()->whereKey($staff->id)->where('company_id', $staff->company_id)
                    ->whereNull('employment_ended_at')->lockForUpdate()->firstOrFail();
                $existing = DB::table('hr_approval_delegations')->where('company_id', $actor->company_id)
                    ->where('idempotency_key', $d['idempotency_key'])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                        'This delegation key was already used with different request facts.');

                    return response()->json(['status' => 'success', 'data' => [
                        'id' => $existing->id, 'status' => $existing->status,
                    ]]);
                }

                $delegate = Staff::query()->with('user')->whereKey($d['delegate_staff_id'])
                    ->where('company_id', $actor->company_id)->whereNull('deleted_at')->whereNull('employment_ended_at')
                    ->whereHas('user', fn ($user) => $user->where('is_active', true))
                    ->where(fn ($query) => $query->whereHas('user.permissions', fn ($permission) => $permission->where('name', 'hr.mss.approve'))
                        ->orWhereHas('user.roles.permissions', fn ($permission) => $permission->where('name', 'hr.mss.approve')))
                    ->where(fn ($query) => $query->whereHas('user.permissions', fn ($permission) => $permission->where('name', 'hr.leave.approve'))
                        ->orWhereHas('user.roles.permissions', fn ($permission) => $permission->where('name', 'hr.leave.approve')))
                    ->lockForUpdate()->firstOrFail();
                abort_if($actor->id === $delegate->id, 422, 'Self-delegation is not allowed.');
                $id = (string) Str::uuid();
                DB::table('hr_approval_delegations')->insert([
                    'id' => $id, 'company_id' => $actor->company_id, 'delegator_staff_id' => $actor->id,
                    'delegate_staff_id' => $delegate->id, 'request_types' => json_encode($d['request_types'], JSON_THROW_ON_ERROR),
                    'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'],
                    'reason' => trim($d['reason']), 'status' => 'pending_approval', 'created_by' => $r->user()->id,
                    'idempotency_key' => $d['idempotency_key'], 'request_payload_checksum' => $checksum,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return response()->json(['status' => 'success', 'data' => [
                    'id' => $id, 'status' => 'pending_approval',
                ]], 201);
            });
        } catch (QueryException $e) {
            $existing = DB::table('hr_approval_delegations')->where('company_id', $staff->company_id)
                ->where('idempotency_key', $d['idempotency_key'])->first();
            if (! $existing) throw $e;
            abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                'This delegation key was already used with different request facts.');

            return response()->json(['status' => 'success', 'data' => [
                'id' => $existing->id, 'status' => $existing->status,
            ]]);
        }
    }

    public function approveDelegation(Request $r, string $id): JsonResponse
    {
        $staff = $this->currentStaff($r);

        return DB::transaction(function () use ($r, $id, $staff) {
            $row = DB::table('hr_approval_delegations')->where('id', $id)->where('company_id', $staff->company_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $approver = Staff::query()->whereKey($staff->id)->where('user_id', $r->user()->id)
                ->where('company_id', $row->company_id)->whereNull('employment_ended_at')->lockForUpdate()->first();
            abort_unless($approver, 403, 'Select an active Staff approver in this legal entity.');
            abort_if($row->created_by === $r->user()->id, 409, 'Delegator cannot approve the same delegation.');
            if ($row->status === 'approved') {
                abort_unless($row->approved_by === $r->user()->id
                    && $row->approved_by_staff_id === $approver->id, 409, 'This delegation was approved by another Staff approver.');

                return response()->json(['status' => 'success', 'data' => [
                    'id' => $row->id, 'status' => $row->status, 'approved_at' => $row->approved_at,
                    'approved_by_staff_id' => $row->approved_by_staff_id,
                ]]);
            }
            abort_unless($row->status === 'pending_approval', 409);
            abort_unless(Staff::query()->whereKey($row->delegate_staff_id)->where('company_id', $staff->company_id)
                ->whereNull('deleted_at')->whereNull('employment_ended_at')->whereHas('user', fn ($user) => $user->where('is_active', true))
                ->where(fn ($query) => $query->whereHas('user.permissions', fn ($permission) => $permission->where('name', 'hr.mss.approve'))
                    ->orWhereHas('user.roles.permissions', fn ($permission) => $permission->where('name', 'hr.mss.approve')))
                ->where(fn ($query) => $query->whereHas('user.permissions', fn ($permission) => $permission->where('name', 'hr.leave.approve'))
                    ->orWhereHas('user.roles.permissions', fn ($permission) => $permission->where('name', 'hr.leave.approve')))
                ->lockForUpdate()->first(['staff.id']),
                409, 'The selected delegate is no longer active and authorized in this legal entity.');
            DB::table('hr_approval_delegations')->where('id', $id)->where('company_id', $staff->company_id)
                ->update(['status' => 'approved', 'approved_by' => $r->user()->id, 'approved_by_staff_id' => $approver->id, 'approved_at' => now(), 'updated_at' => now()]);
            activity('hr-self-service')->causedBy($r->user())->withProperties([
                'delegation_id' => $row->id, 'company_id' => $row->company_id,
                'approved_by_staff_id' => $approver->id,
            ])->log('approval_delegation_approved');

            return response()->json(['status' => 'success', 'data' => DB::table('hr_approval_delegations')
                ->where('id', $id)->first(['id', 'status', 'approved_at', 'approved_by_staff_id'])]);
        });
    }

    private function currentStaff(Request $r): Staff
    {
        $staff = app(StaffAccessService::class)->currentActorStaff($r->user());
        abort_unless(DB::table('companies')->where('id', $staff->company_id)
            ->where('is_active', true)->whereNull('deleted_at')->exists(), 403, 'Select an active Staff legal entity.');

        return $staff;
    }
}
