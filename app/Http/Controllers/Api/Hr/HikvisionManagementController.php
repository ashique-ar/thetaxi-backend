<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Hikvision-family device *lifecycle and operations* management: access
 * groups/doors, live diagnostics, clock/time configuration, "safe settings"
 * verification, credential rotation, device alerts, and reboot/maintenance
 * approval workflows.
 *
 * See {@see \App\Http\Controllers\Api\Hr\AttendanceDeviceController} for the
 * complementary device half — device/connector CRUD, person-mapping (single
 * and bulk), card/PIN credential issuance, raw-event/quarantine review, and
 * manual/automatic attendance sync. That controller owns a device's identity
 * and its Staff mapping; this controller owns what happens to an already
 * -registered device over time (its physical access configuration, health,
 * and maintenance).
 */
class HikvisionManagementController extends Controller
{
    public function groups(Request $r): JsonResponse
    {
        $company = $this->company($r);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_access_groups')->where('company_id', $company)->orderBy('name')->get()]);
    }

    public function storeGroup(Request $r): JsonResponse
    {
        $d = $r->validate(['device_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'door_no' => ['required', 'integer', 'min:1'], 'plan_template_no' => ['required', 'integer', 'min:1'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']]);
        $device = $this->device($r, $d['device_id']);
        $id = (string) Str::uuid();
        DB::table('hr_attendance_access_groups')->insert($d + ['id' => $id, 'company_id' => $device->company_id, 'status' => 'active', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_access_groups')->find($id)], 201);
    }

    public function diagnostics(Request $r, string $id, AttendanceProviderManager $p): JsonResponse
    {
        $device = $this->device($r, $id);
        $adapter = $p->adapterFor($device);
        $time = $adapter->timeFacts($device);
        $settings = $adapter->safeSettings($device);
        $access = $adapter->accessCapabilities($device);
        $maintenance = $adapter->maintenanceCapabilities($device);
        $cap = array_merge((array) $device->capabilities, ['physical_access' => $access, 'maintenance' => $maintenance, 'device_configuration' => ['time' => true, 'safe_settings' => array_keys($settings)]]);
        $device->update(['capabilities' => $cap]);
        $alerts = DB::table('hr_attendance_device_alerts')->where('device_id', $id)->latest('detected_at')->limit(50)->get();

        return response()->json(['status' => 'success', 'data' => ['time' => $time, 'settings' => $settings, 'access_capabilities' => $access, 'maintenance_capabilities' => $maintenance, 'alerts' => $alerts]]);
    }

    public function updateTime(Request $r, string $id, AttendanceProviderManager $p): JsonResponse
    {
        $d = $r->validate(['mode' => ['required', Rule::in(['NTP', 'manual'])], 'timezone' => ['required', 'string', 'max:100'], 'ntp_host' => ['nullable', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000']]);
        $device = $this->device($r, $id);
        abort_unless((bool) data_get($device->capabilities, 'device_configuration.time'), 409, 'Run diagnostics and verify time capability before changing the clock.');
        $before = $p->adapterFor($device)->timeFacts($device);
        $result = $p->adapterFor($device)->setTimeConfiguration($device, $d['mode'], $d['timezone'], $d['ntp_host'] ?? null);
        $this->event($r, $device, 'time_configuration', $before, $result, $d['reason']);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function updateSettings(Request $r, string $id, AttendanceProviderManager $p): JsonResponse
    {
        $d = $r->validate(['settings' => ['required', 'array'], 'settings.showEmployeeNo' => ['nullable', 'boolean'], 'settings.showName' => ['nullable', 'boolean'], 'settings.desensitiseEmployeeNo' => ['nullable', 'boolean'], 'settings.desensitiseName' => ['nullable', 'boolean'], 'settings.buzzerEnabled' => ['nullable', 'boolean'], 'reason' => ['required', 'string', 'max:2000']]);
        $device = $this->device($r, $id);
        abort_unless((bool) data_get($device->capabilities, 'device_configuration.safe_settings'), 409, 'Run diagnostics and verify safe settings capability before changing terminal settings.');
        $before = $p->adapterFor($device)->safeSettings($device);
        $result = $p->adapterFor($device)->setSafeSettings($device, $d['settings']);
        $this->event($r, $device, 'safe_settings', $before, $result, $d['reason']);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function rotateCredentials(Request $r, string $id, AttendanceProviderManager $p): JsonResponse
    {
        $d = $r->validate(['username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000']]);
        $device = $this->device($r, $id);
        abort_unless((bool) data_get($device->capabilities, 'maintenance.reboot'), 409, 'Run diagnostics and verify reboot capability for this exact terminal firmware.');
        $old = (array) $device->encrypted_configuration;
        $candidate = $device->replicate();
        $candidate->id = $device->id;
        $candidate->encrypted_configuration = array_merge($old, ['username' => $d['username'], 'password' => $d['password']]);
        $facts = $p->adapterFor($candidate)->discover($candidate);
        abort_unless(hash_equals($device->serial_number, $facts['serial_number']), 409, 'New credentials reached a different terminal identity.');
        try {
            $device->update(['encrypted_configuration' => (array) $candidate->encrypted_configuration]);
            $verified = $p->adapterFor($device->fresh())->discover($device->fresh());
            abort_unless(hash_equals($device->serial_number, $verified['serial_number']), 409, 'Credential re-test failed terminal identity verification.');
        } catch (\Throwable $e) {
            $device->update(['encrypted_configuration' => $old]);
            report($e);
            abort(502, 'New credentials failed after save and the previous encrypted configuration was restored.');
        }
        $this->event($r, $device, 'credential_rotation', ['configured' => true], ['configured' => true, 'serial_verified' => true], $d['reason']);

        return response()->json(['status' => 'success', 'data' => ['rotated' => true, 'serial_verified' => true]]);
    }

    public function alerts(Request $r): JsonResponse
    {
        $company = $this->company($r);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_device_alerts')->where('company_id', $company)->latest('detected_at')->paginate($r->integer('per_page', 50))]);
    }

    public function resolveAlert(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['note' => ['required', 'string', 'max:2000']]);
        $company = $this->company($r);
        $row = DB::table('hr_attendance_device_alerts')->where('id', $id)->where('company_id', $company)->first();
        abort_unless($row, 404);
        DB::table('hr_attendance_device_alerts')->where('id', $id)->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $r->user()->id, 'resolution_note' => $d['note'], 'updated_at' => now()]);

        return response()->json(['status' => 'success']);
    }

    public function retryCommand(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['reason' => ['required', 'string', 'max:2000']]);
        $company = $this->company($r);
        $row = DB::table('hr_attendance_access_commands')->where('id', $id)->where('company_id', $company)->first();
        abort_unless($row && in_array($row->status, ['dead_letter', 'retry_pending'], true), 409, 'Only failed access commands can be retried.');
        DB::table('hr_attendance_access_commands')->where('id', $id)->update(['status' => 'retry_pending', 'next_attempt_at' => now(), 'failure_message' => 'Manual retry: '.$d['reason'], 'updated_at' => now()]);

        return response()->json(['status' => 'success']);
    }

    public function maintenanceCommands(Request $r): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_device_maintenance_commands')->where('company_id', $this->company($r))->latest('requested_at')->paginate($r->integer('per_page', 50))]);
    }

    public function requestReboot(Request $r, string $id): JsonResponse
    {
        abort_unless(config('hr.features.hikvision_maintenance_commands'), 409, 'Restricted Hikvision maintenance is not enabled for this deployment.');
        $data = $r->validate(['reason' => ['required', 'string', 'max:2000'], 'typed_confirmation' => ['required', 'string', 'max:160'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        $device = $this->device($r, $id);
        abort_unless(hash_equals($device->site_code, $data['typed_confirmation']), 422, 'Type the exact device site code to confirm the reboot request.');
        $probeAt = data_get($device->capabilities, 'last_identity_probe_at');
        abort_unless($probeAt && CarbonImmutable::parse($probeAt)->gte(now()->subMinutes(10)), 409, 'Run a successful device identity test within ten minutes before requesting reboot.');
        if ($existing = DB::table('hr_attendance_device_maintenance_commands')->where('company_id', $device->company_id)->where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $commandId = (string) Str::uuid();
        DB::table('hr_attendance_device_maintenance_commands')->insert(['id' => $commandId, 'company_id' => $device->company_id, 'device_id' => $device->id, 'command_type' => 'reboot', 'status' => 'pending_approval', 'reason' => $data['reason'], 'typed_confirmation' => $data['typed_confirmation'], 'idempotency_key' => $data['idempotency_key'], 'requested_by' => $r->user()->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_device_maintenance_commands')->find($commandId)], 201);
    }

    public function approveReboot(Request $r, string $id): JsonResponse
    {
        abort_unless(config('hr.features.hikvision_maintenance_commands'), 409, 'Restricted Hikvision maintenance is not enabled for this deployment.');
        return DB::transaction(function () use ($r, $id) {
            $command = DB::table('hr_attendance_device_maintenance_commands')->where('id', $id)->lockForUpdate()->first();
            abort_unless($command && $command->company_id === $this->company($r), 404);
            abort_if($command->requested_by === $r->user()->id, 409, 'The reboot requester cannot approve the same command.');
            abort_unless($command->status === 'pending_approval', 409, 'Only a pending reboot may be approved.');
            $device = AttendanceDevice::query()->findOrFail($command->device_id);
            abort_unless((bool) data_get($device->capabilities, 'maintenance.reboot'), 409, 'Reboot capability is no longer verified for this terminal.');
            $probeAt = data_get($device->capabilities, 'last_identity_probe_at');
            abort_unless($probeAt && CarbonImmutable::parse($probeAt)->gte(now()->subMinutes(10)), 409, 'Repeat the device identity test before approving reboot.');
            DB::table('hr_attendance_device_maintenance_commands')->where('id', $id)->update(['status' => 'approved_pending_execution', 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);

            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_device_maintenance_commands')->find($id)]);
        });
    }

    private function company(Request $r): string
    {
        $id = Staff::query()->where('user_id', $r->user()->id)->value('company_id');
        abort_unless($id, 403);

        return $id;
    }

    private function device(Request $r, string $id): AttendanceDevice
    {
        $d = AttendanceDevice::query()->find($id);
        abort_unless($d, 404);
        abort_unless($d->company_id === $this->company($r), 403);

        return $d;
    }

    private function event(Request $r, AttendanceDevice $d, string $action, array $before, array $after, string $reason): void
    {
        DB::table('hr_attendance_device_config_events')->insert(['id' => (string) Str::uuid(), 'company_id' => $d->company_id, 'device_id' => $d->id, 'action' => $action, 'before_checksum' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)), 'after_checksum' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)), 'safe_snapshot' => json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR), 'status' => 'completed', 'reason' => $reason, 'actor_user_id' => $r->user()->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
