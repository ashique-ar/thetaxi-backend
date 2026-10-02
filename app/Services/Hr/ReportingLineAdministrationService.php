<?php

namespace App\Services\Hr;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportingLineAdministrationService
{
    private const SCOPE_LINE_TYPES = ['primary', 'dotted_line'];

    public function create(array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($data, $companyId, $actorUserId) {
            $this->lockCompany($companyId);
            $this->lockStaffPair($data['manager_staff_id'], $data['member_staff_id'], $companyId);
            abort_if($data['manager_staff_id'] === $data['member_staff_id'], 422, 'An employee cannot report to themselves.');
            $payload = [
                'company_id' => $companyId,
                'manager_staff_id' => $data['manager_staff_id'],
                'member_staff_id' => $data['member_staff_id'],
                'line_type' => $data['line_type'],
                'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null,
                'status' => 'active',
                'reason' => $data['reason'],
            ];
            $checksum = $this->checksum(['command' => 'create_reporting_line', 'payload' => $payload]);
            if ($replay = $this->replay($data['idempotency_key'], $checksum, $companyId)) {
                return $replay;
            }
            $this->assertEmploymentCovers($data['manager_staff_id'], $companyId, $data['effective_from'], $data['effective_until'] ?? null, 'Manager');
            $this->assertEmploymentCovers($data['member_staff_id'], $companyId, $data['effective_from'], $data['effective_until'] ?? null, 'Member');
            $this->assertNoOverlap($data['member_staff_id'], $data['line_type'], $companyId, $data['effective_from'], $data['effective_until'] ?? null);
            if (in_array($data['line_type'], self::SCOPE_LINE_TYPES, true)) {
                $this->assertNoCycle($data['manager_staff_id'], $data['member_staff_id'], $companyId, $data['effective_from'], $data['effective_until'] ?? null);
            }

            $id = (string) Str::uuid();
            DB::table('hr_reporting_lines')->insert($payload + [
                'id' => $id, 'version' => 1, 'created_user_id' => $actorUserId, 'updated_user_id' => $actorUserId,
                'idempotency_key' => $data['idempotency_key'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $after = $this->snapshot($id);
            $this->event($after, 'created', null, $after, $data['reason'], $actorUserId, $data['idempotency_key'], $checksum);
            $this->timeline($after, 'reporting_line_started', $actorUserId, $data['idempotency_key']);

            return $after;
        });
    }

    public function end(string $lineId, array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($lineId, $data, $companyId, $actorUserId) {
            $this->lockCompany($companyId);
            $row = DB::table('hr_reporting_lines')->where('id', $lineId)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($row, 404, 'Reporting line was not found in your legal entity.');
            $checksum = $this->checksum(['command' => 'end_reporting_line', 'line_id' => $lineId, 'expected_version' => $data['expected_version'], 'effective_until' => $data['effective_until'], 'reason' => $data['reason']]);
            if ($replay = $this->replay($data['idempotency_key'], $checksum, $companyId)) {
                return $replay;
            }
            abort_unless((int) $row->version === (int) $data['expected_version'], 409, 'Reporting-line version is stale.');
            abort_unless($data['effective_until'] > $row->effective_from, 422, 'Reporting-line end must be after its effective start.');
            abort_if($row->effective_until !== null && $data['effective_until'] >= $row->effective_until, 409, 'A closed reporting-line interval may only be shortened through a new version.');

            $before = (array) $row;
            $version = (int) $row->version + 1;
            DB::table('hr_reporting_lines')->where('id', $lineId)->update([
                'effective_until' => $data['effective_until'], 'status' => 'ended', 'version' => $version,
                'reason' => $data['reason'], 'updated_user_id' => $actorUserId, 'updated_at' => now(),
            ]);
            $after = $this->snapshot($lineId);
            $this->event($after, 'ended', $before, $after, $data['reason'], $actorUserId, $data['idempotency_key'], $checksum);
            $this->timeline($after, 'reporting_line_ended', $actorUserId, $data['idempotency_key']);

            return $after;
        });
    }

    public function projectAssignmentManagers(object $assignment, string $actorUserId): void
    {
        foreach ([
            'primary' => $assignment->manager_staff_id,
            'dotted_line' => $assignment->dotted_line_manager_staff_id,
            'hr_partner' => $assignment->hr_partner_staff_id,
        ] as $type => $managerId) {
            $this->replaceProjectedLine(
                (string) $assignment->staff_id,
                $managerId ? (string) $managerId : null,
                $type,
                (string) $assignment->company_id,
                $assignment->effective_from->toDateString(),
                $assignment->effective_until?->toDateString(),
                $actorUserId,
                'Assignment '.$assignment->id.' manager projection.',
                'assignment-reporting:'.$assignment->id.':'.$type,
            );
        }
    }

    public function closeForEmploymentEnd(string $staffId, string $companyId, string $endDate, string $actorUserId, string $reason): void
    {
        $this->lockCompany($companyId);
        $rows = DB::table('hr_reporting_lines')->where('company_id', $companyId)
            ->where(fn ($query) => $query->where('member_staff_id', $staffId)->orWhere('manager_staff_id', $staffId))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $endDate))
            ->lockForUpdate()->get();
        foreach ($rows as $row) {
            if ($row->effective_from >= $endDate) {
                $this->cancelFuture((array) $row, $actorUserId, $reason, 'employment-end:'.$staffId.':'.$row->id.':'.$endDate);
                continue;
            }
            $this->end((string) $row->id, [
                'effective_until' => $endDate, 'reason' => $reason,
                'expected_version' => (int) $row->version, 'idempotency_key' => 'employment-end:'.$staffId.':'.$row->id.':'.$endDate,
            ], $companyId, $actorUserId);
        }
    }

    private function cancelFuture(array $line, string $actor, string $reason, string $key): void
    {
        $checksum = $this->checksum(['command' => 'cancel_future_reporting_line', 'line_id' => $line['id'], 'reason' => $reason]);
        if ($this->replay($key, $checksum, $line['company_id'])) {
            return;
        }
        $version = (int) $line['version'] + 1;
        DB::table('hr_reporting_lines')->where('id', $line['id'])->update([
            'effective_until' => $line['effective_from'], 'status' => 'cancelled', 'version' => $version,
            'reason' => $reason, 'updated_user_id' => $actor, 'updated_at' => now(),
        ]);
        $after = $this->snapshot($line['id']);
        $this->event($after, 'cancelled', $line, $after, $reason, $actor, $key, $checksum);
        $this->timeline($after, 'reporting_line_cancelled', $actor, $key);
    }

    private function replaceProjectedLine(string $memberId, ?string $managerId, string $type, string $companyId, string $from, ?string $until, string $actor, string $reason, string $key): void
    {
        $current = DB::table('hr_reporting_lines')->where('company_id', $companyId)->where('member_staff_id', $memberId)->where('line_type', $type)
            ->where('effective_from', '<=', $from)->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
            ->lockForUpdate()->first();
        if ($current) {
            if ($current->effective_from === $from) {
                abort_unless($managerId && $current->manager_staff_id === $managerId, 409, 'A different reporting line already starts on the assignment effective date.');
                return;
            }
            $this->end((string) $current->id, [
                'effective_until' => $from, 'reason' => $reason, 'expected_version' => (int) $current->version,
                'idempotency_key' => $key.':end',
            ], $companyId, $actor);
        }
        if ($managerId) {
            $this->create([
                'manager_staff_id' => $managerId, 'member_staff_id' => $memberId, 'line_type' => $type,
                'effective_from' => $from, 'effective_until' => $until, 'reason' => $reason, 'idempotency_key' => $key.':create',
            ], $companyId, $actor);
        }
    }

    private function assertNoOverlap(string $memberId, string $type, string $companyId, string $from, ?string $until): void
    {
        $overlap = DB::table('hr_reporting_lines')->where('company_id', $companyId)->where('member_staff_id', $memberId)->where('line_type', $type)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereColumn('effective_from', '<', 'effective_until'))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
            ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
            ->lockForUpdate()->exists();
        abort_if($overlap, 409, 'A reporting line of this type already overlaps the requested interval.');
    }

    private function assertNoCycle(string $managerId, string $memberId, string $companyId, string $from, ?string $until): void
    {
        $rows = DB::table('hr_reporting_lines')->where('company_id', $companyId)->whereIn('line_type', self::SCOPE_LINE_TYPES)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereColumn('effective_from', '<', 'effective_until'))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $from))
            ->when($until !== null, fn ($query) => $query->where('effective_from', '<', $until))
            ->get(['manager_staff_id', 'member_staff_id', 'effective_from', 'effective_until']);
        $edges = [];
        foreach ($rows as $row) {
            $edges[$row->manager_staff_id][] = $row;
        }
        $queue = [[$memberId, $from, $until]];
        $seen = [];
        while ($queue !== []) {
            [$node, $pathFrom, $pathUntil] = array_shift($queue);
            $state = $node.'|'.$pathFrom.'|'.($pathUntil ?? 'open');
            if (isset($seen[$state])) {
                continue;
            }
            $seen[$state] = true;
            abort_if(count($seen) > 5000, 409, 'Reporting hierarchy is too complex to validate safely.');
            foreach ($edges[$node] ?? [] as $edge) {
                $nextFrom = max($pathFrom, (string) $edge->effective_from);
                $nextUntil = $this->earliestEnd($pathUntil, $edge->effective_until ? (string) $edge->effective_until : null);
                if ($nextUntil !== null && $nextFrom >= $nextUntil) {
                    continue;
                }
                abort_if($edge->member_staff_id === $managerId, 422, 'Reporting hierarchy cannot contain an effective cycle.');
                $queue[] = [(string) $edge->member_staff_id, $nextFrom, $nextUntil];
            }
        }
    }

    private function assertEmploymentCovers(string $staffId, string $companyId, string $from, ?string $until, string $label): void
    {
        $covered = DB::table('hr_employment_spells')->where('staff_id', $staffId)->where('company_id', $companyId)
            ->where('joined_at', '<=', $from)
            ->where(function ($query) use ($until) {
                if ($until === null) {
                    $query->where('status', 'active')->whereNull('terminated_at');
                } else {
                    $query->whereNull('terminated_at')->orWhere('terminated_at', '>=', $until);
                }
            })->exists();
        abort_unless($covered, 422, "{$label} employment must cover the complete reporting-line interval.");
    }

    private function lockStaffPair(string $managerId, string $memberId, string $companyId): void
    {
        $rows = Staff::withTrashed()->whereIn('id', [$managerId, $memberId])->where('company_id', $companyId)->orderBy('id')->lockForUpdate()->get();
        abort_unless($rows->count() === count(array_unique([$managerId, $memberId])), 422, 'Manager and member must be distinct Staff in your legal entity.');
    }

    private function lockCompany(string $companyId): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->lockForUpdate()->first(), 404, 'Legal entity was not found.');
    }

    private function replay(string $key, string $checksum, string $companyId): ?array
    {
        $event = DB::table('hr_reporting_line_events')->where('idempotency_key', $key)->first();
        if (! $event) {
            return null;
        }
        abort_unless($event->company_id === $companyId, 403, 'Reporting-line replay is outside your legal entity.');
        abort_unless(hash_equals($event->request_checksum, $checksum), 409, 'Reporting-line idempotency key was reused with different facts.');

        return is_array($event->after_snapshot) ? $event->after_snapshot : json_decode((string) $event->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    private function event(array $line, string $type, ?array $before, array $after, string $reason, string $actor, string $key, string $checksum): void
    {
        DB::table('hr_reporting_line_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $line['company_id'], 'reporting_line_id' => $line['id'],
            'event_type' => $type, 'reporting_line_version' => $line['version'],
            'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'reason' => $reason,
            'actor_user_id' => $actor, 'idempotency_key' => $key, 'request_checksum' => $checksum,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function timeline(array $line, string $eventType, string $actor, string $key): void
    {
        $spell = DB::table('hr_employment_spells')->where('staff_id', $line['member_staff_id'])
            ->where('joined_at', '<=', $line['effective_from'])->orderByDesc('spell_number')->first();
        if (! $spell) {
            return;
        }
        DB::table('hr_employee_timeline_events')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'staff_id' => $line['member_staff_id'], 'employment_spell_id' => $spell->id,
            'domain' => 'organization', 'event_type' => $eventType, 'source_type' => 'reporting_line', 'source_id' => $line['id'],
            'title' => match ($eventType) {'reporting_line_started' => 'Reporting line started', 'reporting_line_cancelled' => 'Future reporting line cancelled', default => 'Reporting line ended'},
            'safe_summary' => json_encode(['manager_staff_id' => $line['manager_staff_id'], 'line_type' => $line['line_type'], 'effective_from' => $line['effective_from'], 'effective_until' => $line['effective_until']], JSON_THROW_ON_ERROR),
            'confidentiality' => 'internal', 'effective_at' => $eventType === 'reporting_line_started' ? $line['effective_from'] : $line['effective_until'],
            'recorded_at' => now(), 'idempotency_key' => 'timeline:'.$key, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function snapshot(string $id): array
    {
        return (array) DB::table('hr_reporting_lines')->where('id', $id)->first();
    }

    private function earliestEnd(?string $left, ?string $right): ?string
    {
        if ($left === null) return $right;
        if ($right === null) return $left;
        return min($left, $right);
    }

    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
