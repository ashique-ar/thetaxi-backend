<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Physical access-control commands (grant/revoke/suspend/restore) issued
 * against a Hikvision-family device's access groups, including the
 * request/approve maker-checker workflow.
 *
 * @see \App\Http\Controllers\Api\Hr\AttendanceDeviceController
 */
trait ManagesPhysicalAccessCommands
{
    public function accessCommands(Request $request): JsonResponse
    {
        $companyId = $this->authorizedCompanyId($request, $request->input('company_id'));
        $query = DB::table('hr_attendance_access_commands')->where('company_id', $companyId)
            ->when($request->input('status'), fn ($builder, $status) => $builder->where('status', $status))
            ->latest();

        return response()->json(['status' => 'success', 'data' => $query->paginate($request->integer('per_page', 50))]);
    }

    public function requestAccess(Request $request): JsonResponse
    {
        abort_unless(config('hr.features.physical_access_commands', false), 409, 'Physical access command writes are not enabled.');
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid'], 'staff_id' => ['required', 'uuid'],
            'device_id' => ['required', 'uuid'],
            'command_type' => ['required', Rule::in(['grant', 'revoke', 'suspend', 'restore'])],
            'access_group_code' => ['nullable', 'string', 'max:100'], 'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $data['company_id'] = $this->authorizedCompanyId($request, $data['company_id'] ?? null);
        $checksum = hash('sha256', json_encode(['request' => $data, 'requested_by' => $request->user()->id], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($request, $data, $checksum) {
                if ($existing = $this->matchingAccessCommand($data['idempotency_key'], $checksum, $request->user()->id)) {
                    return response()->json(['status' => 'success', 'data' => $existing]);
                }

                $staff = Staff::withTrashed()->whereKey($data['staff_id'])->where('company_id', $data['company_id'])->lockForUpdate()->first();
                abort_unless($staff, 404);
                abort_if(in_array($data['command_type'], ['grant', 'restore'], true) && ($staff->trashed() || filled($staff->employment_ended_at)), 409, 'Former Staff cannot receive physical access.');
                $device = AttendanceDevice::query()->where('company_id', $data['company_id'])->find($data['device_id']);
                abort_unless($device, 404);
                abort_unless(! in_array($data['command_type'], ['grant', 'restore'], true) || filled($data['access_group_code']), 422, 'Grant and restore commands require an access group.');
                if (filled($data['access_group_code'])) {
                    abort_unless(DB::table('hr_attendance_access_groups')->where('company_id', $data['company_id'])->where('device_id', $data['device_id'])->where('code', $data['access_group_code'])->where('status', 'active')->exists(), 422, 'The selected access group is not active for this device.');
                }
                $id = (string) Str::uuid();
                DB::table('hr_attendance_access_commands')->insert([
                    'id' => $id, 'company_id' => $data['company_id'], 'staff_id' => $data['staff_id'], 'device_id' => $data['device_id'] ?? null,
                    'command_type' => $data['command_type'], 'access_group_code' => $data['access_group_code'] ?? null, 'status' => 'pending_approval',
                    'request_snapshot' => json_encode(['reason' => $data['reason'], 'requested_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR),
                    'idempotency_key' => $data['idempotency_key'], 'request_checksum' => $checksum, 'requested_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_access_commands')->find($id)], 201);
            });
        } catch (QueryException $e) {
            if (! in_array($e->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw $e;
            }
            $existing = $this->matchingAccessCommand($data['idempotency_key'], $checksum, $request->user()->id);
            if ($existing) {
                return response()->json(['status' => 'success', 'data' => $existing]);
            }
            throw $e;
        }
    }

    public function approveAccess(Request $request, string $commandId): JsonResponse
    {
        abort_unless(config('hr.features.physical_access_commands', false), 409, 'Physical access command writes are not enabled.');

        return DB::transaction(function () use ($request, $commandId) {
            $command = DB::table('hr_attendance_access_commands')->where('id', $commandId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->lockForUpdate()->first();
            abort_unless($command, 404);
            $companyId = $command->company_id;
            abort_if($command->requested_by === $request->user()->id, 409, 'The requester cannot approve the same physical-access command.');
            if ($command->approved_by === $request->user()->id && $command->status !== 'pending_approval') {
                return response()->json(['status' => 'success', 'data' => $command]);
            }
            abort_unless($command->status === 'pending_approval', 409, 'Only pending commands may be approved.');
            if (in_array($command->command_type, ['grant', 'restore'], true)) {
                $staff = Staff::query()->whereKey($command->staff_id)->where('company_id', $companyId)->lockForUpdate()->firstOrFail();
                abort_if(filled($staff->employment_ended_at), 409, 'Former Staff cannot receive physical access.');
            }
            DB::table('hr_attendance_access_commands')->where('id', $commandId)->where('company_id', $companyId)->update([
                'status' => 'approved_pending_delivery', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_access_commands')->find($commandId)]);
        });
    }

    private function matchingAccessCommand(string $key, string $checksum, string $actorId): ?object
    {
        $existing = DB::table('hr_attendance_access_commands')->where('idempotency_key', $key)->first();
        abort_unless(! $existing || ($existing->requested_by === $actorId && hash_equals((string) $existing->request_checksum, $checksum)), 409, 'Idempotency key was reused with different command evidence.');

        return $existing;
    }
}
