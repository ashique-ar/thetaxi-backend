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
            $facts = $providers->adapterFor($device)->discover($device);
            abort_unless(hash_equals($device->serial_number, $facts['serial_number']), 409, 'The configured endpoint belongs to a different Hikvision device.');
            $device->update([
                'model' => $facts['model'] ?: $device->model,
                'firmware' => $facts['firmware'] ?: $device->firmware,
                'capabilities' => array_merge((array) $device->capabilities, $facts['capabilities'], [
                    'manufacturer' => $facts['manufacturer'],
                    'device_type' => $facts['device_type'],
                ]),
                'last_sync_at' => now(),
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            report($exception);
            abort(503, 'The Hikvision terminal could not be reached. Check its IP, port, network route, and power.');
        } catch (\Illuminate\Http\Client\RequestException $exception) {
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
        $device=AttendanceDevice::query()->find($deviceId);abort_unless($device,404);$this->authorizedCompanyId($request,$device->company_id);
        $data=$request->validate(['days'=>['nullable','integer','min:1','max:31']]);$to=CarbonImmutable::now();$days=(int)($data['days']??2);
        return response()->json(['status'=>'success','data'=>$sync->sync($device,$to->subDays($days),$to,'manual_reconciliation')]);
    }

    public function syncRuns(Request $request): JsonResponse
    {
        $companyId=$this->authorizedCompanyId($request,$request->input('company_id'));
        $runs=DB::table('hr_attendance_sync_runs')->where('company_id',$companyId)->latest('started_at')->paginate($request->integer('per_page',50));
        return response()->json(['status'=>'success','data'=>$runs]);
    }

    public function devicePeople(Request $request, string $deviceId, AttendanceProviderManager $providers): JsonResponse
    {
        $device=AttendanceDevice::query()->find($deviceId);abort_unless($device,404);$this->authorizedCompanyId($request,$device->company_id);$adapter=$providers->adapterFor($device);$position=0;$people=[];
        do{$page=$adapter->people($device,$position,30);$people=array_merge($people,$page['people']);$position=$page['next_position'];}while($page['has_more']);
        $mappings=DB::table('hr_attendance_person_mappings')->where('company_id',$device->company_id)->where(fn($q)=>$q->where('device_id',$device->id)->orWhereNull('device_id'))->get()->keyBy('provider_person_id');
        $data=collect($people)->map(fn($person)=>$person+['mapping'=>$mappings->get($person['employee_no'])])->values();
        return response()->json(['status'=>'success','data'=>['people'=>$data,'total'=>count($people)]]);
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
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            report($exception);
            abort(503, 'The Hikvision terminal could not be reached while creating the Staff user.');
        } catch (\Illuminate\Http\Client\RequestException $exception) {
            report($exception);
            abort($exception->response->status() === 401 ? 401 : 502, $exception->response->status() === 401 ? 'Hikvision authentication failed.' : 'Hikvision rejected the Staff user provisioning request.');
        }

        return response()->json(['status' => 'success', 'data' => $person], $person['created'] ? 201 : 200);
    }

    public function mappingCandidates(Request $request): JsonResponse
    {
        $companyId=$this->authorizedCompanyId($request,$request->input('company_id'));
        $staff=DB::table('staff')->join('users','users.id','=','staff.user_id')->where('staff.company_id',$companyId)->whereNull('staff.employment_ended_at')->select('staff.id','staff.code','users.first_name','users.last_name','users.email')->orderBy('users.first_name')->get();
        return response()->json(['status'=>'success','data'=>$staff]);
    }

    public function legacyStaffGaps(Request $request): JsonResponse
    {
        $actor=Staff::query()->where('user_id',$request->user()->id)->firstOrFail();
        $rows=DB::table('staff')->join('users','users.id','=','staff.user_id')->whereNull('staff.deleted_at')->where(fn($q)=>$q->whereNull('staff.company_id')->orWhereNull('staff.code'))->select('staff.id','staff.company_id','staff.code','users.first_name','users.last_name','users.email')->orderBy('users.first_name')->paginate($request->integer('per_page',100));
        return response()->json(['status'=>'success','data'=>$rows,'meta'=>['actor_staff_id'=>$actor->id]]);
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

        return response()->json(['status' => 'success', 'data' => [
            'connectors' => $connectors,
            'devices' => $devices,
            'open_quarantine_counts' => $counts,
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
        ]);
        $this->authorizedCompanyId($request, $data['company_id']);
        return DB::transaction(function () use ($request, $data) {
            DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->first();
            $staff = Staff::query()->findOrFail($data['staff_id']);
            abort_unless($staff->company_id === $data['company_id'], 422, 'Staff and mapping legal entities must match.');
            if (! empty($data['device_id'])) abort_unless(AttendanceDevice::query()->whereKey($data['device_id'])->where('company_id', $data['company_id'])->exists(), 422, 'Device and mapping legal entities must match.');
            $overlapping = $this->mappingOverlapQuery($data)->lockForUpdate()->get();
            foreach ($overlapping as $existing) {
                DB::table('hr_attendance_person_mappings')->where('id', $existing->id)->update(['effective_until' => $data['effective_from'], 'updated_at' => now()]);
            }
            $data['enrolled_methods'] = isset($data['enrolled_methods']) ? json_encode(array_values(array_unique($data['enrolled_methods'])), JSON_THROW_ON_ERROR) : null;
            $id = (string) Str::uuid();
            $employeeNumberSnapshot = $data['provider_person_id'];
            DB::table('hr_attendance_person_mappings')->insert($data + ['id'=>$id,'employee_number_snapshot'=>$employeeNumberSnapshot,'enrollment_status'=>'verified','created_by'=>$request->user()->id,'last_verified_at'=>now(),'verified_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            $mapping = DB::table('hr_attendance_person_mappings')->find($id);
            $resolved = $this->reconcileMapping($mapping, $request->user()->id, 'Resolved automatically when the device-person mapping was saved.');
            return response()->json(['status'=>'success','data'=>['mapping'=>$mapping,'replaced_mapping_count'=>$overlapping->count(),'resolved_quarantine_count'=>$resolved]], 201);
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
                return response()->json(['status' => 'success', 'data' => ['mapping'=>$mapping,'resolved_quarantine_count'=>0]]);
            }
            abort_unless($mapping->enrollment_status === 'pending', 409, 'Only pending mappings may be activated.');
            DB::table('hr_attendance_person_mappings')->where('id', $mappingId)->update([
                'enrollment_status' => 'verified',
                'last_verified_at' => now(),
                'verified_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $mapping = DB::table('hr_attendance_person_mappings')->find($mappingId);
            $resolved=$this->reconcileMapping($mapping,$request->user()->id,'Resolved automatically when the device-person mapping was activated.');
            return response()->json(['status' => 'success', 'data' => ['mapping'=>DB::table('hr_attendance_person_mappings')->find($mappingId),'resolved_quarantine_count'=>$resolved]]);
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
        if (! empty($data['device_id'])) abort_unless(AttendanceDevice::query()->whereKey($data['device_id'])->where('company_id', $data['company_id'])->exists(), 422, 'Device and command legal entities must match.');
        if ($existing = DB::table('hr_attendance_access_commands')->where('idempotency_key', $data['idempotency_key'])->first()) {
            abort_unless($existing->company_id === $data['company_id'] && $existing->staff_id === $data['staff_id'] && $existing->command_type === $data['command_type'], 409, 'Idempotency key was reused for a different command.');
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $id = (string) Str::uuid();
        DB::table('hr_attendance_access_commands')->insert([
            'id' => $id, 'company_id' => $data['company_id'], 'staff_id' => $data['staff_id'], 'device_id' => $data['device_id'] ?? null,
            'command_type' => $data['command_type'], 'access_group_code' => $data['access_group_code'] ?? null, 'status' => 'pending_approval',
            'request_snapshot' => json_encode(['reason' => $data['reason'], 'requested_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR),
            'idempotency_key' => $data['idempotency_key'], 'requested_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
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
            ->where('provider_person_id', $data['provider_person_id'])
            ->whereDate('effective_from', '<', $data['effective_until'] ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $data['effective_from']));
    }

    private function reconcileMapping(object $mapping, string $actorUserId, string $reason): int
    {
        $events = DB::table('hr_attendance_raw_events')->where('company_id',$mapping->company_id)->where('provider_person_id',$mapping->provider_person_id)
            ->when($mapping->device_id,fn($query,$id)=>$query->where('device_id',$id))->whereDate('occurred_at','>=',$mapping->effective_from)
            ->when($mapping->effective_until,fn($query,$until)=>$query->whereDate('occurred_at','<',$until));
        $eventIds = (clone $events)->pluck('id');
        $events->update(['staff_id'=>$mapping->staff_id,'person_mapping_id'=>$mapping->id,'mapping_status'=>'mapped','updated_at'=>now()]);
        return DB::table('hr_attendance_quarantine_items')->whereIn('raw_event_id',$eventIds)->where('status','open')->update([
            'status'=>'resolved','resolved_staff_id'=>$mapping->staff_id,'resolved_mapping_id'=>$mapping->id,'resolved_by'=>$actorUserId,
            'resolved_at'=>now(),'resolution_reason'=>$reason,'updated_at'=>now(),
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
