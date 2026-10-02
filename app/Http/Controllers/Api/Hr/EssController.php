<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EssController extends Controller
{
    public function myRequests(Request $r): JsonResponse
    {
        $staff = $this->currentStaff($r);
        $query = DB::table('hr_request_index as i')
            ->leftJoin('staff as owner', 'owner.id', '=', 'i.current_owner_staff_id')
            ->leftJoin('users as owner_user', 'owner_user.id', '=', 'owner.user_id')
            ->where('i.requester_staff_id', $staff->id)
            ->select(['i.id', 'i.request_type', 'i.source_type', 'i.source_id', 'i.status', 'i.summary', 'i.current_owner_staff_id', 'i.due_at', 'i.result_type', 'i.result_id', 'i.submitted_at', 'i.closed_at', 'owner.code as current_owner_staff_code', 'owner_user.first_name as current_owner_first_name', 'owner_user.last_name as current_owner_last_name'])
            ->latest('i.submitted_at');

        return response()->json(['status' => 'success', 'data' => $query->paginate($r->integer('per_page', 50))]);
    }

    public function requestHistory(Request $r, string $id): JsonResponse
    {
        $staff = $this->currentStaff($r);
        $index = DB::table('hr_request_index')->where('id', $id)->where('requester_staff_id', $staff->id)->first();
        abort_unless($index, 404);
        $events = DB::table('hr_request_events')->where('request_index_id', $id)
            ->select(['id', 'event_type', 'from_status', 'to_status', 'visible_to_employee_message', 'occurred_at'])
            ->orderBy('occurred_at')->get();

        return response()->json(['status' => 'success', 'data' => ['request' => $index, 'events' => $events]]);
    }

    public function inbox(Request $r): JsonResponse
    {
        $staff = $this->currentStaff($r);
        $delegators = DB::table('hr_approval_delegations')->where('company_id', $staff->company_id)
            ->where('delegate_staff_id', $staff->id)->where('status', 'approved')
            ->whereDate('effective_from', '<=', now())->whereDate('effective_until', '>=', now())->pluck('delegator_staff_id');
        $query = DB::table('hr_request_index')->leftJoin('staff as requester_staff', 'requester_staff.id', '=', 'hr_request_index.requester_staff_id')
            ->leftJoin('users as requester_user', 'requester_user.id', '=', 'requester_staff.user_id')
            ->where('hr_request_index.company_id', $staff->company_id)
            ->whereIn('hr_request_index.status', ['pending', 'pending_approval', 'returned'])
            ->where(fn ($q) => $q->where('hr_request_index.current_owner_staff_id', $staff->id)->orWhereIn('hr_request_index.current_owner_staff_id', $delegators))
            ->select(['hr_request_index.id', 'hr_request_index.requester_staff_id', 'hr_request_index.request_type', 'hr_request_index.source_type', 'hr_request_index.source_id', 'hr_request_index.status', 'hr_request_index.summary', 'hr_request_index.current_owner_staff_id', 'hr_request_index.due_at', 'hr_request_index.submitted_at', 'requester_staff.code as requester_staff_code', DB::raw("TRIM(CONCAT_WS(' ', requester_user.first_name, requester_user.last_name)) as requester_name")])
            ->orderByRaw('hr_request_index.due_at asc nulls last');

        return response()->json(['status' => 'success', 'data' => $query->paginate($r->integer('per_page', 50))]);
    }

    public function delegate(Request $r): JsonResponse
    {
        $d = $r->validate(['delegate_staff_id' => ['required', 'uuid'], 'request_types' => ['required', 'array', 'min:1'], 'request_types.*' => ['required', 'string', 'max:60'], 'effective_from' => ['required', 'date'], 'effective_until' => ['required', 'date', 'after_or_equal:effective_from'], 'reason' => ['required', 'string', 'max:1000']]);
        $staff = $this->currentStaff($r);
        $delegate = Staff::query()->whereKey($d['delegate_staff_id'])->where('company_id', $staff->company_id)->whereNull('employment_ended_at')->firstOrFail();
        abort_if($staff->id === $delegate->id, 422, 'Self-delegation is not allowed.');
        $id = (string) Str::uuid();
        DB::table('hr_approval_delegations')->insert(['id' => $id, 'company_id' => $staff->company_id, 'delegator_staff_id' => $staff->id, 'delegate_staff_id' => $delegate->id, 'request_types' => json_encode($d['request_types'], JSON_THROW_ON_ERROR), 'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'], 'reason' => $d['reason'], 'status' => 'pending_approval', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_approval_delegations')->find($id)], 201);
    }

    public function approveDelegation(Request $r, string $id): JsonResponse
    {
        $staff = $this->currentStaff($r);

        return DB::transaction(function () use ($r, $id, $staff) {
            $row = DB::table('hr_approval_delegations')->where('id', $id)->where('company_id', $staff->company_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->created_by === $r->user()->id, 409, 'Delegator cannot approve the same delegation.');
            abort_unless($row->status === 'pending_approval', 409);
            abort_unless(Staff::query()->whereKey($row->delegate_staff_id)->where('company_id', $staff->company_id)->whereNull('employment_ended_at')->exists(), 409, 'The selected delegate is no longer active in this legal entity.');
            DB::table('hr_approval_delegations')->where('id', $id)->where('company_id', $staff->company_id)
                ->update(['status' => 'approved', 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);

            return response()->json(['status' => 'success', 'data' => DB::table('hr_approval_delegations')->find($id)]);
        });
    }

    private function currentStaff(Request $r): Staff
    {
        $contextId = (string) $r->header('X-Active-Context-Id', '');
        abort_unless($r->header('X-Active-Context-Type') === 'staff' && Str::isUuid($contextId), 403, 'Select an active Staff context.');
        $context = DB::table('user_contexts')->where('id', $contextId)->where('user_id', $r->user()->id)
            ->where('context_type', 'staff')->where('is_active', true)->whereNull('deleted_at')->first();
        abort_unless($context, 403, 'Select an active Staff context.');

        return Staff::query()->whereKey($context->context_id)->where('user_id', $r->user()->id)->whereNull('employment_ended_at')->firstOrFail();
    }
}
