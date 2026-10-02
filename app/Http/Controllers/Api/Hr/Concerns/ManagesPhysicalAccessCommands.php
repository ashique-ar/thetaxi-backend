<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
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
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'device_id' => ['nullable', 'uuid', 'exists:hr_attendance_devices,id'],
            'command_type' => ['required', Rule::in(['grant', 'revoke', 'suspend', 'restore'])],
            'access_group_code' => ['nullable', 'string', 'max:100'], 'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->authorizedCompanyId($request, $data['company_id']);
        abort_unless(Staff::query()->whereKey($data['staff_id'])->where('company_id', $data['company_id'])->exists(), 422, 'Staff and command legal entities must match.');
        if (! empty($data['device_id'])) {
            abort_unless(AttendanceDevice::query()->whereKey($data['device_id'])->where('company_id', $data['company_id'])->exists(), 422, 'Device and command legal entities must match.');
        }
        abort_unless(! in_array($data['command_type'], ['grant', 'restore'], true) || filled($data['access_group_code']), 422, 'Grant and restore commands require an access group.');
        if (filled($data['access_group_code'])) {
            abort_unless(DB::table('hr_attendance_access_groups')->where('company_id', $data['company_id'])->where('device_id', $data['device_id'])->where('code', $data['access_group_code'])->where('status', 'active')->exists(), 422, 'The selected access group is not active for this device.');
        }
        if ($existing = DB::table('hr_attendance_access_commands')->where('idempotency_key', $data['idempotency_key'])->first()) {
            abort_unless($existing->company_id === $data['company_id'] && $existing->staff_id === $data['staff_id'] && $existing->command_type === $data['command_type'], 409, 'Idempotency key was reused for a different command.');

            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $id = (string) Str::uuid();
        DB::table('hr_attendance_access_commands')->insert([
            'id' => $id, 'company_id' => $data['company_id'], 'staff_id' => $data['staff_id'], 'device_id' => $data['device_id'] ?? null,
            'command_type' => $data['command_type'], 'access_group_code' => $data['access_group_code'] ?? null, 'status' => 'pending_approval',
            'request_snapshot' => json_encode(['reason' => $data['reason'], 'requested_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR),
            'idempotency_key' => $data['idempotency_key'], 'request_checksum' => hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'requested_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_access_commands')->find($id)], 201);
    }

    public function approveAccess(Request $request, string $commandId): JsonResponse
    {
        abort_unless(config('hr.features.physical_access_commands', false), 409, 'Physical access command writes are not enabled.');

        return DB::transaction(function () use ($request, $commandId) {
            $command = DB::table('hr_attendance_access_commands')->where('id', $commandId)->lockForUpdate()->first();
            abort_unless($command, 404);
            $this->authorizedCompanyId($request, $command->company_id);
            abort_if($command->requested_by === $request->user()->id, 409, 'The requester cannot approve the same physical-access command.');
            abort_unless($command->status === 'pending_approval', 409, 'Only pending commands may be approved.');
            DB::table('hr_attendance_access_commands')->where('id', $commandId)->update([
                'status' => 'approved_pending_delivery', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_access_commands')->find($commandId)]);
        });
    }
}
