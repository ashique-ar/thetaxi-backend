<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttendanceDeviceController extends Controller
{
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
        $devices = DB::table('hr_attendance_devices')
            ->where('company_id', $companyId)
            ->select(['id', 'connector_id', 'provider', 'model', 'serial_number', 'site_code', 'timezone', 'status', 'last_sync_at', 'last_event_at'])
            ->get();
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
        ]);
        $this->authorizedCompanyId($request, $data['company_id']);
        return DB::transaction(function () use ($request, $data) {
            DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->first();
            $staff = Staff::query()->findOrFail($data['staff_id']);
            abort_unless($staff->company_id === $data['company_id'], 422, 'Staff and mapping legal entities must match.');
            if (! empty($data['device_id'])) abort_unless(AttendanceDevice::query()->whereKey($data['device_id'])->where('company_id', $data['company_id'])->exists(), 422, 'Device and mapping legal entities must match.');
            abort_if($this->mappingOverlapQuery($data)->exists(), 409, 'An overlapping provider-person mapping already exists.');
            $id = (string) Str::uuid();
            DB::table('hr_attendance_person_mappings')->insert($data + ['id'=>$id,'employee_number_snapshot'=>$staff->code,'enrollment_status'=>'pending','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            return response()->json(['status'=>'success','data'=>DB::table('hr_attendance_person_mappings')->find($id)], 201);
        });
    }

    public function approveMapping(Request $request, string $mappingId): JsonResponse
    {
        $this->requireAttendanceWrites();
        return DB::transaction(function () use ($request, $mappingId) {
            $mapping = DB::table('hr_attendance_person_mappings')->where('id', $mappingId)->lockForUpdate()->first();
            abort_unless($mapping, 404);
            $this->authorizedCompanyId($request, $mapping->company_id);
            abort_if($mapping->created_by === $request->user()->id, 409, 'The mapping creator cannot approve the same mapping.');
            abort_unless($mapping->enrollment_status === 'pending', 409, 'Only pending mappings may be approved.');
            DB::table('hr_attendance_person_mappings')->where('id', $mappingId)->update([
                'enrollment_status' => 'verified',
                'last_verified_at' => now(),
                'verified_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_person_mappings')->find($mappingId)]);
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
