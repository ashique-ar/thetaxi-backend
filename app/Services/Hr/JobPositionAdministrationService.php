<?php

namespace App\Services\Hr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobPositionAdministrationService
{
    private const CATALOGS = [
        'job_family' => ['table' => 'hr_job_families', 'fields' => ['code', 'name', 'description', 'status', 'effective_from', 'effective_until']],
        'job_grade' => ['table' => 'hr_job_grades', 'fields' => ['code', 'name', 'rank', 'minimum_salary_lkr', 'maximum_salary_lkr', 'status', 'effective_from', 'effective_until']],
        'designation' => ['table' => 'hr_designations', 'fields' => ['job_family_id', 'job_grade_id', 'code', 'name', 'description', 'status', 'effective_from', 'effective_until']],
    ];

    public function createCatalog(string $type, array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($type, $data, $companyId, $actorUserId) {
            $definition = $this->catalog($type);
            $this->lockCompany($companyId);
            $payload = ['company_id' => $companyId] + array_intersect_key($data, array_flip($definition['fields']));
            $payload['status'] = 'active';
            $this->assertCatalogReferences($type, $payload, $companyId);
            $checksum = $this->checksum(['command' => 'create_'.$type, 'payload' => $payload, 'reason' => $data['reason']]);
            if ($replay = $this->replay($data['idempotency_key'], $checksum, $companyId, $type)) {
                return $replay;
            }

            $id = (string) Str::uuid();
            DB::table($definition['table'])->insert($payload + [
                'id' => $id,
                'version' => 1,
                'updated_user_id' => $actorUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $after = $this->snapshot($definition['table'], $id);
            $this->event($companyId, $type, $id, 'created', 1, null, $after, $data, $actorUserId, $checksum);

            return $after;
        });
    }

    public function updateCatalog(string $type, string $id, array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($type, $id, $data, $companyId, $actorUserId) {
            $definition = $this->catalog($type);
            $this->lockCompany($companyId);
            $row = DB::table($definition['table'])->where('id', $id)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($row, 404, 'The requested job catalogue record was not found in your legal entity.');
            $payload = array_intersect_key($data, array_flip($definition['fields']));
            $checksum = $this->checksum(['aggregate_id' => $id, 'expected_version' => $data['expected_version'], 'payload' => $payload, 'reason' => $data['reason']]);
            if ($replay = $this->replay($data['idempotency_key'], $checksum, $companyId, $type)) {
                return $replay;
            }
            abort_unless((int) $row->version === (int) $data['expected_version'], 409, 'The job catalogue version is stale.');
            abort_unless($payload['code'] === $row->code, 422, 'Job catalogue codes are immutable; create a new record instead.');
            $this->assertCatalogReferences($type, $payload, $companyId);
            $this->assertCatalogCoversDependents($type, $id, $payload);

            $before = (array) $row;
            $version = (int) $row->version + 1;
            DB::table($definition['table'])->where('id', $id)->update($payload + [
                'version' => $version,
                'updated_user_id' => $actorUserId,
                'updated_at' => now(),
            ]);
            $after = $this->snapshot($definition['table'], $id);
            $this->event($companyId, $type, $id, 'updated', $version, $before, $after, $data, $actorUserId, $checksum);

            return $after;
        });
    }

    public function createPosition(array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($data, $companyId, $actorUserId) {
            $this->lockCompany($companyId);
            $payload = $this->positionPayload($data, $companyId);
            $this->assertPositionReferences($payload, $companyId);
            $checksum = $this->checksum(['command' => 'create_position', 'payload' => $payload, 'reason' => $data['reason']]);
            if ($replay = $this->replay($data['idempotency_key'], $checksum, $companyId, 'position')) {
                return $this->withOccupancy($replay);
            }

            $id = (string) Str::uuid();
            DB::table('hr_positions')->insert($payload + [
                'id' => $id,
                'version' => 1,
                'updated_user_id' => $actorUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $after = $this->snapshot('hr_positions', $id);
            $this->event($companyId, 'position', $id, 'created', 1, null, $after, $data, $actorUserId, $checksum);

            return $this->withOccupancy($after);
        });
    }

    public function updatePosition(string $id, array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($id, $data, $companyId, $actorUserId) {
            $this->lockCompany($companyId);
            $row = DB::table('hr_positions')->where('id', $id)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($row, 404, 'Position was not found in your legal entity.');
            $payload = $this->positionPayload($data, $companyId, true);
            $checksum = $this->checksum(['position_id' => $id, 'expected_version' => $data['expected_version'], 'payload' => $payload, 'reason' => $data['reason']]);
            if ($replay = $this->replay($data['idempotency_key'], $checksum, $companyId, 'position')) {
                return $this->withOccupancy($replay);
            }
            abort_unless((int) $row->version === (int) $data['expected_version'], 409, 'Position version is stale.');
            abort_unless($payload['position_number'] === $row->position_number, 422, 'Position numbers are immutable; create a new position instead.');
            $this->assertPositionReferences($payload, $companyId);
            $occupied = $this->occupiedCount($id);
            abort_if((int) $payload['headcount_limit'] < $occupied, 422, 'Headcount cannot be reduced below current effective occupancy.');
            abort_if($payload['status'] === 'inactive' && $occupied > 0, 422, 'A position with current occupants cannot be made inactive.');
            $this->assertPositionCoversAssignments($id, $payload['effective_from'], $payload['effective_until'] ?? null);

            $before = (array) $row;
            $version = (int) $row->version + 1;
            DB::table('hr_positions')->where('id', $id)->update($payload + [
                'version' => $version,
                'updated_user_id' => $actorUserId,
                'updated_at' => now(),
            ]);
            $after = $this->snapshot('hr_positions', $id);
            $this->event($companyId, 'position', $id, 'updated', $version, $before, $after, $data, $actorUserId, $checksum);

            return $this->withOccupancy($after);
        });
    }

    public function withOccupancy(array $position, ?int $occupied = null): array
    {
        $occupied ??= $this->occupiedCount((string) $position['id']);
        $limit = (int) $position['headcount_limit'];
        $position['occupied_count'] = $occupied;
        $position['vacancy_count'] = max($limit - $occupied, 0);
        $position['availability_status'] = $position['status'] === 'inactive'
            ? 'inactive'
            : ($occupied === 0 ? 'vacant' : ($occupied < $limit ? 'partially_filled' : 'filled'));

        return $position;
    }

    private function positionPayload(array $data, string $companyId, bool $update = false): array
    {
        $payload = array_intersect_key($data, array_flip([
            'organization_unit_id', 'designation_id', 'position_number', 'title', 'headcount_limit', 'status', 'effective_from', 'effective_until',
        ]));
        if (! $update) {
            $payload = ['company_id' => $companyId] + $payload;
            $payload['status'] = 'active';
        }

        return $payload;
    }

    private function assertCatalogReferences(string $type, array $payload, string $companyId): void
    {
        if ($type !== 'designation') {
            return;
        }
        foreach (['job_family_id' => 'hr_job_families', 'job_grade_id' => 'hr_job_grades'] as $field => $table) {
            if (! empty($payload[$field])) {
                abort_unless($this->referenceCovers($table, $payload[$field], $companyId, $payload['effective_from'], $payload['effective_until'] ?? null), 422, "{$field} must be an active effective record in the designation legal entity and cover its interval.");
            }
        }
    }

    private function assertPositionReferences(array $payload, string $companyId): void
    {
        foreach (['organization_unit_id' => 'hr_organization_units', 'designation_id' => 'hr_designations'] as $field => $table) {
            abort_unless($this->referenceCovers($table, $payload[$field], $companyId, $payload['effective_from'], $payload['effective_until'] ?? null), 422, "{$field} must be an active effective record in the position legal entity and cover its interval.");
        }
        $designation = DB::table('hr_designations')->where('id', $payload['designation_id'])->where('company_id', $companyId)->first(['job_family_id', 'job_grade_id']);
        foreach (['job_family_id' => 'hr_job_families', 'job_grade_id' => 'hr_job_grades'] as $field => $table) {
            if (! empty($designation->{$field})) {
                abort_unless($this->referenceCovers($table, $designation->{$field}, $companyId, $payload['effective_from'], $payload['effective_until'] ?? null), 422, "The designation {$field} must remain active and cover the position interval.");
            }
        }
    }

    private function referenceCovers(string $table, string $id, string $companyId, string $from, ?string $until): bool
    {
        return DB::table($table)->where('id', $id)->where('company_id', $companyId)->where('status', 'active')
            ->whereNotNull('effective_from')->where('effective_from', '<=', $from)
            ->where(function ($query) use ($until) {
                if ($until === null) {
                    $query->whereNull('effective_until');
                } else {
                    $query->whereNull('effective_until')->orWhere('effective_until', '>=', $until);
                }
            })->exists();
    }

    private function assertPositionCoversAssignments(string $positionId, string $from, ?string $until): void
    {
        $outside = DB::table('hr_employment_assignments')->where('position_id', $positionId)
            ->where(function ($query) use ($from, $until) {
                $query->where('effective_from', '<', $from);
                if ($until !== null) {
                    $query->orWhereNull('effective_until')->orWhere('effective_until', '>', $until);
                }
            })->exists();
        abort_if($outside, 422, 'The position effective interval must cover every retained Staff assignment that references it.');
    }

    private function assertCatalogCoversDependents(string $type, string $id, array $payload): void
    {
        $dependent = match ($type) {
            'job_family' => ['table' => 'hr_designations', 'foreign_key' => 'job_family_id'],
            'job_grade' => ['table' => 'hr_designations', 'foreign_key' => 'job_grade_id'],
            'designation' => ['table' => 'hr_positions', 'foreign_key' => 'designation_id'],
            default => null,
        };
        if ($dependent === null) {
            return;
        }
        $conflict = DB::table($dependent['table'])->where($dependent['foreign_key'], $id)->where('status', 'active')
            ->where(function ($query) use ($payload) {
                if ($payload['status'] === 'inactive') {
                    $query->whereRaw('1 = 1');
                    return;
                }
                $query->where('effective_from', '<', $payload['effective_from']);
                if (($payload['effective_until'] ?? null) !== null) {
                    $query->orWhereNull('effective_until')->orWhere('effective_until', '>', $payload['effective_until']);
                }
            })->exists();
        abort_if($conflict, 422, 'The job catalogue interval or status must continue to cover its active dependent records.');
    }

    private function occupiedCount(string $positionId): int
    {
        $today = now()->toDateString();

        return DB::table('hr_employment_assignments')
            ->where('position_id', $positionId)
            ->where('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $today))
            ->count();
    }

    private function catalog(string $type): array
    {
        abort_unless(isset(self::CATALOGS[$type]), 500, 'Unsupported job catalogue type.');

        return self::CATALOGS[$type];
    }

    private function lockCompany(string $companyId): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->lockForUpdate()->first(), 404, 'Legal entity was not found.');
    }

    private function replay(string $key, string $checksum, string $companyId, string $aggregateType): ?array
    {
        $event = DB::table('hr_organization_change_events')->where('idempotency_key', $key)->first();
        if (! $event) {
            return null;
        }
        abort_unless($event->company_id === $companyId, 403, 'Job or position command replay is outside your legal entity.');
        abort_unless($event->aggregate_type === $aggregateType, 409, 'Idempotency key was reused for another job or position command.');
        abort_unless(hash_equals($event->request_checksum, $checksum), 409, 'Idempotency key was reused with different job or position facts.');

        return is_array($event->after_snapshot)
            ? $event->after_snapshot
            : json_decode((string) $event->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    private function event(string $companyId, string $type, string $id, string $eventType, int $version, ?array $before, array $after, array $data, string $actor, string $checksum): void
    {
        DB::table('hr_organization_change_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'aggregate_type' => $type, 'aggregate_id' => $id,
            'event_type' => $eventType, 'aggregate_version' => $version,
            'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'reason' => $data['reason'],
            'actor_user_id' => $actor, 'idempotency_key' => $data['idempotency_key'], 'request_checksum' => $checksum,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function snapshot(string $table, string $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
