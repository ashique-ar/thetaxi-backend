<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Terminal-person directory access, Staff-to-terminal identity mapping (single and
 * bulk), and quarantine-resolving reconciliation for Hikvision-family attendance
 * terminals.
 *
 * @see \App\Http\Controllers\Api\Hr\AttendanceDeviceController
 */
trait ManagesDevicePeopleMapping
{
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
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'record_type' => ['nullable', Rule::in(['staff', 'calendar', 'shift', 'policy'])],
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'],
            'selected_ids' => ['nullable', 'array', 'max:200'],
            'selected_ids.*' => ['required', 'uuid', 'distinct'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyId = $this->authorizedCompanyId($request, $data['company_id'] ?? null);
        $search = trim((string) ($data['search'] ?? ''));
        $recordType = $data['record_type'] ?? 'staff';
        if ($recordType !== 'staff') {
            $definition = match ($recordType) {
                'calendar' => ['table' => 'hr_work_calendars', 'status' => 'active'],
                'shift' => ['table' => 'hr_shift_definitions', 'status' => 'active'],
                'policy' => ['table' => 'hr_attendance_policies', 'status' => 'approved'],
            };
            $records = DB::table($definition['table'])
                ->where('company_id', $companyId)
                ->where('status', $definition['status'])
                ->when($data['selected_id'] ?? null, fn ($query, $id) => $query->where('id', $id))
                ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search) {
                    $match->whereLikeInsensitive('code', $search)->orWhereLikeInsensitive('name', $search);
                }))
                ->select('id', 'code', 'name', 'status')
                ->orderBy('name')
                ->paginate((int) ($data['per_page'] ?? 25));
            $records->setCollection($records->getCollection()->map(fn ($row) => [
                'value' => (string) $row->id,
                'label' => trim(($row->code ? $row->code.' · ' : '').$row->name),
                'metadata' => ['code' => $row->code, 'record_type' => $recordType],
                'status' => $row->status,
            ]));

            return response()->json(['status' => 'success', 'data' => $records]);
        }
        $staff = DB::table('staff')
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.company_id', $companyId)
            ->whereNull('staff.deleted_at')
            ->whereNull('staff.employment_ended_at')
            ->when($data['selected_id'] ?? null, fn ($query, $id) => $query->where('staff.id', $id))
            ->when($data['selected_ids'] ?? null, fn ($query, $ids) => $query->whereIn('staff.id', $ids))
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search) {
                $match->whereLikeInsensitive('staff.code', $search)
                    ->orWhereLikeInsensitive('users.first_name', $search)
                    ->orWhereLikeInsensitive('users.last_name', $search)
                    ->orWhereLikeInsensitive('users.email', $search);
            }))
            ->select('staff.id', 'staff.code', 'users.first_name', 'users.last_name', 'users.email')
            ->orderBy('users.first_name')
            ->orderBy('users.last_name');
        $mapStaff = function ($row) {
            $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));
            return [
                'value' => (string) $row->id,
                'label' => trim(($row->code ? $row->code.' · ' : '').($name ?: 'Staff member')),
                'metadata' => ['code' => $row->code, 'email' => $row->email],
                'status' => 'active',
            ];
        };
        if (! empty($data['selected_ids'])) {
            return response()->json(['status' => 'success', 'data' => $staff->get()->map($mapStaff)->values()]);
        }
        $staff = $staff->paginate((int) ($data['per_page'] ?? 25));
        $staff->setCollection($staff->getCollection()->map($mapStaff));

        return response()->json(['status' => 'success', 'data' => $staff]);
    }

    public function legacyStaffGaps(Request $request): JsonResponse
    {
        $actor = Staff::query()->where('user_id', $request->user()->id)->firstOrFail();
        $rows = DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')->whereNull('staff.deleted_at')->where(fn ($q) => $q->whereNull('staff.company_id')->orWhereNull('staff.code'))->select('staff.id', 'staff.company_id', 'staff.code', 'users.first_name', 'users.last_name', 'users.email')->orderBy('users.first_name')->paginate($request->integer('per_page', 100));

        return response()->json(['status' => 'success', 'data' => $rows, 'meta' => ['actor_staff_id' => $actor->id]]);
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
}
