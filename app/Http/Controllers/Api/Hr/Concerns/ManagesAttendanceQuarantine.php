<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read access to raw attendance events, and the open-item quarantine queue
 * where unmapped/ambiguous terminal identities are reviewed and resolved
 * against a verified Staff mapping.
 *
 * @see \App\Http\Controllers\Api\Hr\AttendanceDeviceController
 */
trait ManagesAttendanceQuarantine
{
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

    public function quarantineMappingOptions(Request $request, string $itemId): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $item = DB::table('hr_attendance_quarantine_items')->where('id', $itemId)
            ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
        abort_unless($item, 404);
        $companyId = $item->company_id;
        abort_unless($item->status === 'open', 409, 'Only open quarantine items can be resolved.');
        $event = DB::table('hr_attendance_raw_events')->where('id', $item->raw_event_id)->first();
        abort_unless($event && $event->company_id === $companyId, 409, 'Quarantine and raw event legal entities must match.');
        abort_unless(! $event->device_id || DB::table('hr_attendance_devices')->where('id', $event->device_id)->where('company_id', $companyId)->exists(), 409, 'The event device must belong to the quarantine legal entity.');

        $occurred = CarbonImmutable::parse($event->occurred_at)->toDateString();
        $search = trim((string) ($data['search'] ?? ''));
        $query = DB::table('hr_attendance_person_mappings as mapping')
            ->join('staff', 'staff.id', '=', 'mapping.staff_id')
            ->leftJoin('users', 'users.id', '=', 'staff.user_id')
            ->where('mapping.company_id', $companyId)
            ->where('staff.company_id', $companyId)
            ->where('mapping.enrollment_status', 'verified')
            ->where('mapping.provider_person_id', $event->provider_person_id)
            ->where(fn ($builder) => $builder->whereNull('mapping.device_id')->orWhere('mapping.device_id', $event->device_id))
            ->whereDate('mapping.effective_from', '<=', $occurred)
            ->where(fn ($builder) => $builder->whereNull('mapping.effective_until')->orWhereDate('mapping.effective_until', '>', $occurred))
            ->when($data['selected_id'] ?? null, fn ($builder, $id) => $builder->where('mapping.id', $id))
            ->when($search !== '', fn ($builder) => $builder->where(fn ($match) => $match
                ->whereLikeInsensitive('staff.code', $search)
                ->orWhereLikeInsensitive('mapping.employee_number_snapshot', $search)
                ->orWhereLikeInsensitive('mapping.provider_person_id', $search)
                ->orWhereLikeInsensitive('users.first_name', $search)
                ->orWhereLikeInsensitive('users.last_name', $search)))
            ->select('mapping.id', 'mapping.employee_number_snapshot', 'mapping.provider_person_id', 'staff.code as staff_code', 'users.first_name', 'users.last_name')
            ->orderBy('staff.code');
        $options = $query->paginate((int) ($data['per_page'] ?? 25));
        $options->setCollection($options->getCollection()->map(function ($mapping) {
            $name = trim(($mapping->first_name ?? '').' '.($mapping->last_name ?? ''));

            return [
                'value' => (string) $mapping->id,
                'label' => trim(($mapping->staff_code ? $mapping->staff_code.' · ' : '').($name ?: $mapping->employee_number_snapshot ?: $mapping->provider_person_id)),
                'metadata' => ['provider_person_id' => $mapping->provider_person_id, 'employee_number' => $mapping->employee_number_snapshot],
                'status' => 'verified',
            ];
        }));

        return response()->json(['status' => 'success', 'data' => $options]);
    }

    public function resolveQuarantine(Request $request, string $itemId): JsonResponse
    {
        $data = $request->validate(['person_mapping_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000']]);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $item = DB::table('hr_attendance_quarantine_items')->where('id', $itemId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->lockForUpdate()->first();
            abort_unless($item, 404);
            if ($item->status === 'resolved') {
                return response()->json(['status' => 'success', 'data' => $item]);
            }
            $mapping = DB::table('hr_attendance_person_mappings')->where('id', $data['person_mapping_id'])
                ->where('company_id', $item->company_id)->lockForUpdate()->first();
            abort_unless($mapping, 404);
            $event = DB::table('hr_attendance_raw_events')->where('id', $item->raw_event_id)->first();
            abort_unless($event && $event->company_id === $item->company_id && $mapping && $mapping->company_id === $item->company_id, 422, 'Event, mapping, and quarantine legal entities must match.');
            abort_unless(! $event->device_id || DB::table('hr_attendance_devices')->where('id', $event->device_id)->where('company_id', $item->company_id)->exists(), 422, 'The event device must belong to the quarantine legal entity.');
            $staff = DB::table('staff')->where('id', $mapping->staff_id)->lockForUpdate()->first();
            abort_unless($staff && $staff->company_id === $item->company_id, 422, 'The mapping Staff member must belong to the quarantine legal entity.');
            abort_unless($mapping->enrollment_status === 'verified', 422, 'Only a verified mapping can resolve quarantined evidence.');
            abort_unless($mapping->provider_person_id === $event->provider_person_id, 422, 'The provider person does not match the selected mapping.');
            abort_unless($mapping->device_id === null || $mapping->device_id === $event->device_id, 422, 'The event device does not match the selected mapping.');
            $occurred = CarbonImmutable::parse($event->occurred_at)->toDateString();
            abort_unless($occurred >= $mapping->effective_from && ($mapping->effective_until === null || $occurred < $mapping->effective_until), 422, 'The mapping was not effective when the event occurred.');
            DB::table('hr_attendance_quarantine_items')->where('id', $itemId)->where('company_id', $item->company_id)->update([
                'status' => 'resolved', 'resolved_staff_id' => $mapping->staff_id, 'resolved_mapping_id' => $mapping->id,
                'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution_reason' => $data['reason'], 'updated_at' => now(),
            ]);

            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_quarantine_items')->find($itemId)]);
        });
    }
}
