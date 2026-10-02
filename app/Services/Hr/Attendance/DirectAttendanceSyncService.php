<?php

namespace App\Services\Hr\Attendance;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Hr\Attendance\AttendanceRawEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * PULL attendance path: actively polls a `direct_isapi` device's event log
 * (via {@see AttendanceProviderManager::adapterFor()}) over a given time
 * window and persists each event the same way the push path does — raw
 * event storage, dedup, and quarantine for unmapped identities — recording
 * progress in `hr_attendance_sync_runs`.
 *
 * Invoked both for manual reconciliation
 * ({@see \App\Http\Controllers\Api\Hr\AttendanceDeviceController::sync()})
 * and for the scheduled `hr:hikvision-sync` console command (see
 * routes/console.php). Contrast with the PUSH path
 * ({@see AttendanceIngestionService}), used when the device/middleware signs
 * and sends events to us instead of us polling it.
 */
class DirectAttendanceSyncService
{
    public function __construct(private AttendanceProviderManager $providers)
    {
    }

    public function sync(AttendanceDevice $device, CarbonImmutable $from, CarbonImmutable $to, string $type = 'incremental'): array
    {
        abort_unless(config('hr.features.attendance_ingestion', false), 409, 'HR attendance ingestion is not enabled.');
        abort_unless($device->status === 'active' && $device->integration_mode === 'direct_isapi', 409, 'Only active direct-ISAPI devices can be synchronized.');
        $runId = (string) Str::uuid();
        DB::table('hr_attendance_sync_runs')->insert(['id' => $runId, 'company_id' => $device->company_id, 'device_id' => $device->id, 'sync_type' => $type, 'status' => 'running', 'started_at' => now(), 'cursor_snapshot' => json_encode(['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'position' => 0]), 'created_at' => now(), 'updated_at' => now()]);
        $counts = ['read' => 0, 'created' => 0, 'duplicate' => 0, 'quarantined' => 0];
        $position = 0;
        try {
            do {
                $page = $this->providers->adapterFor($device)->attendanceEvents($device, $from, $to, $position, 30);
                foreach ($page['events'] as $event)
                    $this->persistEvent($device, $runId, $event, $counts);
                $counts['read'] += count($page['events']);
                $position = $page['next_position'];
                DB::table('hr_attendance_sync_runs')->where('id', $runId)->update(['read_count' => $counts['read'], 'created_count' => $counts['created'], 'duplicate_count' => $counts['duplicate'], 'quarantined_count' => $counts['quarantined'], 'cursor_snapshot' => json_encode(['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'position' => $position, 'total_matches' => $page['total_matches']]), 'updated_at' => now()]);
            } while ($page['has_more']);
            DB::table('hr_attendance_sync_runs')->where('id', $runId)->update(['status' => 'completed', 'finished_at' => now(), 'updated_at' => now()]);
            $device->update(['last_sync_at' => now()]);
            return ['run_id' => $runId, 'status' => 'completed', 'counts' => $counts];
        } catch (Throwable $exception) {
            DB::table('hr_attendance_sync_runs')->where('id', $runId)->update(['status' => 'failed', 'finished_at' => now(), 'error_summary' => Str::limit($exception->getMessage(), 2000), 'updated_at' => now()]);
            throw $exception;
        }
    }

    private function persistEvent(AttendanceDevice $device, string $runId, array $event, array &$counts): void
    {
        if (AttendanceRawEvent::query()->where('device_id', $device->id)->where('provider_event_id', $event['provider_event_id'])->exists()) {
            $counts['duplicate']++;
            return;
        }
        DB::transaction(function () use ($device, $runId, $event, &$counts) {
            $occurred = CarbonImmutable::parse($event['occurred_at']);
            $date = $occurred->setTimezone($event['source_timezone'])->toDateString();
            $mappings = DB::table('hr_attendance_person_mappings')->where('company_id', $device->company_id)->where('provider_person_id', $event['provider_person_id'])->where('enrollment_status', 'verified')->where(fn($q) => $q->where('device_id', $device->id)->orWhereNull('device_id'))->whereDate('effective_from', '<=', $date)->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $date))->get();
            $mapping = $mappings->count() === 1 ? $mappings->first() : null;
            $reason = empty($event['provider_person_id']) ? 'person_missing' : ($mappings->isEmpty() ? 'person_unmapped' : ($mappings->count() > 1 ? 'person_mapping_ambiguous' : null));
            $payloadChecksum = hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES));
            $requestId = (string) Str::uuid();
            DB::table('hr_attendance_ingestion_requests')->insert(['id' => $requestId, 'device_id' => $device->id, 'request_id' => 'direct:' . $device->id . ':' . $event['provider_event_id'], 'nonce' => 'sync:' . $runId . ':' . $event['provider_event_id'], 'signed_at' => now(), 'received_at' => now(), 'payload_checksum' => $payloadChecksum, 'event_count' => 1, 'status' => 'accepted', 'source_ip' => null, 'created_at' => now(), 'updated_at' => now()]);
            $raw = AttendanceRawEvent::create(['company_id' => $device->company_id, 'device_id' => $device->id, 'ingestion_request_id' => $requestId, 'staff_id' => $mapping?->staff_id, 'person_mapping_id' => $mapping?->id, 'provider_event_id' => $event['provider_event_id'], 'provider_person_id' => $event['provider_person_id'], 'employee_number' => $event['employee_number'], 'occurred_at' => $occurred, 'source_timezone' => $event['source_timezone'], 'source_utc_offset_minutes' => $event['source_utc_offset_minutes'], 'event_kind' => $event['event_kind'], 'direction' => $event['direction'], 'authentication_method' => $event['authentication_method'], 'verification_result' => $event['verification_result'], 'encrypted_raw_payload' => AttendanceEventEvidence::minimalPayload($event), 'payload_checksum' => $payloadChecksum, 'mapping_status' => $reason ? 'quarantined' : 'mapped', 'received_at' => now()]);
            if ($reason) {
                DB::table('hr_attendance_quarantine_items')->insert(['id' => (string) Str::uuid(), 'company_id' => $device->company_id, 'raw_event_id' => $raw->id, 'reason_code' => $reason, 'details' => 'Direct ISAPI event requires reviewed mapping resolution.', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                $counts['quarantined']++;
            } else
                $counts['created']++;
        });
        $device->update(['last_event_at' => now()]);
    }
}
