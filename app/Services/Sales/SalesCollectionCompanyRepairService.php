<?php

namespace App\Services\Sales;

use App\Models\Booking\Booking;
use App\Models\Sales\SalesBookingAttribution;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SalesCollectionCompanyRepairService
{
    public function __construct(private readonly SalesCollectionCompanyIntegrity $integrity) {}

    public function preview(string $bookingNumber, string $authorizedCompanyId): array
    {
        return DB::transaction(function () use ($bookingNumber, $authorizedCompanyId): array {
            $booking = Booking::query()->where('booking_number', $bookingNumber)->lockForUpdate()->firstOrFail();
            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            abort_unless($attribution?->company_id, 409, 'The booking has no established legal entity; no collection company repair is allowed.');
            abort_unless(hash_equals($authorizedCompanyId, (string) $attribution->company_id), 409,
                'The booking legal entity changed; refresh the authorized repair context.');
            $this->integrity->assertNoImmutableEvidenceMismatch($booking, $attribution->company_id);
            $snapshot = $this->snapshot($booking, $attribution);
            abort_unless(! $snapshot['blocked'], 409, 'A linked Sales Profile belongs to another or unresolved legal entity; resolve attribution first.');
            abort_unless($snapshot['rows'] !== [], 409, 'No repairable collection company mismatch remains for this booking.');
            $companyName = DB::table('companies')->where('id', $attribution->company_id)->value('name');

            return [
                'booking_number' => $booking->booking_number,
                'company_name' => $companyName,
                'booking_evidence_subject_id' => (string) $booking->id,
                'repair_count' => count($snapshot['rows']),
                'records' => array_map(fn (array $row) => [
                    'record_type' => $row['table'],
                    'reference' => $row['reference'],
                    'recorded_company' => $row['before_company_name'] ?? 'Unassigned',
                    'company_after_repair' => $companyName,
                ], $snapshot['rows']),
                'preview_checksum' => $snapshot['checksum'],
            ];
        }, 3);
    }

    public function history(string $companyId): LengthAwarePaginator
    {
        return DB::table('sales_collection_company_repairs as repair')
            ->join('bookings as booking', 'booking.id', '=', 'repair.booking_id')
            ->join('companies as after_company', 'after_company.id', '=', 'repair.after_company_id')
            ->leftJoin('companies as before_company', 'before_company.id', '=', 'repair.before_company_id')
            ->join('domain_evidence_files as evidence', 'evidence.id', '=', 'repair.evidence_file_id')
            ->join('users as actor', 'actor.id', '=', 'repair.performed_by')
            ->leftJoin('sales_collection_company_repair_rollback_items as rollback_item', 'rollback_item.repair_id', '=', 'repair.id')
            ->leftJoin('sales_collection_company_repair_rollbacks as rollback', 'rollback.id', '=', 'rollback_item.rollback_id')
            ->leftJoin('domain_evidence_files as rollback_evidence', 'rollback_evidence.id', '=', 'rollback.evidence_file_id')
            ->leftJoin('users as rollback_actor', 'rollback_actor.id', '=', 'rollback.performed_by')
            ->where('repair.company_id', $companyId)
            ->orderByDesc('repair.repaired_at')->orderBy('repair.id')
            ->select([
                'booking.booking_number', 'repair.source_table', 'repair.reason', 'repair.repaired_at',
                'before_company.name as company_before', 'after_company.name as company_after',
                'evidence.file_name as evidence_name', 'actor.first_name as actor_first_name',
                'actor.last_name as actor_last_name',
                'rollback.reason as rollback_reason', 'rollback.rolled_back_at',
                'rollback_evidence.file_name as rollback_evidence_name',
                'rollback_actor.first_name as rollback_actor_first_name', 'rollback_actor.last_name as rollback_actor_last_name',
                DB::raw('CASE WHEN repair.after_checksum IS NULL THEN 0 ELSE 1 END as rollback_baseline_available'),
            ])->paginate(50);
    }

    public function rollbackPreview(string $bookingNumber, string $authorizedCompanyId): array
    {
        return DB::transaction(function () use ($bookingNumber, $authorizedCompanyId): array {
            $booking = Booking::query()->where('booking_number', $bookingNumber)->lockForUpdate()->firstOrFail();
            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            abort_unless($attribution?->company_id, 409, 'The booking has no established legal entity; no repair rollback is allowed.');
            abort_unless(hash_equals($authorizedCompanyId, (string) $attribution->company_id), 409,
                'The booking legal entity changed; refresh the authorized rollback context.');
            $snapshot = $this->rollbackSnapshot($booking, $attribution);
            abort_unless($snapshot['rows'] !== [], 409, 'No eligible collection company repairs remain to roll back.');

            return [
                'booking_number' => $booking->booking_number,
                'booking_evidence_subject_id' => (string) $booking->id,
                'company_name' => DB::table('companies')->where('id', $attribution->company_id)->value('name'),
                'repair_count' => count($snapshot['rows']),
                'records' => array_map(fn (array $row) => [
                    'record_type' => $row['source_table'], 'reference' => $row['reference'],
                    'company_before_rollback' => $row['after_company_name'],
                    'company_after_rollback' => $row['before_company_name'] ?? 'Unassigned',
                ], $snapshot['rows']),
                'preview_checksum' => $snapshot['checksum'],
            ];
        }, 3);
    }

    public function rollback(string $bookingNumber, array $data, string $actorUserId, string $authorizedCompanyId): array
    {
        abort_if(strlen(trim($data['reason'])) < 10, 422, 'Provide a rollback reason of at least 10 characters.');

        return DB::transaction(function () use ($bookingNumber, $data, $actorUserId, $authorizedCompanyId): array {
            $booking = Booking::query()->where('booking_number', $bookingNumber)->lockForUpdate()->firstOrFail();
            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            abort_unless($attribution?->company_id, 409, 'The booking has no established legal entity; no repair rollback is allowed.');
            abort_unless(hash_equals($authorizedCompanyId, (string) $attribution->company_id), 409,
                'The booking legal entity changed; refresh the authorized rollback context.');

            $keyHash = hash('sha256', $attribution->company_id.':'.$booking->id.':'.$data['idempotency_key']);
            $requestChecksum = hash('sha256', json_encode([
                'booking_number' => $bookingNumber, 'preview_checksum' => $data['preview_checksum'],
                'evidence_file_id' => $data['evidence_file_id'], 'reason' => trim($data['reason']),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $duplicate = DB::table('sales_collection_company_repair_rollbacks')
                ->where('company_id', $attribution->company_id)->where('idempotency_hash', $keyHash)->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->request_checksum, $requestChecksum), 409,
                    'This rollback key was already used with different facts.');
                $rollbackItems = DB::table('sales_collection_company_repair_rollback_items')
                    ->where('rollback_id', $duplicate->id)->get();
                abort_unless($rollbackItems->count() === (int) $duplicate->record_count, 409,
                    'Original rollback rows are incomplete; replay is held.');
                $this->assertAuditEvidence($attribution->company_id, $data['idempotency_key'],
                    'sales.collection.company_repair_rolled_back', $rollbackItems, trim($data['reason']));

                return ['booking_number' => $bookingNumber,
                    'rolled_back_count' => $rollbackItems->count(), 'replayed' => true];
            }

            $snapshot = $this->rollbackSnapshot($booking, $attribution, true);
            abort_unless($snapshot['rows'] !== [] && hash_equals($snapshot['checksum'], $data['preview_checksum']), 409,
                'Collection company repair rollback preview is stale; create a new preview.');
            abort_unless(DB::table('domain_evidence_files')->whereKey($data['evidence_file_id'])
                ->where('domain', 'sales')->where('company_id', $attribution->company_id)->whereNull('deleted_at')
                ->where('subject_type', 'booking')->where('subject_id', $booking->id)->exists(), 422,
                'Evidence must be attached to this booking and its established Sales legal entity.');

            $rollbackId = (string) Str::uuid();
            DB::table('sales_collection_company_repair_rollbacks')->insert([
                'id' => $rollbackId, 'company_id' => $attribution->company_id, 'booking_id' => $booking->id,
                'evidence_file_id' => $data['evidence_file_id'], 'reason' => trim($data['reason']),
                'preview_checksum' => $snapshot['checksum'], 'request_checksum' => $requestChecksum,
                'idempotency_hash' => $keyHash, 'record_count' => count($snapshot['rows']),
                'performed_by' => $actorUserId, 'rolled_back_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($snapshot['rows'] as $row) {
                $updatedAt = now();
                $query = DB::table($row['source_table'])->where('id', $row['source_record_id'])
                    ->where('booking_id', $booking->id)->where('company_id', $row['after_company_id']);
                abort_unless($query->update(['company_id' => $row['before_company_id'], 'updated_at' => $updatedAt]) === 1,
                    409, 'A collection record changed during rollback; refresh the preview.');

                $afterValues = $row['checksum_values'];
                $afterValues['company_id'] = $row['before_company_id'];
                $afterValues['updated_at'] = $updatedAt->toDateTimeString();
                $afterChecksum = hash('sha256', json_encode($afterValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                DB::table('sales_collection_company_repair_rollback_items')->insert([
                    'id' => (string) Str::uuid(), 'rollback_id' => $rollbackId, 'repair_id' => $row['repair_id'],
                    'source_table' => $row['source_table'], 'source_record_id' => $row['source_record_id'],
                    'before_checksum' => $row['current_checksum'], 'after_checksum' => $afterChecksum,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('domain_audit_events')->insert([
                    'id' => (string) Str::uuid(), 'domain' => 'sales', 'company_id' => $attribution->company_id,
                    'subject_type' => $row['source_table'], 'subject_id' => $row['source_record_id'],
                    'event_type' => 'sales.collection.company_repair_rolled_back', 'actor_user_id' => $actorUserId,
                    'actor_type' => 'user', 'correlation_id' => $data['idempotency_key'],
                    'before_checksum' => $row['current_checksum'], 'after_checksum' => $afterChecksum,
                    'reason' => trim($data['reason']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return ['booking_number' => $bookingNumber, 'rolled_back_count' => count($snapshot['rows']), 'replayed' => false];
        }, 3);
    }

    public function apply(string $bookingNumber, array $data, string $actorUserId, string $authorizedCompanyId): array
    {
        abort_if(strlen(trim($data['reason'])) < 10, 422, 'Provide a repair reason of at least 10 characters.');
        return DB::transaction(function () use ($bookingNumber, $data, $actorUserId, $authorizedCompanyId): array {
            $booking = Booking::query()->where('booking_number', $bookingNumber)->lockForUpdate()->firstOrFail();
            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            abort_unless($attribution?->company_id, 409, 'The booking has no established legal entity; no collection company repair is allowed.');
            abort_unless(hash_equals($authorizedCompanyId, (string) $attribution->company_id), 409,
                'The booking legal entity changed; refresh the authorized repair context.');

            $keyHash = hash('sha256', $attribution->company_id.':'.$booking->id.':'.$data['idempotency_key']);
            $requestChecksum = hash('sha256', json_encode([
                'booking_number' => $bookingNumber,
                'preview_checksum' => $data['preview_checksum'],
                'evidence_file_id' => $data['evidence_file_id'],
                'reason' => trim($data['reason']),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $duplicate = DB::table('sales_collection_company_repairs')->where('company_id', $attribution->company_id)
                ->where('idempotency_hash', $keyHash)->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->request_checksum, $requestChecksum), 409,
                    'This repair key was already used with different facts.');
                $repairRows = DB::table('sales_collection_company_repairs')->where('company_id', $attribution->company_id)
                    ->where('idempotency_hash', $keyHash)->get();
                abort_unless($duplicate->request_record_count !== null
                    && $repairRows->count() === (int) $duplicate->request_record_count, 409,
                    'Original transaction rows are incomplete; replay is held.');
                $this->assertAuditEvidence($attribution->company_id, $data['idempotency_key'],
                    'sales.collection.company_repaired', $repairRows, trim($data['reason']));

                return ['booking_number' => $bookingNumber, 'repaired_count' => $repairRows->count(), 'replayed' => true];
            }

            $this->integrity->assertNoImmutableEvidenceMismatch($booking, $attribution->company_id);
            $snapshot = $this->snapshot($booking, $attribution, true);
            abort_unless(! $snapshot['blocked'], 409, 'A linked Sales Profile belongs to another or unresolved legal entity; resolve attribution first.');
            abort_unless($snapshot['rows'] !== [] && hash_equals($snapshot['checksum'], $data['preview_checksum']), 409,
                'Collection company repair preview is stale; create a new preview.');
            abort_unless(DB::table('domain_evidence_files')->whereKey($data['evidence_file_id'])
                ->where('domain', 'sales')->where('company_id', $attribution->company_id)->whereNull('deleted_at')
                ->where('subject_type', 'booking')->where('subject_id', $booking->id)->exists(), 422,
                'Evidence must be attached to this booking and its established Sales legal entity.');

            foreach ($snapshot['rows'] as $row) {
                $updatedAt = now();
                $query = DB::table($row['table'])->where('id', $row['id'])->where('booking_id', $booking->id);
                $row['before_company_id'] === null
                    ? $query->whereNull('company_id')
                    : $query->where('company_id', $row['before_company_id']);
                abort_unless($query->update(['company_id' => $attribution->company_id, 'updated_at' => $updatedAt]) === 1,
                    409, 'A collection record changed during repair; refresh the preview.');

                $repairId = (string) Str::uuid();
                $beforeChecksum = hash('sha256', json_encode($row['checksum_values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $afterValues = $row['checksum_values'];
                $afterValues['company_id'] = $attribution->company_id;
                $afterValues['updated_at'] = $updatedAt->toDateTimeString();
                $afterChecksum = hash('sha256', json_encode($afterValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                DB::table('sales_collection_company_repairs')->insert([
                    'id' => $repairId, 'company_id' => $attribution->company_id, 'booking_id' => $booking->id,
                    'source_table' => $row['table'], 'source_record_id' => $row['id'],
                    'before_company_id' => $row['before_company_id'], 'after_company_id' => $attribution->company_id,
                    'evidence_file_id' => $data['evidence_file_id'], 'reason' => trim($data['reason']),
                    'preview_checksum' => $snapshot['checksum'], 'request_checksum' => $requestChecksum,
                    'idempotency_hash' => $keyHash, 'source_key_hash' => hash('sha256', $row['table'].':'.$row['id']),
                    'before_checksum' => $beforeChecksum,
                    'after_checksum' => $afterChecksum,
                    'request_record_count' => count($snapshot['rows']),
                    'performed_by' => $actorUserId, 'repaired_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('domain_audit_events')->insert([
                    'id' => (string) Str::uuid(), 'domain' => 'sales', 'company_id' => $attribution->company_id,
                    'subject_type' => $row['table'], 'subject_id' => $row['id'],
                    'event_type' => 'sales.collection.company_repaired', 'actor_user_id' => $actorUserId,
                    'actor_type' => 'user', 'correlation_id' => $data['idempotency_key'],
                    'before_checksum' => $beforeChecksum, 'after_checksum' => $afterChecksum,
                    'reason' => trim($data['reason']), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->integrity->assertConsistent($booking, $attribution->company_id);

            return ['booking_number' => $bookingNumber, 'repaired_count' => count($snapshot['rows']), 'replayed' => false];
        }, 3);
    }

    private function assertAuditEvidence(string $companyId, string $idempotencyKey, string $eventType, Collection $rows, string $reason): void
    {
        foreach ($rows as $row) {
            abort_unless($row->before_checksum && $row->after_checksum, 409,
                'Original transaction audit checksums are incomplete; replay is held.');
            $events = DB::table('domain_audit_events')->where('domain', 'sales')->where('company_id', $companyId)
                ->where('event_type', $eventType)->where('correlation_id', $idempotencyKey)
                ->where('subject_type', $row->source_table)->where('subject_id', $row->source_record_id)
                ->limit(2)->get(['before_checksum', 'after_checksum', 'reason']);
            abort_unless($events->count() === 1 && $events[0]->before_checksum && $events[0]->after_checksum
                && hash_equals($row->before_checksum, $events[0]->before_checksum)
                && hash_equals($row->after_checksum, $events[0]->after_checksum) && $events[0]->reason === $reason, 409,
                'Original transaction audit evidence is missing or inconsistent; replay is held.');
        }
        abort_unless($rows->isNotEmpty(), 409, 'Original transaction rows are missing; replay is held.');
    }

    private function rollbackSnapshot(Booking $booking, SalesBookingAttribution $attribution, bool $lock = false): array
    {
        $query = DB::table('sales_collection_company_repairs as repair')
            ->where('repair.booking_id', $booking->id)->where('repair.company_id', $attribution->company_id)
            ->whereNotNull('repair.after_checksum')
            ->whereNotExists(fn ($scope) => $scope->selectRaw('1')
                ->from('sales_collection_company_repair_rollback_items as item')->whereColumn('item.repair_id', 'repair.id'))
            ->orderBy('repair.id');
        if ($lock) $query->lockForUpdate();

        $rows = [];
        foreach ($query->get(['repair.id', 'repair.source_table', 'repair.source_record_id', 'repair.before_company_id',
            'repair.after_company_id', 'repair.after_checksum']) as $repair) {
            abort_unless(isset(SalesCollectionCompanyIntegrity::BOOKING_TABLES[$repair->source_table]), 409,
                'This repair references an unsupported source and remains held.');
            $table = $repair->source_table;
            $columns = array_values(array_filter(SalesCollectionCompanyIntegrity::BOOKING_TABLES[$table],
                fn (string $column) => Schema::hasColumn($table, $column)));
            $profileColumn = SalesCollectionCompanyIntegrity::PROFILE_REFERENCES[$table] ?? null;
            $queryColumns = $profileColumn && Schema::hasColumn($table, $profileColumn)
                ? array_values(array_unique([...$columns, $profileColumn])) : $columns;
            $sourceQuery = DB::table($table)->where('id', $repair->source_record_id)->where('booking_id', $booking->id);
            if ($lock) $sourceQuery->lockForUpdate();
            $record = $sourceQuery->first(['id', 'company_id', 'updated_at', ...$queryColumns]);
            abort_unless($record && $record->company_id === $repair->after_company_id, 409,
                'A repaired collection record has changed since repair; rollback is held.');

            $reference = [];
            $values = ['id' => $record->id, 'company_id' => $record->company_id, 'updated_at' => $record->updated_at];
            foreach ($columns as $column) {
                $reference[$column] = $record->{$column};
                $values[$column] = $record->{$column};
            }
            if ($profileColumn && ! empty($record->{$profileColumn})) {
                $values[$profileColumn] = $record->{$profileColumn};
                $values['profile_company_id'] = DB::table('sales_profiles')->where('id', $record->{$profileColumn})->value('company_id');
            }
            $currentChecksum = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            abort_unless(hash_equals($repair->after_checksum, $currentChecksum), 409,
                'A repaired collection record no longer matches its audited after-state; rollback is held.');
            $rows[] = [
                'repair_id' => $repair->id, 'source_table' => $table, 'source_record_id' => (string) $record->id,
                'before_company_id' => $repair->before_company_id, 'after_company_id' => $repair->after_company_id,
                'before_company_name' => $repair->before_company_id
                    ? DB::table('companies')->where('id', $repair->before_company_id)->value('name') : null,
                'after_company_name' => DB::table('companies')->where('id', $repair->after_company_id)->value('name'),
                'reference' => array_filter($reference, fn ($value) => $value !== null && $value !== ''),
                'checksum_values' => $values, 'current_checksum' => $currentChecksum,
            ];
        }

        return ['rows' => $rows, 'checksum' => hash('sha256', json_encode([
            'booking_number' => $booking->booking_number, 'company_id' => $attribution->company_id,
            'rows' => array_map(fn (array $row) => [$row['repair_id'], $row['current_checksum']], $rows),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    private function snapshot(Booking $booking, SalesBookingAttribution $attribution, bool $lock = false): array
    {
        $rows = [];
        $blocked = false;
        foreach (SalesCollectionCompanyIntegrity::BOOKING_TABLES as $table => $referenceColumns) {
            if (in_array($table, SalesCollectionCompanyIntegrity::NON_REPAIRABLE_TABLES, true)) continue;
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'booking_id') || ! Schema::hasColumn($table, 'company_id')) continue;
            $columns = array_values(array_filter($referenceColumns, fn (string $column) => Schema::hasColumn($table, $column)));
            $profileColumn = SalesCollectionCompanyIntegrity::PROFILE_REFERENCES[$table] ?? null;
            $queryColumns = $profileColumn && Schema::hasColumn($table, $profileColumn)
                ? array_values(array_unique([...$columns, $profileColumn])) : $columns;
            $query = DB::table($table)->where('booking_id', $booking->id)->orderBy('id');
            if ($lock) $query->lockForUpdate();
            foreach ($query->get(['id', 'company_id', 'updated_at', ...$queryColumns]) as $record) {
                $reference = [];
                $checksumValues = ['id' => $record->id, 'company_id' => $record->company_id, 'updated_at' => $record->updated_at];
                foreach ($columns as $column) {
                    $reference[$column] = $record->{$column};
                    $checksumValues[$column] = $record->{$column};
                }
                if ($profileColumn && ! empty($record->{$profileColumn})) {
                    $checksumValues[$profileColumn] = $record->{$profileColumn};
                    $profileCompany = DB::table('sales_profiles')->where('id', $record->{$profileColumn})->value('company_id');
                    if ($profileCompany !== $attribution->company_id) $blocked = true;
                    $checksumValues['profile_company_id'] = $profileCompany;
                }
                if ($record->company_id === $attribution->company_id) continue;
                $rows[] = [
                    'table' => $table, 'id' => (string) $record->id, 'before_company_id' => $record->company_id,
                    'before_company_name' => $record->company_id ? DB::table('companies')->where('id', $record->company_id)->value('name') : null,
                    'reference' => array_filter($reference, fn ($value) => $value !== null && $value !== ''),
                    'checksum_values' => $checksumValues,
                ];
            }
        }
        $checksumRows = array_map(fn (array $row) => [$row['table'], $row['checksum_values']], $rows);

        return ['rows' => $rows, 'blocked' => $blocked, 'checksum' => hash('sha256', json_encode([
            'booking_number' => $booking->booking_number, 'company_id' => $attribution->company_id, 'rows' => $checksumRows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }
}
