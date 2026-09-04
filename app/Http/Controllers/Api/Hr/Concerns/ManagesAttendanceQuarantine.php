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
}
