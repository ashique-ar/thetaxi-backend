<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use App\Services\Hr\Attendance\DirectAttendanceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttendanceDeviceController extends Controller
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

    public function devicePeople(Request $request, string $deviceId, AttendanceProviderManager $providers): JsonResponse
    {
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        $adapter = $providers->adapterFor($device);
        $position = 0;
        $people = [];
        do {
            $page = $adapter->people($device, $position, 30);
            $people = array_merge($people, $page['people']);
            $position = $page['next_position'];
        } while ($page['has_more']);
        $today = CarbonImmutable::now($device->timezone)->toDateString();
        $mappings = DB::table('hr_attendance_person_mappings as mapping')
            ->join('staff', 'staff.id', '=', 'mapping.staff_id')
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->where('mapping.company_id', $device->company_id)
            ->where(fn ($query) => $query->where('mapping.device_id', $device->id)->orWhereNull('mapping.device_id'))
            ->whereDate('mapping.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('mapping.effective_until')->orWhereDate('mapping.effective_until', '>', $today))
            ->orderByRaw('case when mapping.device_id = ? then 0 else 1 end', [$device->id])
            ->orderByDesc('mapping.effective_from')
            ->select('mapping.*', 'staff.code as staff_code', 'users.first_name', 'users.last_name', 'users.email')
            ->get()
            ->unique('provider_person_id')
            ->keyBy('provider_person_id');
        $dispositions = DB::table('hr_attendance_identity_dispositions')->where('device_id', $device->id)->get()->keyBy('provider_person_id');
        $data = collect($people)->map(fn ($person) => $person + ['mapping' => $mappings->get($person['employee_no']), 'disposition' => $dispositions->get($person['employee_no'])])->values();

        return response()->json(['status' => 'success', 'data' => ['people' => $data, 'total' => count($people)]]);
    }

    public function provisionDevicePerson(Request $request, string $deviceId, AttendanceProviderManager $providers): JsonResponse
    {
        $this->requireAttendanceWrites();
        $data = $request->validate(['staff_id' => ['required', 'uuid']]);
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        abort_unless($device->status === 'active' && $device->integration_mode === 'direct_isapi', 409, 'Only an active direct-ISAPI device can receive Staff users.');
        $staff = Staff::query()->with('user:id,first_name,last_name')->whereKey($data['staff_id'])->where('company_id', $device->company_id)->whereNull('employment_ended_at')->firstOrFail();
        abort_unless(filled($staff->code), 422, 'Set the Staff employee code before provisioning the Hikvision user.');
        abort_unless(preg_match('/^[A-Za-z0-9._-]{1,32}$/', $staff->code) === 1, 422, 'The Staff employee code is not supported by this Hikvision terminal.');
        $name = trim(($staff->user?->first_name ?? '').' '.($staff->user?->last_name ?? '')) ?: $staff->code;

        try {
            $person = $providers->adapterFor($device)->provisionPerson($device, $staff->code, $name);
        } catch (ConnectionException $exception) {
            report($exception);
            abort(503, 'The Hikvision terminal could not be reached while creating the Staff user.');
        } catch (RequestException $exception) {
            report($exception);
            abort($exception->response->status() === 401 ? 401 : 502, $exception->response->status() === 401 ? 'Hikvision authentication failed.' : 'Hikvision rejected the Staff user provisioning request.');
        }

        return response()->json(['status' => 'success', 'data' => $person], $person['created'] ? 201 : 200);
    }

    public function updateDevicePerson(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        $this->requireAttendanceWrites();
        $data = $request->validate([
            'staff_id' => ['required', 'uuid'],
            'enabled' => ['required', 'boolean'],
            'display_name' => ['nullable', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        abort_unless($device->status === 'active' && $device->integration_mode === 'direct_isapi', 409, 'Only an active direct-ISAPI device can synchronize Staff users.');
        $staff = Staff::query()->with('user:id,first_name,last_name')->whereKey($data['staff_id'])->where('company_id', $device->company_id)->firstOrFail();
        $mappingExists = DB::table('hr_attendance_person_mappings')
            ->where('company_id', $device->company_id)
            ->where('staff_id', $staff->id)
            ->where('provider_person_id', $employeeNumber)
            ->where('enrollment_status', 'verified')
            ->where(fn ($query) => $query->where('device_id', $device->id)->orWhereNull('device_id'))
            ->exists();
        abort_unless($mappingExists, 422, 'A verified Staff-to-terminal mapping is required before synchronizing this user.');
        abort_if($data['enabled'] && filled($staff->employment_ended_at), 422, 'Former Staff cannot be enabled on an attendance terminal.');
        $canonicalName = trim(($staff->user?->first_name ?? '').' '.($staff->user?->last_name ?? '')) ?: $staff->code;
        $name = trim((string) ($data['display_name'] ?? '')) ?: $canonicalName;

        try {
            $adapter = $providers->adapterFor($device);
            $before = $this->terminalPerson($adapter, $device, $employeeNumber);
            $person = $adapter->updatePerson($device, $employeeNumber, $name, (bool) $data['enabled']);
            $after = $this->terminalPerson($adapter, $device, $employeeNumber);
            abort_unless($after && $after['display_name'] === $name && (bool) $after['enabled'] === (bool) $data['enabled'], 502, 'Hikvision accepted the update but post-write reconciliation did not match.');
            DB::table('hr_attendance_device_config_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $device->company_id, 'device_id' => $device->id,
                'action' => 'terminal_person_update', 'before_checksum' => hash('sha256', json_encode($before)),
                'after_checksum' => hash('sha256', json_encode($after)),
                'safe_snapshot' => json_encode(['employee_number' => $employeeNumber, 'before_name' => $before['display_name'] ?? null, 'after_name' => $after['display_name'], 'enabled' => $after['enabled']]),
                'status' => 'completed', 'reason' => $data['reason'], 'actor_user_id' => $request->user()->id,
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (ConnectionException $exception) {
            report($exception);
            abort(503, 'The Hikvision terminal could not be reached while synchronizing the Staff user.');
        } catch (RequestException $exception) {
            report($exception);
            abort($exception->response->status() === 401 ? 401 : 502, $exception->response->status() === 401 ? 'Hikvision authentication failed.' : 'Hikvision rejected the Staff user update.');
        }

        return response()->json(['status' => 'success', 'data' => $person]);
    }

    public function updateDevicePersonStatus(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        $this->requireAttendanceWrites();
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000']]);
        [$device, $mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        $staff = Staff::query()->findOrFail($mapping->staff_id);
        abort_if($data['enabled'] && filled($staff->employment_ended_at), 422, 'Former Staff cannot be enabled on an attendance terminal.');

        try {
            $adapter = $providers->adapterFor($device);
            $before = $this->terminalPerson($adapter, $device, $employeeNumber);
            $result = $adapter->setPersonEnabled($device, $employeeNumber, (bool) $data['enabled']);
            $after = $this->terminalPerson($adapter, $device, $employeeNumber);
            abort_unless($after && (bool) $after['enabled'] === (bool) $data['enabled'], 502, 'Hikvision accepted the status update but post-write reconciliation did not match.');
            abort_unless(($before['display_name'] ?? null) === ($after['display_name'] ?? null), 502, 'The terminal display name changed during a status-only update.');
            DB::table('hr_attendance_device_config_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $device->company_id, 'device_id' => $device->id,
                'action' => 'terminal_person_status_update', 'before_checksum' => hash('sha256', json_encode($before)),
                'after_checksum' => hash('sha256', json_encode($after)),
                'safe_snapshot' => json_encode(['employee_number' => $employeeNumber, 'display_name' => $after['display_name'] ?? null, 'enabled' => $after['enabled']]),
                'status' => 'completed', 'reason' => $data['reason'], 'actor_user_id' => $request->user()->id,
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (ConnectionException $exception) {
            report($exception);
            abort(503, 'The Hikvision terminal could not be reached while changing user status.');
        } catch (RequestException $exception) {
            report($exception);
            abort($exception->response->status() === 401 ? 401 : 502, $exception->response->status() === 401 ? 'Hikvision authentication failed.' : 'Hikvision rejected the status update.');
        }

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function credentials(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        $cards = collect($providers->adapterFor($device)->cards($device, $employeeNumber))->map(fn ($card) => [
            'fingerprint' => $this->credentialFingerprint($card['card_number']),
            'masked_reference' => $this->maskCard($card['card_number']),
            'card_type' => $card['card_type'],
        ])->values();
        $history = DB::table('hr_attendance_credential_events')->where('device_id', $device->id)->where('provider_person_id', $employeeNumber)->latest('occurred_at')->limit(50)->get(['id', 'credential_type', 'action', 'masked_reference', 'status', 'reason', 'actor_user_id', 'occurred_at']);

        return response()->json(['status' => 'success', 'data' => ['mapping' => $mapping, 'cards' => $cards, 'history' => $history, 'pin' => ['configured' => $history->contains(fn ($row) => $row->credential_type === 'pin' && $row->action === 'set' && $row->status === 'delivered')]]]);
    }

    public function setCard(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['card_number' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'], 'card_type' => ['required', Rule::in(['normalCard', 'patrolCard', 'hijackCard', 'superCard'])], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        abort_unless((bool) data_get($device->capabilities, 'card_management', false), 409, 'Card management is not verified for this device. Test the device first.');
        $fingerprint = $this->credentialFingerprint($data['card_number']);
        if ($existing = DB::table('hr_attendance_credential_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $adapter = $providers->adapterFor($device);
        $owner = $adapter->cardOwner($device, $data['card_number']);
        abort_if($owner !== null, 409, $owner === $employeeNumber ? 'This card is already assigned to the Staff member.' : 'This card is already assigned to another terminal identity.');
        $result = $adapter->setCard($device, $employeeNumber, $data['card_number'], $data['card_type']);
        $event = $this->credentialEvent($request, $device, $mapping, 'card', 'set', $fingerprint, $this->maskCard($data['card_number']), $data['reason'], $data['idempotency_key'], $result);

        return response()->json(['status' => 'success', 'data' => $event], 201);
    }

    public function deleteCard(Request $request, string $deviceId, string $employeeNumber, string $fingerprint, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        abort_unless((bool) data_get($device->capabilities, 'card_management', false), 409, 'Card management is not verified for this device. Test the device first.');
        if ($existing = DB::table('hr_attendance_credential_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $card = collect($providers->adapterFor($device)->cards($device, $employeeNumber))->first(fn ($row) => hash_equals($fingerprint, $this->credentialFingerprint($row['card_number'])));
        abort_unless($card, 404, 'The card is no longer assigned on the terminal.');
        $result = $providers->adapterFor($device)->deleteCard($device, $card['card_number']);
        $event = $this->credentialEvent($request, $device, $mapping, 'card', 'revoke', $fingerprint, $this->maskCard($card['card_number']), $data['reason'], $data['idempotency_key'], $result);

        return response()->json(['status' => 'success', 'data' => $event]);
    }

    public function setPin(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        abort_unless((bool) data_get($device->capabilities, 'pin_management', false), 409, 'PIN management is not verified for this device. Test the device first.');
        if ($existing = DB::table('hr_attendance_credential_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $result = $providers->adapterFor($device)->setPin($device, $employeeNumber, $data['pin']);
        $event = $this->credentialEvent($request, $device, $mapping, 'pin', 'set', null, null, $data['reason'], $data['idempotency_key'], $result);

        return response()->json(['status' => 'success', 'data' => $event]);
    }

    public function disposition(Request $request, string $deviceId, string $employeeNumber): JsonResponse
    {
        $data = $request->validate(['disposition' => ['required', Rule::in(['former_staff', 'vendor', 'test_identity', 'unknown', 'ignored'])], 'reason' => ['required', 'string', 'max:2000']]);
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        DB::table('hr_attendance_identity_dispositions')->updateOrInsert(['device_id' => $device->id, 'provider_person_id' => $employeeNumber], [
            'id' => (string) Str::uuid(), 'company_id' => $device->company_id, 'disposition' => $data['disposition'], 'reason' => $data['reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_identity_dispositions')->where('device_id', $device->id)->where('provider_person_id', $employeeNumber)->first()]);
    }

    public function bulkMapping(Request $request, string $deviceId, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['commit' => ['required', 'boolean'], 'rows' => ['required', 'array', 'min:1', 'max:100'], 'rows.*.provider_person_id' => ['required', 'string', 'max:160'], 'rows.*.staff_id' => ['required', 'uuid', 'distinct']]);
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        $position = 0;
        $people = [];
        do {
            $page = $providers->adapterFor($device)->people($device, $position, 30);
            $people = array_merge($people, $page['people']);
            $position = $page['next_position'];
        } while ($page['has_more']);
        $directory = collect($people)->keyBy('employee_no');
        $allStaff = Staff::query()->with('user:id,first_name,last_name')->where('company_id', $device->company_id)->whereNull('employment_ended_at')->get();
        $staff = $allStaff->whereIn('id', collect($data['rows'])->pluck('staff_id'))->keyBy('id');
        $preview = [];
        foreach ($data['rows'] as $row) {
            $person = $directory->get($row['provider_person_id']);
            $member = $staff->get($row['staff_id']);
            $matches = $person ? $allStaff->filter(fn ($candidate) => $this->safeIdentityMatch($person, $candidate)) : collect();
            $safe = $person && $member && $matches->count() === 1 && $matches->first()->id === $member->id;
            $reason = ! $person ? 'Terminal identity not found.' : (! $member ? 'Active Staff record not found.' : (! $safe ? 'The identity is not an exact unique identifier or name match.' : null));
            $preview[] = ['provider_person_id' => $row['provider_person_id'], 'device_name' => $person['display_name'] ?? null, 'staff_id' => $row['staff_id'], 'staff_name' => $member ? trim(($member->user?->first_name ?? '').' '.($member->user?->last_name ?? '')) : null, 'safe' => $safe, 'reason' => $reason];
        }
        abort_if(collect($preview)->contains(fn ($row) => ! $row['safe']) && $data['commit'], 422, 'Every bulk mapping row must pass server-side exact-match verification.');
        if (! $data['commit']) {
            return response()->json(['status' => 'success', 'data' => ['preview' => $preview, 'committed' => false]]);
        }
        $result = DB::transaction(function () use ($data, $device, $request) {
            $created = 0;
            $resolved = 0;
            foreach ($data['rows'] as $row) {
                $payload = ['company_id' => $device->company_id, 'staff_id' => $row['staff_id'], 'device_id' => $device->id, 'provider_person_id' => $row['provider_person_id'], 'effective_from' => now($device->timezone)->toDateString(), 'effective_until' => null];
                abort_if($this->mappingOverlapQuery($payload)->lockForUpdate()->exists(), 409, 'A proposed bulk mapping overlaps an existing Staff or terminal mapping.');
                $id = (string) Str::uuid();
                DB::table('hr_attendance_person_mappings')->insert($payload + ['id' => $id, 'employee_number_snapshot' => $row['provider_person_id'], 'enrollment_status' => 'verified', 'created_by' => $request->user()->id, 'last_verified_at' => now(), 'verified_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('hr_attendance_identity_dispositions')->where('device_id', $device->id)->where('provider_person_id', $row['provider_person_id'])->delete();
                $resolved += $this->reconcileMapping(DB::table('hr_attendance_person_mappings')->find($id), $request->user()->id, 'Resolved by reviewed exact-match bulk mapping.');
                $created++;
            }

            return ['created' => $created, 'resolved_quarantine_count' => $resolved];
        });

        return response()->json(['status' => 'success', 'data' => ['preview' => $preview, 'committed' => true] + $result], 201);
    }

    public function mappingCandidates(Request $request): JsonResponse
    {
        $companyId = $this->authorizedCompanyId($request, $request->input('company_id'));
        $staff = DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')->where('staff.company_id', $companyId)->whereNull('staff.employment_ended_at')->select('staff.id', 'staff.code', 'users.first_name', 'users.last_name', 'users.email')->orderBy('users.first_name')->get();

        return response()->json(['status' => 'success', 'data' => $staff]);
    }

    public function legacyStaffGaps(Request $request): JsonResponse
    {
        $actor = Staff::query()->where('user_id', $request->user()->id)->firstOrFail();
        $rows = DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')->whereNull('staff.deleted_at')->where(fn ($q) => $q->whereNull('staff.company_id')->orWhereNull('staff.code'))->select('staff.id', 'staff.company_id', 'staff.code', 'users.first_name', 'users.last_name', 'users.email')->orderBy('users.first_name')->paginate($request->integer('per_page', 100));

        return response()->json(['status' => 'success', 'data' => $rows, 'meta' => ['actor_staff_id' => $actor->id]]);
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

    public function storeDevice(Request $request): JsonResponse
    {
        $this->requireAttendanceWrites();
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'connector_id' => ['nullable', 'uuid', 'exists:hr_attendance_connectors,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:hr_organization_units,id'],
            'provider' => ['required', 'string', 'max:60'],
            'integration_mode' => ['required', Rule::in(['direct_isapi', 'hikcentral', 'provider_push', 'approved_csv', 'local_connector'])],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['required', 'string', 'max:160'],
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

    public function storeMapping(Request $request): JsonResponse
    {
        $this->requireAttendanceWrites();
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'device_id' => ['nullable', 'uuid', 'exists:hr_attendance_devices,id'],
            'provider_person_id' => ['required', 'string', 'max:160'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'enrolled_methods' => ['nullable', 'array'],
            'enrolled_methods.*' => ['string', Rule::in(['fingerprint', 'card', 'face', 'pin'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->authorizedCompanyId($request, $data['company_id']);

        return DB::transaction(function () use ($request, $data) {
            DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->first();
            $staff = Staff::query()->findOrFail($data['staff_id']);
            abort_unless($staff->company_id === $data['company_id'], 422, 'Staff and mapping legal entities must match.');
            if (! empty($data['device_id'])) {
                abort_unless(AttendanceDevice::query()->whereKey($data['device_id'])->where('company_id', $data['company_id'])->exists(), 422, 'Device and mapping legal entities must match.');
            }
            $overlapping = $this->mappingOverlapQuery($data)->lockForUpdate()->get();
            abort_if($overlapping->isNotEmpty() && blank($data['reason'] ?? null), 422, 'A reason is required when changing an existing portal mapping.');
            foreach ($overlapping as $existing) {
                DB::table('hr_attendance_person_mappings')->where('id', $existing->id)
                    ->update(['effective_until' => $data['effective_from'], 'updated_at' => now()]);
            }
            $reason = trim((string) ($data['reason'] ?? '')) ?: 'Initial reviewed terminal-to-Staff mapping.';
            unset($data['reason']);
            $data['enrolled_methods'] = isset($data['enrolled_methods']) ? json_encode(array_values(array_unique($data['enrolled_methods'])), JSON_THROW_ON_ERROR) : null;
            $id = (string) Str::uuid();
            $employeeNumberSnapshot = $data['provider_person_id'];
            DB::table('hr_attendance_person_mappings')->insert($data + ['id' => $id, 'employee_number_snapshot' => $employeeNumberSnapshot, 'enrollment_status' => 'verified', 'created_by' => $request->user()->id, 'last_verified_at' => now(), 'verified_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $mapping = DB::table('hr_attendance_person_mappings')->find($id);
            $resolved = $this->reconcileMapping($mapping, $request->user()->id, 'Resolved automatically when the device-person mapping was saved.');
            if (! empty($data['device_id'])) {
                DB::table('hr_attendance_device_config_events')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $data['company_id'], 'device_id' => $data['device_id'],
                    'action' => $overlapping->isEmpty() ? 'portal_person_mapping_created' : 'portal_person_mapping_changed',
                    'before_checksum' => hash('sha256', json_encode($overlapping->pluck('id')->all())), 'after_checksum' => hash('sha256', $id),
                    'safe_snapshot' => json_encode(['provider_person_id' => $data['provider_person_id'], 'previous_staff_ids' => $overlapping->pluck('staff_id')->all(), 'new_staff_id' => $data['staff_id'], 'effective_from' => $data['effective_from']]),
                    'status' => 'completed', 'reason' => $reason, 'actor_user_id' => $request->user()->id,
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return response()->json(['status' => 'success', 'data' => ['mapping' => $mapping, 'replaced_mapping_count' => $overlapping->count(), 'resolved_quarantine_count' => $resolved]], 201);
        });
    }

    public function mappings(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['pending', 'verified'])], 'provider_person_id' => ['nullable', 'string', 'max:160']]);
        $companyId = $this->authorizedCompanyId($request, $data['company_id'] ?? null);
        $query = DB::table('hr_attendance_person_mappings')
            ->select(['id', 'staff_id', 'device_id', 'provider_person_id', 'employee_number_snapshot', 'enrollment_status', 'effective_from', 'effective_until', 'created_by', 'last_verified_at', 'verified_by'])
            ->where('company_id', $companyId)
            ->when($data['staff_id'] ?? null, fn ($builder, $value) => $builder->where('staff_id', $value))
            ->when($data['status'] ?? null, fn ($builder, $value) => $builder->where('enrollment_status', $value))
            ->when($data['provider_person_id'] ?? null, fn ($builder, $value) => $builder->where('provider_person_id', $value))
            ->latest('created_at');

        return response()->json(['status' => 'success', 'data' => $query->paginate($request->integer('per_page', 50))]);
    }

    public function approveMapping(Request $request, string $mappingId): JsonResponse
    {
        $this->requireAttendanceWrites();

        return DB::transaction(function () use ($request, $mappingId) {
            $mapping = DB::table('hr_attendance_person_mappings')->where('id', $mappingId)->lockForUpdate()->first();
            abort_unless($mapping, 404);
            $this->authorizedCompanyId($request, $mapping->company_id);
            if ($mapping->enrollment_status === 'verified') {
                return response()->json(['status' => 'success', 'data' => ['mapping' => $mapping, 'resolved_quarantine_count' => 0]]);
            }
            abort_unless($mapping->enrollment_status === 'pending', 409, 'Only pending mappings may be activated.');
            DB::table('hr_attendance_person_mappings')->where('id', $mappingId)->update([
                'enrollment_status' => 'verified',
                'last_verified_at' => now(),
                'verified_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $mapping = DB::table('hr_attendance_person_mappings')->find($mappingId);
            $resolved = $this->reconcileMapping($mapping, $request->user()->id, 'Resolved automatically when the device-person mapping was activated.');

            return response()->json(['status' => 'success', 'data' => ['mapping' => DB::table('hr_attendance_person_mappings')->find($mappingId), 'resolved_quarantine_count' => $resolved]]);
        });
    }

    public function rawEvents(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $companyId = $this->authorizedCompanyId($request, $data['company_id'] ?? null);
        $query = DB::table('hr_attendance_raw_events')
            ->select(['id', 'company_id', 'device_id', 'staff_id', 'provider_event_id', 'provider_person_id', 'employee_number', 'occurred_at', 'source_timezone', 'event_kind', 'direction', 'authentication_method', 'verification_result', 'payload_checksum', 'mapping_status', 'received_at'])
            ->where('company_id', $companyId)
            ->when($data['staff_id'] ?? null, fn ($builder, $value) => $builder->where('staff_id', $value))
            ->when($data['from'] ?? null, fn ($builder, $value) => $builder->whereDate('occurred_at', '>=', $value))
            ->when($data['to'] ?? null, fn ($builder, $value) => $builder->whereDate('occurred_at', '<=', $value))
            ->latest('occurred_at');

        return response()->json(['status' => 'success', 'data' => $query->paginate($request->integer('per_page', 50))]);
    }

    public function quarantine(Request $request): JsonResponse
    {
        $companyId = $this->authorizedCompanyId($request, $request->input('company_id'));
        $query = DB::table('hr_attendance_quarantine_items as item')
            ->join('hr_attendance_raw_events as event', 'event.id', '=', 'item.raw_event_id')
            ->select('item.*', 'event.provider_event_id', 'event.provider_person_id', 'event.employee_number', 'event.occurred_at', 'event.device_id')
            ->where('item.company_id', $companyId)
            ->where('item.status', $request->input('status', 'open'))
            ->orderBy('event.occurred_at');

        return response()->json(['status' => 'success', 'data' => $query->paginate($request->integer('per_page', 50))]);
    }

    public function resolveQuarantine(Request $request, string $itemId): JsonResponse
    {
        $data = $request->validate(['person_mapping_id' => ['required', 'uuid', 'exists:hr_attendance_person_mappings,id'], 'reason' => ['required', 'string', 'max:2000']]);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $item = DB::table('hr_attendance_quarantine_items')->where('id', $itemId)->lockForUpdate()->first();
            abort_unless($item, 404);
            $this->authorizedCompanyId($request, $item->company_id);
            if ($item->status === 'resolved') {
                return response()->json(['status' => 'success', 'data' => $item]);
            }
            $event = DB::table('hr_attendance_raw_events')->where('id', $item->raw_event_id)->first();
            $mapping = DB::table('hr_attendance_person_mappings')->where('id', $data['person_mapping_id'])->first();
            abort_unless($event && $mapping && $mapping->company_id === $item->company_id, 422, 'Event, mapping, and quarantine legal entities must match.');
            abort_unless($mapping->enrollment_status === 'verified', 422, 'Only a verified mapping can resolve quarantined evidence.');
            abort_unless($mapping->provider_person_id === $event->provider_person_id, 422, 'The provider person does not match the selected mapping.');
            abort_unless($mapping->device_id === null || $mapping->device_id === $event->device_id, 422, 'The event device does not match the selected mapping.');
            $occurred = CarbonImmutable::parse($event->occurred_at)->toDateString();
            abort_unless($occurred >= $mapping->effective_from && ($mapping->effective_until === null || $occurred < $mapping->effective_until), 422, 'The mapping was not effective when the event occurred.');
            DB::table('hr_attendance_quarantine_items')->where('id', $itemId)->update([
                'status' => 'resolved', 'resolved_staff_id' => $mapping->staff_id, 'resolved_mapping_id' => $mapping->id,
                'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution_reason' => $data['reason'], 'updated_at' => now(),
            ]);

            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_quarantine_items')->find($itemId)]);
        });
    }

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

    private function mappingOverlapQuery(array $data): Builder
    {
        return DB::table('hr_attendance_person_mappings')
            ->where('company_id', $data['company_id'])
            ->where(fn ($query) => empty($data['device_id']) ? $query->whereNull('device_id') : $query->where('device_id', $data['device_id']))
            ->where(fn ($query) => $query
                ->where('provider_person_id', $data['provider_person_id'])
                ->orWhere('staff_id', $data['staff_id']))
            ->whereDate('effective_from', '<', $data['effective_until'] ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $data['effective_from']));
    }

    private function mappedIdentity(Request $request, string $deviceId, string $employeeNumber): array
    {
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        abort_unless($device->status === 'active' && $device->integration_mode === 'direct_isapi', 409, 'Credential writes require an active direct-ISAPI device.');
        $mapping = DB::table('hr_attendance_person_mappings')->where('company_id', $device->company_id)->where('provider_person_id', $employeeNumber)->where('enrollment_status', 'verified')->where(fn ($q) => $q->where('device_id', $device->id)->orWhereNull('device_id'))->whereDate('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', now()))->first();
        abort_unless($mapping, 422, 'A current verified Staff mapping is required.');

        return [$device, $mapping];
    }

    private function credentialFingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function terminalPerson(mixed $adapter, AttendanceDevice $device, string $employeeNumber): ?array
    {
        $position = 0;
        do {
            $page = $adapter->people($device, $position, 30);
            $person = collect($page['people'] ?? [])->firstWhere('employee_no', $employeeNumber);
            if ($person) {
                return $person;
            }
            $position = (int) ($page['next_position'] ?? 0);
        } while ((bool) ($page['has_more'] ?? false));

        return null;
    }

    private function maskCard(string $value): string
    {
        return str_repeat('•', max(0, strlen($value) - 4)).substr($value, -4);
    }

    private function credentialEvent(Request $request, AttendanceDevice $device, object $mapping, string $type, string $action, ?string $fingerprint, ?string $masked, string $reason, string $idempotency, array $result): object
    {
        $id = (string) Str::uuid();
        DB::table('hr_attendance_credential_events')->insert(['id' => $id, 'company_id' => $device->company_id, 'device_id' => $device->id, 'staff_id' => $mapping->staff_id, 'provider_person_id' => $mapping->provider_person_id, 'credential_type' => $type, 'action' => $action, 'credential_fingerprint' => $fingerprint, 'masked_reference' => $masked, 'status' => 'delivered', 'reason' => $reason, 'idempotency_key' => $idempotency, 'actor_user_id' => $request->user()->id, 'occurred_at' => now(), 'provider_result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('hr_attendance_credential_events')->find($id);
    }

    private function safeIdentityMatch(array $person, Staff $staff): bool
    {
        $normalize = fn ($value) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
        $deviceName = $normalize($person['display_name'] ?? '');
        $full = $normalize(($staff->user?->first_name ?? '').($staff->user?->last_name ?? ''));
        $tokens = collect([$staff->user?->first_name, $staff->user?->last_name])->map($normalize)->filter(fn ($token) => strlen($token) >= 4);

        return ($normalize($staff->code) !== '' && $normalize($staff->code) === $normalize($person['employee_no'] ?? '')) || ($full !== '' && $full === $deviceName) || $tokens->contains($deviceName);
    }

    private function reconcileMapping(object $mapping, string $actorUserId, string $reason): int
    {
        $events = DB::table('hr_attendance_raw_events')->where('company_id', $mapping->company_id)->where('provider_person_id', $mapping->provider_person_id)
            ->when($mapping->device_id, fn ($query, $id) => $query->where('device_id', $id))->whereDate('occurred_at', '>=', $mapping->effective_from)
            ->when($mapping->effective_until, fn ($query, $until) => $query->whereDate('occurred_at', '<', $until));
        $eventIds = (clone $events)->pluck('id');
        $events->update(['staff_id' => $mapping->staff_id, 'person_mapping_id' => $mapping->id, 'mapping_status' => 'mapped', 'updated_at' => now()]);

        return DB::table('hr_attendance_quarantine_items')->whereIn('raw_event_id', $eventIds)->where('status', 'open')->update([
            'status' => 'resolved', 'resolved_staff_id' => $mapping->staff_id, 'resolved_mapping_id' => $mapping->id, 'resolved_by' => $actorUserId,
            'resolved_at' => now(), 'resolution_reason' => $reason, 'updated_at' => now(),
        ]);
    }

    private function authorizedCompanyId(Request $request, ?string $requestedCompanyId): string
    {
        $actorCompanyId = Staff::query()->where('user_id', $request->user()->id)->value('company_id');
        abort_unless($actorCompanyId, 403, 'The authenticated user has no staff legal-entity context.');
        abort_if($requestedCompanyId && $requestedCompanyId !== $actorCompanyId, 403, 'Attendance data is outside your legal entity.');

        return $actorCompanyId;
    }

    private function requireAttendanceWrites(): void
    {
        abort_unless(config('hr.features.attendance_ingestion', false), 409, 'Attendance ingestion writes are not enabled.');
    }
}
