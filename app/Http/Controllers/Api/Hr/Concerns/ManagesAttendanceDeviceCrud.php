<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use App\Services\Hr\Attendance\DirectAttendanceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Connector/device registration, identity-probing, and manual/automatic sync
 * orchestration for Hikvision-family attendance terminals.
 *
 * @see \App\Http\Controllers\Api\Hr\AttendanceDeviceController
 */
trait ManagesAttendanceDeviceCrud
{
    public function probe(Request $request, string $deviceId, AttendanceProviderManager $providers): JsonResponse
    {
        $this->requireAttendanceWrites();
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        abort_unless($device->status === 'active', 409, 'Only an active attendance device can be tested.');

        try {
            $adapter = $providers->adapterFor($device);
            $facts = $adapter->discover($device);
            $credentialCapabilities = $adapter->credentialCapabilities($device);
            abort_unless(hash_equals($device->serial_number, $facts['serial_number']), 409, 'The configured endpoint belongs to a different Hikvision device.');
            $device->update([
                'model' => $facts['model'] ?: $device->model,
                'firmware' => $facts['firmware'] ?: $device->firmware,
                'capabilities' => array_merge((array) $device->capabilities, $facts['capabilities'], [
                    'manufacturer' => $facts['manufacturer'],
                    'device_type' => $facts['device_type'],
                    'card_management' => $credentialCapabilities['cards'],
                    'pin_management' => $credentialCapabilities['pin'],
                    'last_identity_probe_at' => now()->toIso8601String(),
                ]),
                'last_sync_at' => now(),
            ]);
        } catch (ConnectionException $exception) {
            report($exception);
            abort(503, 'The Hikvision terminal could not be reached. Check its IP, port, network route, and power.');
        } catch (RequestException $exception) {
            report($exception);
            abort($exception->response->status() === 401 ? 401 : 502, $exception->response->status() === 401 ? 'Hikvision authentication failed.' : 'Hikvision rejected the ISAPI device-information request.');
        }

        return response()->json(['status' => 'success', 'data' => [
            'device' => $device->fresh()->makeHidden('encrypted_configuration'),
            'connection' => 'verified',
        ]]);
    }

    public function sync(Request $request, string $deviceId, DirectAttendanceSyncService $sync): JsonResponse
    {
        $this->requireAttendanceWrites();
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:31']]);
        $to = CarbonImmutable::now();
        $days = (int) ($data['days'] ?? 2);

        return response()->json(['status' => 'success', 'data' => $sync->sync($device, $to->subDays($days), $to, 'manual_reconciliation')]);
    }

    public function syncRuns(Request $request): JsonResponse
    {
        $companyId = $this->authorizedCompanyId($request, $request->input('company_id'));
        $runs = DB::table('hr_attendance_sync_runs')->where('company_id', $companyId)->latest('started_at')->paginate($request->integer('per_page', 50));

        return response()->json(['status' => 'success', 'data' => $runs]);
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->authorizedCompanyId($request, $request->input('company_id'));
        $devices = AttendanceDevice::query()
            ->where('company_id', $companyId)
            ->with('connector:id,name,status,last_heartbeat_at')
            ->orderBy('site_code')
            ->get();

        return response()->json(['status' => 'success', 'data' => $devices]);
    }

    public function health(Request $request): JsonResponse
    {
        $companyId = $this->authorizedCompanyId($request, $request->input('company_id'));
        $connectors = DB::table('hr_attendance_connectors')
            ->where('company_id', $companyId)
            ->select(['id', 'name', 'topology', 'status', 'last_heartbeat_at', 'capabilities'])
            ->get();
        // Connectivity fields (ip_address/port/username) are safe to return to any actor who
        // can already see this device's site/serial/model; the encrypted `password` sub-key
        // never round-trips back over the wire once set, matching the connector signing_secret's
        // existing "shown once at creation, never again" convention.
        $devices = AttendanceDevice::query()->where('company_id', $companyId)
            ->get(['id', 'company_id', 'connector_id', 'provider', 'integration_mode', 'model', 'serial_number', 'site_code', 'timezone', 'status', 'last_sync_at', 'last_event_at', 'encrypted_configuration'])
            ->map(function (AttendanceDevice $device) {
                $connection = collect($device->encrypted_configuration ?? [])->only(['ip_address', 'port', 'username'])->all();

                return $device->makeHidden('encrypted_configuration')->toArray() + ['connection' => $connection];
            });
        $counts = DB::table('hr_attendance_quarantine_items')
            ->where('company_id', $companyId)
            ->where('status', 'open')
            ->selectRaw('reason_code, count(*) as total')
            ->groupBy('reason_code')
            ->pluck('total', 'reason_code');

        $latestRun = DB::table('hr_attendance_sync_runs')->where('company_id', $companyId)->latest('started_at')->first();
        $latestCompletedAt = $latestRun?->finished_at ?? $latestRun?->started_at;
        $automationEnabled = (bool) config('hr.features.attendance_ingestion');
        $automationHealthy = $automationEnabled && $latestCompletedAt && CarbonImmutable::parse($latestCompletedAt)->gte(now()->subMinutes(15)) && $latestRun->status === 'completed';

        return response()->json(['status' => 'success', 'data' => [
            'connectors' => $connectors,
            'devices' => $devices,
            'open_quarantine_counts' => $counts,
            'automation' => [
                'enabled' => $automationEnabled,
                'healthy' => $automationHealthy,
                'expected_interval_minutes' => 5,
                'latest_run_status' => $latestRun?->status,
                'latest_run_at' => $latestCompletedAt,
                'message' => ! $automationEnabled
                    ? 'Automatic Hikvision synchronization is disabled.'
                    : ($automationHealthy ? 'Automatic synchronization is running normally.' : 'Automatic synchronization is overdue. Confirm that the Laravel scheduler is running.'),
            ],
        ]]);
    }

    public function storeConnector(Request $request): JsonResponse
    {
        $this->requireAttendanceWrites();
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'topology' => ['required', Rule::in(['direct_isapi', 'hikcentral', 'provider_push', 'approved_csv', 'local_connector'])],
            'allowed_ip_cidrs' => ['nullable', 'string', 'max:2000'],
            'capabilities' => ['nullable', 'array'],
        ]);
        $this->authorizedCompanyId($request, $data['company_id']);
        $secret = bin2hex(random_bytes(32));
        $connector = AttendanceConnector::create($data + [
            'connector_key' => 'ATC-'.strtoupper(Str::random(24)),
            'signing_secret' => $secret,
            'status' => 'active',
            'created_user_id' => $request->user()->id,
        ]);

        return response()->json(['status' => 'success', 'data' => [
            'connector' => $connector,
            'signing_secret' => $secret,
            'secret_display' => 'one_time_only',
        ]], 201);
    }

    public function storeDevice(Request $request, AttendanceProviderManager $providers): JsonResponse
    {
        $this->requireAttendanceWrites();
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'connector_id' => ['nullable', 'uuid', 'exists:hr_attendance_connectors,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:hr_organization_units,id'],
            'provider' => ['required', 'string', 'max:60'],
            'integration_mode' => ['required', Rule::in(['direct_isapi', 'hikcentral', 'provider_push', 'approved_csv', 'local_connector'])],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:160'],
            'firmware' => ['nullable', 'string', 'max:100'],
            'site_code' => ['required', 'string', 'max:80'],
            'timezone' => ['required', 'timezone'],
            'capabilities' => ['nullable', 'array'],
            'encrypted_configuration' => ['nullable', 'array'],
        ]);
        $this->authorizedCompanyId($request, $data['company_id']);
        if (! empty($data['connector_id'])) {
            abort_unless(AttendanceConnector::query()->whereKey($data['connector_id'])->where('company_id', $data['company_id'])->exists(), 422, 'Connector and device legal entities must match.');
        }
        if (! empty($data['organization_unit_id'])) {
            abort_unless(DB::table('hr_organization_units')->where('id', $data['organization_unit_id'])->where('company_id', $data['company_id'])->exists(), 422, 'Organization unit and device legal entities must match.');
        }

        if ($data['integration_mode'] === 'direct_isapi') {
            $candidate = new AttendanceDevice();
            $candidate->forceFill($data + ['status' => 'active']);
            try {
                $adapter = $providers->adapterFor($candidate);
                $facts = $adapter->discover($candidate);
                $credentialCapabilities = $adapter->credentialCapabilities($candidate);
            } catch (ConnectionException $exception) {
                report($exception);
                abort(503, 'The Hikvision terminal could not be reached. Check its IP, port, network route, and power.');
            } catch (RequestException $exception) {
                report($exception);
                abort($exception->response->status() === 401 ? 401 : 502, $exception->response->status() === 401
                    ? 'Hikvision authentication failed.'
                    : 'Hikvision rejected the ISAPI device-information request.');
            }

            abort_if(
                ! empty($data['serial_number']) && ! hash_equals((string) $data['serial_number'], (string) $facts['serial_number']),
                409,
                'The entered serial does not match the terminal at this address.'
            );
            $data['serial_number'] = $facts['serial_number'];
            $data['model'] = $facts['model'] ?: ($data['model'] ?? null);
            $data['firmware'] = $facts['firmware'] ?: ($data['firmware'] ?? null);
            $data['capabilities'] = array_merge((array) ($data['capabilities'] ?? []), $facts['capabilities'], [
                'manufacturer' => $facts['manufacturer'],
                'device_type' => $facts['device_type'],
                'card_management' => $credentialCapabilities['cards'],
                'pin_management' => $credentialCapabilities['pin'],
                'last_identity_probe_at' => now()->toIso8601String(),
            ]);
            $data['last_sync_at'] = now();
        }

        abort_unless(! empty($data['serial_number']), 422, 'This attendance provider requires a serial number.');

        $device = AttendanceDevice::create($data + ['status' => 'active', 'created_user_id' => $request->user()->id]);

        return response()->json(['status' => 'success', 'data' => $device], 201);
    }

    /**
     * §5.4/QH2-01: storeDevice()/storeConnector() were create-only — a device's IP/port/
     * credentials (carried in the encrypted_configuration blob, since a direct_isapi device
     * has no dedicated ip_address column by design — different topologies need different
     * config shapes) could never be changed after registration, and neither had any Angular
     * surface at all. This closes the device half: a device's local-network address changes
     * over time (DHCP lease renewal, re-IP, physical move) and an admin needs to update it
     * without re-registering the whole device and losing its raw-event/mapping history.
     */
    public function updateDevice(Request $request, string $deviceId): JsonResponse
    {
        $this->requireAttendanceWrites();
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        $data = $request->validate([
            'connector_id' => ['nullable', 'uuid', 'exists:hr_attendance_connectors,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:hr_organization_units,id'],
            'model' => ['nullable', 'string', 'max:120'],
            'firmware' => ['nullable', 'string', 'max:100'],
            'site_code' => ['required', 'string', 'max:80'],
            'timezone' => ['required', 'timezone'],
            'capabilities' => ['nullable', 'array'],
            'encrypted_configuration' => ['nullable', 'array'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        if (! empty($data['connector_id'])) {
            abort_unless(AttendanceConnector::query()->whereKey($data['connector_id'])->where('company_id', $device->company_id)->exists(), 422, 'Connector and device legal entities must match.');
        }
        if (! empty($data['organization_unit_id'])) {
            abort_unless(DB::table('hr_organization_units')->where('id', $data['organization_unit_id'])->where('company_id', $device->company_id)->exists(), 422, 'Organization unit and device legal entities must match.');
        }
        // encrypted_configuration (which may hold the ISAPI password) is never sent back to the
        // client by health()/index(), so a client-supplied value only ever carries the fields the
        // admin actually changed. Merge onto the existing decrypted config rather than replacing
        // it outright, or editing the IP alone would silently erase an already-stored password.
        if (array_key_exists('encrypted_configuration', $data)) {
            $data['encrypted_configuration'] = array_filter((array) $device->encrypted_configuration, fn ($v, $k) => ! array_key_exists($k, $data['encrypted_configuration']), ARRAY_FILTER_USE_BOTH) + $data['encrypted_configuration'];
        }
        $device->update($data);

        return response()->json(['status' => 'success', 'data' => $device->fresh()]);
    }

    public function destroyDevice(Request $request, string $deviceId): JsonResponse
    {
        $this->requireAttendanceWrites();
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);

        // Soft deletion removes the terminal from operational selection and all
        // schedulers while retaining its UUID for raw events, mappings, access
        // commands, configuration evidence, and audit reconstruction.
        $device->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Attendance terminal removed. Historical evidence was retained.',
        ]);
    }
}
