<?php

namespace App\Services\Hr;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActingAppointmentAdministrationService
{
    private const ASSIGNMENT_FIELDS = [
        'position_id', 'organization_unit_id', 'manager_staff_id', 'dotted_line_manager_staff_id',
        'hr_partner_staff_id', 'cost_centre_code', 'location_code', 'payroll_group_code',
        'default_shift_code', 'work_pattern_code',
    ];

    public function __construct(private readonly ReportingLineAdministrationService $reportingLines) {}

    public function create(array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($data, $companyId, $actorUserId) {
            $this->lockCompany($companyId);
            if ($existing = DB::table('hr_acting_appointments')->where('idempotency_key', $data['idempotency_key'])->first()) {
                abort_unless(
                    $existing->company_id === $companyId
                    && $existing->staff_id === $data['staff_id']
                    && $existing->acting_position_id === $data['acting_position_id']
                    && $existing->acting_manager_staff_id === ($data['acting_manager_staff_id'] ?? null)
                    && $existing->effective_from === $data['effective_from']
                    && $existing->effective_until === $data['effective_until']
                    && $existing->reason === $data['reason'],
                    409,
                    'Acting-appointment key was reused with different evidence.',
                );
                return $this->snapshot((string) $existing->id);
            }
            $staff = DB::table('staff')->where('id', $data['staff_id'])->where('company_id', $companyId)->whereNull('employment_ended_at')->lockForUpdate()->first();
            abort_unless($staff, 422, 'The employee must be active in your legal entity.');
            abort_unless($data['effective_until'] > $data['effective_from'], 422, 'An acting appointment must have a finite end after its start.');
            $spell = DB::table('hr_employment_spells')->where('staff_id', $staff->id)->where('company_id', $companyId)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($spell, 422, 'An active employment spell is required.');
            $source = $this->assignmentAt((string) $staff->id, $data['effective_from'], true);
            abort_unless($source && $source->employment_spell_id === $spell->id, 422, 'A governed source assignment must cover the acting start.');
            abort_if($source->effective_until !== null && $source->effective_until <= $data['effective_until'], 422, 'The source assignment must extend beyond the acting restoration boundary.');
            abort_if(DB::table('hr_employment_assignments')->where('staff_id', $staff->id)->where('id', '!=', $source->id)->where('effective_from', '>=', $data['effective_from'])->where('effective_from', '<=', $data['effective_until'])->exists(), 409, 'Another assignment is already scheduled inside the acting or restoration boundary.');
            $position = $this->positionForInterval($data['acting_position_id'], $companyId, $data['effective_from'], $data['effective_until'], true);
            $managerId = $data['acting_manager_staff_id'] ?? $source->manager_staff_id;
            $this->assertOptionalStaff($managerId, $companyId, (string) $staff->id);
            $this->assertNoAppointmentOverlap((string) $staff->id, $data['effective_from'], $data['effective_until']);
            $this->assertPositionCapacity((string) $position->id, (int) $position->headcount_limit, (string) $staff->id, $data['effective_from'], $data['effective_until']);

            $sourceSnapshot = $this->assignmentSnapshot($source);
            $actingSnapshot = $sourceSnapshot;
            $actingSnapshot['position_id'] = (string) $position->id;
            $actingSnapshot['organization_unit_id'] = (string) $position->organization_unit_id;
            $actingSnapshot['manager_staff_id'] = $managerId;
            $restoreSnapshot = $sourceSnapshot;
            $excluded = [
                'compensation_change_included' => false,
                'payroll_change_included' => false,
                'delegated_approval_authority_included' => false,
                'sales_profile_or_hierarchy_change_included' => false,
            ];
            $command = [
                'company_id' => $companyId, 'staff_id' => (string) $staff->id,
                'source_assignment_id' => (string) $source->id, 'acting_position_id' => (string) $position->id,
                'acting_manager_staff_id' => $managerId, 'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'], 'reason' => $data['reason'],
                'source_assignment_snapshot' => $sourceSnapshot, 'acting_assignment_snapshot' => $actingSnapshot,
                'restoration_assignment_snapshot' => $restoreSnapshot, 'excluded_impact_snapshot' => $excluded,
            ];
            $checksum = $this->checksum($command);
            $id = (string) Str::uuid();
            DB::table('hr_acting_appointments')->insert([
                'id' => $id, 'company_id' => $companyId, 'staff_id' => $staff->id, 'employment_spell_id' => $spell->id,
                'source_assignment_id' => $source->id, 'acting_position_id' => $position->id,
                'acting_manager_staff_id' => $managerId, 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'],
                'status' => 'pending_approval', 'version' => 1, 'reason' => $data['reason'],
                'source_assignment_snapshot' => json_encode($sourceSnapshot, JSON_THROW_ON_ERROR),
                'acting_assignment_snapshot' => json_encode($actingSnapshot, JSON_THROW_ON_ERROR),
                'restoration_assignment_snapshot' => json_encode($restoreSnapshot, JSON_THROW_ON_ERROR),
                'excluded_impact_snapshot' => json_encode($excluded, JSON_THROW_ON_ERROR),
                'request_checksum' => $checksum, 'idempotency_key' => $data['idempotency_key'], 'requested_by' => $actorUserId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $after = $this->snapshot($id);
            $this->event($id, 'requested', 1, null, $after, $data['reason'], $actorUserId, $data['idempotency_key'], $checksum);

            return $after;
        });
    }

    public function approve(string $id, array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($id, $data, $companyId, $actorUserId) {
            $this->lockCompany($companyId);
            $row = DB::table('hr_acting_appointments')->where('id', $id)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($row, 404, 'Acting appointment was not found in your legal entity.');
            $checksum = $this->checksum(['command' => 'approve', 'id' => $id, 'expected_version' => $data['expected_version'], 'reason' => $data['reason']]);
            if ($event = DB::table('hr_acting_appointment_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
                abort_unless(hash_equals($event->command_checksum, $checksum) && $event->acting_appointment_id === $id, 409, 'Approval key was reused with different evidence.');
                return $this->snapshot($id);
            }
            abort_unless($row->status === 'pending_approval', 409, 'Only a pending acting appointment can be approved.');
            abort_unless((int) $row->version === (int) $data['expected_version'], 409, 'Acting-appointment version is stale.');
            abort_if($row->requested_by === $actorUserId, 403, 'The requester cannot approve the same acting appointment.');
            abort_unless(DB::table('staff')->where('id', $row->staff_id)->where('company_id', $companyId)->whereNull('employment_ended_at')->lockForUpdate()->first(), 422, 'The employee is no longer active in this legal entity.');
            $current = $this->assignmentAt((string) $row->staff_id, $row->effective_from, true);
            abort_unless($current && $current->id === $row->source_assignment_id, 409, 'The source assignment changed after this request was prepared.');
            abort_unless($this->checksum($this->assignmentSnapshot($current)) === $this->checksum($this->decode($row->source_assignment_snapshot)), 409, 'The frozen source assignment changed after this request was prepared.');
            abort_unless(hash_equals($row->request_checksum, $this->requestChecksumFromRow($row)), 409, 'The frozen acting-appointment evidence is inconsistent.');
            $position = $this->positionForInterval((string) $row->acting_position_id, $companyId, $row->effective_from, $row->effective_until, true);
            $this->assertPositionCapacity((string) $position->id, (int) $position->headcount_limit, (string) $row->staff_id, $row->effective_from, $row->effective_until);
            $sourceSnapshot = $this->decode($row->source_assignment_snapshot);
            if (! empty($sourceSnapshot['position_id'])) {
                $restorationEnd = $sourceSnapshot['effective_until'] ?? null;
                $sourcePosition = $this->positionForInterval((string) $sourceSnapshot['position_id'], $companyId, $row->effective_until, $restorationEnd, true);
                $this->assertPositionCapacity((string) $sourcePosition->id, (int) $sourcePosition->headcount_limit, (string) $row->staff_id, $row->effective_until, $restorationEnd);
            }
            abort_if(DB::table('hr_employment_assignments')->where('staff_id', $row->staff_id)->where('id', '!=', $row->source_assignment_id)->where('effective_from', '>=', $row->effective_from)->where('effective_from', '<=', $row->effective_until)->exists(), 409, 'Another assignment is already scheduled inside the acting or restoration boundary.');

            $before = $this->snapshot($id);
            $actingSnapshot = $this->decode($row->acting_assignment_snapshot);
            $restoreSnapshot = $this->decode($row->restoration_assignment_snapshot);
            DB::table('hr_employment_assignments')->where('id', $row->source_assignment_id)->update(['effective_until' => $row->effective_from, 'updated_at' => now()]);
            $acting = $this->insertAssignment($row, $actingSnapshot, 'acting', $row->effective_from, $row->effective_until, $actorUserId);
            $restoration = $this->insertAssignment($row, $restoreSnapshot, 'primary', $row->effective_until, $restoreSnapshot['effective_until'] ?? null, $actorUserId);
            $this->reportingLines->projectAssignmentManagers($acting, $actorUserId);
            $this->reportingLines->projectAssignmentManagers($restoration, $actorUserId);
            DB::table('hr_acting_appointments')->where('id', $id)->update([
                'status' => 'approved', 'version' => 2, 'approved_by' => $actorUserId, 'approved_at' => now(),
                'acting_assignment_id' => $acting->id, 'restoration_assignment_id' => $restoration->id, 'updated_at' => now(),
            ]);
            $after = $this->snapshot($id);
            $this->event($id, 'approved', 2, $before, $after, $data['reason'], $actorUserId, $data['idempotency_key'], $checksum);
            $this->timeline($row, 'acting_appointment_started', 'Acting appointment starts', $acting->id, $row->effective_from, 'acting-start:'.$id);
            $this->timeline($row, 'acting_appointment_restored', 'Primary assignment restored', $restoration->id, $row->effective_until, 'acting-restore:'.$id);

            return $after;
        });
    }

    private function insertAssignment(object $appointment, array $snapshot, string $type, string $from, ?string $until, string $actor): object
    {
        $id = (string) Str::uuid();
        $values = array_intersect_key($snapshot, array_flip(self::ASSIGNMENT_FIELDS));
        DB::table('hr_employment_assignments')->insert($values + [
            'id' => $id, 'employment_spell_id' => $appointment->employment_spell_id, 'staff_id' => $appointment->staff_id,
            'company_id' => $appointment->company_id, 'assignment_type' => $type, 'effective_from' => $from,
            'effective_until' => $until, 'change_reason' => 'approved_acting_appointment',
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'approved_by' => $actor,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $assignment = (array) DB::table('hr_employment_assignments')->where('id', $id)->first();
        $assignment['effective_from'] = CarbonImmutable::parse($from);
        $assignment['effective_until'] = $until ? CarbonImmutable::parse($until) : null;
        return (object) $assignment;
    }

    private function assignmentAt(string $staffId, string $date, bool $lock = false): ?object
    {
        $query = DB::table('hr_employment_assignments')->where('staff_id', $staffId)->where('effective_from', '<=', $date)
            ->where(fn ($range) => $range->whereNull('effective_until')->orWhere('effective_until', '>', $date))->orderByDesc('effective_from');
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function positionForInterval(string $id, string $companyId, string $from, ?string $until, bool $lock = false): object
    {
        $query = DB::table('hr_positions')->where('id', $id)->where('company_id', $companyId)->where('status', 'active')
            ->where('effective_from', '<=', $from)->where(function ($range) use ($until) {
                if ($until === null) $range->whereNull('effective_until');
                else $range->whereNull('effective_until')->orWhere('effective_until', '>=', $until);
            });
        $position = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($position, 422, 'The acting position must be active and cover the complete interval.');
        return $position;
    }

    private function assertPositionCapacity(string $positionId, int $limit, string $staffId, string $from, ?string $until): void
    {
        $boundaries = DB::table('hr_employment_assignments')->where('position_id', $positionId)->where('staff_id', '!=', $staffId)
            ->where(fn ($range) => $range->whereNull('effective_until')->orWhere('effective_until', '>', $from))
            ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
            ->get(['effective_from', 'effective_until'])->flatMap(fn ($row) => [$row->effective_from, $row->effective_until])
            ->filter(fn ($date) => $date !== null && $date >= $from && ($until === null || $date < $until))->push($from)->unique();
        foreach ($boundaries as $date) {
            $occupied = DB::table('hr_employment_assignments')->where('position_id', $positionId)->where('staff_id', '!=', $staffId)
                ->where('effective_from', '<=', $date)->where(fn ($range) => $range->whereNull('effective_until')->orWhere('effective_until', '>', $date))->count();
            abort_if($occupied >= $limit, 422, 'The acting position has no governed headcount capacity for the complete interval.');
        }
    }

    private function assertNoAppointmentOverlap(string $staffId, string $from, string $until): void
    {
        abort_if(DB::table('hr_acting_appointments')->where('staff_id', $staffId)->whereIn('status', ['pending_approval', 'approved'])
            ->where('effective_from', '<', $until)->where('effective_until', '>', $from)->exists(), 409, 'This employee already has an overlapping acting appointment.');
    }

    private function assertOptionalStaff(?string $staffId, string $companyId, string $memberId): void
    {
        if ($staffId === null) return;
        abort_if($staffId === $memberId, 422, 'An acting employee cannot manage themselves.');
        abort_unless(DB::table('staff')->where('id', $staffId)->where('company_id', $companyId)->whereNull('employment_ended_at')->exists(), 422, 'The acting manager must be active in the same legal entity.');
    }

    private function assignmentSnapshot(object $row): array
    {
        $snapshot = array_intersect_key((array) $row, array_flip(self::ASSIGNMENT_FIELDS));
        $snapshot['effective_until'] = $row->effective_until;
        return $snapshot;
    }

    private function requestChecksumFromRow(object $row): string
    {
        return $this->checksum([
            'company_id' => $row->company_id, 'staff_id' => $row->staff_id, 'source_assignment_id' => $row->source_assignment_id,
            'acting_position_id' => $row->acting_position_id, 'acting_manager_staff_id' => $row->acting_manager_staff_id,
            'effective_from' => $row->effective_from, 'effective_until' => $row->effective_until, 'reason' => $row->reason,
            'source_assignment_snapshot' => $this->decode($row->source_assignment_snapshot),
            'acting_assignment_snapshot' => $this->decode($row->acting_assignment_snapshot),
            'restoration_assignment_snapshot' => $this->decode($row->restoration_assignment_snapshot),
            'excluded_impact_snapshot' => $this->decode($row->excluded_impact_snapshot),
        ]);
    }

    private function snapshot(string $id): array
    {
        $row = (array) DB::table('hr_acting_appointments')->where('id', $id)->first();
        foreach (['source_assignment_snapshot', 'acting_assignment_snapshot', 'restoration_assignment_snapshot', 'excluded_impact_snapshot'] as $field) $row[$field] = $this->decode($row[$field]);
        return $row;
    }

    private function event(string $id, string $type, int $version, ?array $before, array $after, string $reason, string $actor, string $key, string $checksum): void
    {
        DB::table('hr_acting_appointment_events')->insert([
            'id' => (string) Str::uuid(), 'acting_appointment_id' => $id, 'event_type' => $type, 'version' => $version,
            'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'reason' => $reason, 'actor_user_id' => $actor,
            'idempotency_key' => $key, 'command_checksum' => $checksum, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function timeline(object $row, string $type, string $title, string $assignmentId, string $effectiveAt, string $key): void
    {
        DB::table('hr_employee_timeline_events')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'staff_id' => $row->staff_id, 'employment_spell_id' => $row->employment_spell_id,
            'domain' => 'people', 'event_type' => $type, 'source_type' => 'acting_appointment', 'source_id' => $row->id,
            'title' => $title, 'safe_summary' => json_encode(['acting_appointment_id' => $row->id, 'assignment_id' => $assignmentId, 'effective_at' => $effectiveAt], JSON_THROW_ON_ERROR),
            'confidentiality' => 'hr_private', 'effective_at' => $effectiveAt, 'recorded_at' => now(), 'idempotency_key' => $key,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lockCompany(string $companyId): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->lockForUpdate()->first(), 404, 'Legal entity was not found.');
    }

    private function decode(mixed $value): array
    {
        return is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function checksum(array $value): string
    {
        ksort($value);
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
