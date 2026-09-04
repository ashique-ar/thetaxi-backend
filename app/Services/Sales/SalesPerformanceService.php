<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesAlertPolicyVersion;
use App\Models\Sales\SalesKpiSnapshot;
use App\Models\Sales\SalesKpiSnapshotRow;
use App\Models\Sales\SalesMetricFact;
use App\Models\Sales\SalesPerformanceAlert;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesTargetCopyBatch;
use App\Models\Sales\SalesTargetVersion;
use App\Models\Staff;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

class SalesPerformanceService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesAlertPolicyContract $alertPolicyContract,
        private readonly SalesTaskInterventionFactService $taskInterventionFacts,
        private readonly SalesMetricBreakdownService $metricBreakdowns,
        private readonly SalesFrozenCollectionAgingService $frozenAging,
    ) {}

    public const RANKING_POLICY = [
        'method' => 'lexicographic',
        'keys' => ['new_sales_achievement_percent', 'collection_achievement_percent', 'net_new_sales_lkr', 'eligible_collections_lkr'],
        'direction' => 'descending', 'missing_target' => 'n/a_after_configured_targets', 'ties' => 'equal_display_rank',
        'display_identity' => 'staff_code',
    ];

    public function createTarget(
        array $data,
        string $idempotencyKey,
        string $actorUserId,
        ?string $sourceIp,
    ): SalesTargetVersion
    {
        return DB::transaction(function () use ($data, $idempotencyKey, $actorUserId, $sourceIp) {
            DB::table('companies')->whereKey($data['company_id'])->lockForUpdate()->firstOrFail();
            $profile = SalesProfile::query()->lockForUpdate()->findOrFail($data['sales_profile_id']);
            abort_unless($profile->company_id === $data['company_id'], 422, 'Target and Sales Profile legal entities must match.');
            $start = CarbonImmutable::parse($data['period_start']);
            $end = CarbonImmutable::parse($data['period_end']);
            abort_unless($start->isStartOfMonth() && $end->isSameDay($start->endOfMonth()), 422, 'Sales targets must be defined for a complete calendar month.');
            abort_if(($data['new_sales_target_lkr'] ?? null) === null && ($data['eligible_collections_target_lkr'] ?? null) === null, 422, 'At least one target must be configured; missing is distinct from zero.');
            $requestEvidence = [
                'company_id' => $profile->company_id, 'sales_profile_id' => $profile->id,
                'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'new_sales_target_lkr' => $data['new_sales_target_lkr'] ?? null,
                'eligible_collections_target_lkr' => $data['eligible_collections_target_lkr'] ?? null,
                'reason' => trim($data['reason']), 'actor_user_id' => $actorUserId,
            ];
            $requestChecksum = hash('sha256', CanonicalJson::encode($requestEvidence));
            $existing = SalesTargetVersion::query()->where('company_id', $profile->company_id)
                ->where('draft_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->draft_request_checksum, $requestChecksum), 409,
                    'The target-draft idempotency key was reused with different evidence.');
                $auditEventId = DB::table('domain_audit_events')
                    ->where('domain', 'sales')->where('subject_type', 'sales_target_version')
                    ->where('subject_id', $existing->id)->where('event_type', 'sales.performance.target_draft_created')
                    ->where('correlation_id', $idempotencyKey)->value('id');
                $outboxEventId = DB::table('domain_outbox_events')
                    ->where('domain', 'sales')->where('aggregate_type', 'sales_target_version')
                    ->where('aggregate_id', $existing->id)->where('event_type', 'sales.performance.target_draft_created')
                    ->where('correlation_id', $idempotencyKey)->value('id');
                abort_unless(is_string($auditEventId) && is_string($outboxEventId), 409,
                    'The original target-draft audit evidence is unavailable; the command cannot be replayed safely.');

                return $this->withTargetCommandEvidence(
                    $existing, $auditEventId, $outboxEventId, $idempotencyKey, true,
                );
            }
            // The legal-entity row lock above serializes target-version allocation;
            // PostgreSQL does not permit FOR UPDATE on an aggregate MAX query.
            $version = (int) SalesTargetVersion::query()->where('sales_profile_id', $profile->id)
                ->whereDate('period_start', $start)->whereDate('period_end', $end)->max('version') + 1;
            $payload = [
                'company_id' => $profile->company_id, 'sales_profile_id' => $profile->id, 'source' => 'manual',
                'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'new_sales_target_lkr' => $data['new_sales_target_lkr'] ?? null,
                'eligible_collections_target_lkr' => $data['eligible_collections_target_lkr'] ?? null,
                'status' => 'draft', 'version' => $version, 'reason' => trim($data['reason']),
                'draft_idempotency_key' => $idempotencyKey, 'draft_request_checksum' => $requestChecksum,
                'prepared_by' => $actorUserId, 'prepared_at' => now(),
            ];
            $payload['payload_checksum'] = hash('sha256', CanonicalJson::encode($payload));
            $target = SalesTargetVersion::create($payload);
            $auditEventId = (string) Str::uuid();
            DB::table('domain_audit_events')->insert([
                'id' => $auditEventId, 'domain' => 'sales', 'company_id' => $profile->company_id,
                'subject_type' => 'sales_target_version', 'subject_id' => $target->id,
                'event_type' => 'sales.performance.target_draft_created', 'actor_user_id' => $actorUserId,
                'actor_type' => 'user', 'correlation_id' => $idempotencyKey, 'source_ip' => $sourceIp,
                'before_checksum' => null, 'after_checksum' => $target->payload_checksum,
                'reason' => trim($data['reason']), 'occurred_at' => $target->prepared_at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $outboxEventId = $this->events->record('sales', $profile->company_id, 'sales_target_version', $target->id,
                'sales.performance.target_draft_created', 1, 1, [
                    'sales_profile_id' => $profile->id, 'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(), 'version' => $version,
                    'payload_checksum' => $target->payload_checksum, 'request_checksum' => $requestChecksum,
                ], $target->prepared_at, $idempotencyKey);

            return $this->withTargetCommandEvidence(
                $target, $auditEventId, $outboxEventId, $idempotencyKey, false,
            );
        }, 3);
    }

    public function approveTarget(
        SalesTargetVersion $target,
        int $expectedVersion,
        string $reason,
        string $idempotencyKey,
        string $actorUserId,
        ?string $sourceIp,
    ): SalesTargetVersion
    {
        return DB::transaction(function () use (
            $target, $expectedVersion, $reason, $idempotencyKey, $actorUserId, $sourceIp,
        ) {
            DB::table('companies')->whereKey($target->company_id)->lockForUpdate()->firstOrFail();
            $locked = SalesTargetVersion::query()->lockForUpdate()->findOrFail($target->id);
            $requestChecksum = hash('sha256', CanonicalJson::encode([
                'target_id' => $locked->id, 'expected_version' => $expectedVersion,
                'reason' => trim($reason), 'actor_user_id' => $actorUserId,
            ]));
            $replay = SalesTargetVersion::query()->where('company_id', $locked->company_id)
                ->where('approval_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($replay) {
                abort_unless($replay->id === $locked->id
                    && hash_equals((string) $replay->approval_request_checksum, $requestChecksum), 409,
                    'The target-approval idempotency key was reused with different evidence.');
                $auditEventId = DB::table('domain_audit_events')
                    ->where('domain', 'sales')->where('subject_type', 'sales_target_version')
                    ->where('subject_id', $replay->id)->where('event_type', 'sales.performance.target_approved')
                    ->where('correlation_id', $idempotencyKey)->value('id');
                $outboxEventId = DB::table('domain_outbox_events')
                    ->where('domain', 'sales')->where('aggregate_type', 'sales_target_version')
                    ->where('aggregate_id', $replay->id)->where('event_type', 'sales.performance.target_approved')
                    ->where('correlation_id', $idempotencyKey)->value('id');
                abort_unless(is_string($auditEventId) && is_string($outboxEventId), 409,
                    'The original target-approval audit evidence is unavailable; the command cannot be replayed safely.');

                return $this->withTargetCommandEvidence(
                    $replay, $auditEventId, $outboxEventId, $idempotencyKey, true,
                );
            }
            abort_unless((int) $locked->version === $expectedVersion, 409,
                'The target version is stale; refresh the target history before approval.');
            abort_unless($locked->status === 'draft', 422, 'Only draft targets can be approved.');
            abort_if($locked->prepared_by === $actorUserId, 403, 'Target maker and approver must be different users.');
            $timezone = config('sales.business_timezone');
            abort_unless(is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true), 409,
                'An approved Sales business timezone is required before target approval.');
            $startUtc = CarbonImmutable::parse($locked->period_start->toDateString(), $timezone)->startOfDay()->utc();
            $endExclusiveUtc = CarbonImmutable::parse($locked->period_end->toDateString(), $timezone)->addDay()->startOfDay()->utc();
            abort_if(DB::table('domain_period_locks')->where('domain', 'sales')->where('company_id', $locked->company_id)
                ->where('period_type', 'month')->where('period_start', $startUtc)->where('period_end', $endExclusiveUtc)
                ->where('state', 'locked')->exists(), 409,
                'The Sales performance period is locked; reopen it before approving a replacement target.');
            $supersededIds = SalesTargetVersion::query()->where('sales_profile_id', $locked->sales_profile_id)
                ->whereDate('period_start', $locked->period_start)->whereDate('period_end', $locked->period_end)
                ->where('status', 'approved')->lockForUpdate()->pluck('id')->all();
            $beforeChecksum = hash('sha256', CanonicalJson::encode([
                'target_id' => $locked->id, 'status' => $locked->status,
                'version' => (int) $locked->version, 'payload_checksum' => $locked->payload_checksum,
            ]));
            SalesTargetVersion::query()->whereIn('id', $supersededIds)->update([
                'status' => 'superseded', 'updated_at' => now(),
            ]);
            $approvedAt = now();
            $locked->update([
                'status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => $approvedAt,
                'approval_reason' => trim($reason), 'approval_idempotency_key' => $idempotencyKey,
                'approval_request_checksum' => $requestChecksum,
            ]);
            $afterChecksum = hash('sha256', CanonicalJson::encode([
                'target_id' => $locked->id, 'status' => 'approved', 'version' => (int) $locked->version,
                'payload_checksum' => $locked->payload_checksum, 'approval_request_checksum' => $requestChecksum,
                'superseded_target_ids' => $supersededIds,
            ]));
            $auditEventId = (string) Str::uuid();
            DB::table('domain_audit_events')->insert([
                'id' => $auditEventId, 'domain' => 'sales', 'company_id' => $locked->company_id,
                'subject_type' => 'sales_target_version', 'subject_id' => $locked->id,
                'event_type' => 'sales.performance.target_approved', 'actor_user_id' => $actorUserId,
                'actor_type' => 'user', 'correlation_id' => $idempotencyKey, 'source_ip' => $sourceIp,
                'before_checksum' => $beforeChecksum, 'after_checksum' => $afterChecksum,
                'reason' => trim($reason), 'occurred_at' => $approvedAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $outboxEventId = $this->events->record('sales', $locked->company_id, 'sales_target_version', $locked->id,
                'sales.performance.target_approved', 2, 1, [
                    'sales_profile_id' => $locked->sales_profile_id,
                    'period_start' => $locked->period_start->toDateString(),
                    'period_end' => $locked->period_end->toDateString(), 'version' => (int) $locked->version,
                    'payload_checksum' => $locked->payload_checksum, 'approval_request_checksum' => $requestChecksum,
                    'superseded_target_ids' => $supersededIds,
                ], $approvedAt, $idempotencyKey);

            return $this->withTargetCommandEvidence(
                $locked->refresh(), $auditEventId, $outboxEventId, $idempotencyKey, false,
            );
        }, 3);
    }

    private function withTargetCommandEvidence(
        SalesTargetVersion $target,
        string $auditEventId,
        string $outboxEventId,
        string $correlationId,
        bool $idempotentReplay,
    ): SalesTargetVersion {
        $target->setAttribute('audit_event_id', $auditEventId);
        $target->setAttribute('outbox_event_id', $outboxEventId);
        $target->setAttribute('correlation_id', $correlationId);
        $target->setAttribute('idempotent_replay', $idempotentReplay);

        return $target;
    }

    public function previewTargetCopy(
        string $companyId,
        string $sourcePeriodStart,
        string $targetPeriodStart,
        array $profileIds,
    ): array {
        [$sourceStart, $sourceEnd] = $this->monthlyPeriod($sourcePeriodStart);
        [$targetStart, $targetEnd] = $this->monthlyPeriod($targetPeriodStart);
        abort_if($sourceStart->equalTo($targetStart), 422, 'Source and destination target months must be different.');

        $profileIds = array_values(array_unique($profileIds));
        sort($profileIds, SORT_STRING);
        abort_if($profileIds === [], 422, 'Select at least one authorised Sales Profile.');
        $profiles = SalesProfile::query()->withTrashed()->with('staff')->where('company_id', $companyId)
            ->whereIn('id', $profileIds)->get()->keyBy('id');
        abort_unless($profiles->count() === count($profileIds), 422,
            'Every selected Sales Profile must belong to the selected legal entity.');

        $rows = collect($profileIds)->map(function (string $profileId) use (
            $profiles, $sourceStart, $sourceEnd, $targetStart, $targetEnd
        ) {
            $profile = $profiles->get($profileId);
            $profileEligible = $profile && ! $profile->trashed() && $profile->status === 'active'
                && $profile->effective_from?->lte($targetEnd)
                && (! $profile->effective_until || $profile->effective_until->gt($targetStart))
                && $profile->staff && ! $profile->staff->trashed()
                && (! $profile->staff?->employment_ended_at || $profile->staff->employment_ended_at->gt($targetStart));
            $sources = SalesTargetVersion::query()->where('sales_profile_id', $profileId)
                ->whereDate('period_start', $sourceStart)->whereDate('period_end', $sourceEnd)
                ->where('status', 'approved')->get();
            $source = $sources->count() === 1 ? $sources->first() : null;
            $destination = SalesTargetVersion::query()->where('sales_profile_id', $profileId)
                ->whereDate('period_start', $targetStart)->whereDate('period_end', $targetEnd)
                ->orderByDesc('version')->first();
            $blocker = ! $profileEligible ? 'profile_not_effective'
                : ($sources->isEmpty() ? 'source_not_configured'
                    : ($sources->count() > 1 ? 'source_approved_ambiguous' : null));

            return [
                'sales_profile_id' => $profileId,
                'source_target_id' => $source?->id,
                'source_version' => $source?->version,
                'new_sales_target_lkr' => $source?->new_sales_target_lkr,
                'eligible_collections_target_lkr' => $source?->eligible_collections_target_lkr,
                'destination_latest_target_id' => $destination?->id,
                'destination_latest_version' => $destination?->version,
                'destination_latest_status' => $destination?->status,
                'action' => $blocker ? 'blocked' : 'create_draft',
                'blocker' => $blocker,
            ];
        })->values()->all();
        $snapshot = [
            'company_id' => $companyId,
            'source_period_start' => $sourceStart->toDateString(),
            'source_period_end' => $sourceEnd->toDateString(),
            'target_period_start' => $targetStart->toDateString(),
            'target_period_end' => $targetEnd->toDateString(),
            'rows' => $rows,
        ];

        return [
            ...$snapshot,
            'copyable_count' => collect($rows)->where('action', 'create_draft')->count(),
            'blocked_count' => collect($rows)->where('action', 'blocked')->count(),
            'preview_checksum' => hash('sha256', CanonicalJson::encode($snapshot)),
            'write_performed' => false,
        ];
    }

    public function copyTargets(
        string $companyId,
        string $sourcePeriodStart,
        string $targetPeriodStart,
        array $profileIds,
        string $previewChecksum,
        string $reason,
        string $idempotencyKey,
        string $actorUserId,
    ): SalesTargetCopyBatch {
        $profileIds = array_values(array_unique($profileIds));
        sort($profileIds, SORT_STRING);
        $requestChecksum = hash('sha256', CanonicalJson::encode([
            'company_id' => $companyId, 'source_period_start' => $sourcePeriodStart,
            'target_period_start' => $targetPeriodStart, 'profile_ids' => array_values($profileIds),
            'preview_checksum' => $previewChecksum, 'reason' => trim($reason), 'actor_user_id' => $actorUserId,
        ]));

        return DB::transaction(function () use ($companyId, $sourcePeriodStart, $targetPeriodStart, $profileIds,
            $previewChecksum, $reason, $idempotencyKey, $actorUserId, $requestChecksum) {
            $existing = SalesTargetCopyBatch::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_payload_checksum, $requestChecksum), 409,
                    'The target-copy idempotency key was reused with different evidence.');
                return $existing->load('targets');
            }

            [$sourceStart, $sourceEnd] = $this->monthlyPeriod($sourcePeriodStart);
            [$targetStart, $targetEnd] = $this->monthlyPeriod($targetPeriodStart);
            $profileIds = array_values(array_unique($profileIds));
            sort($profileIds, SORT_STRING);
            DB::table('companies')->whereKey($companyId)->lockForUpdate()->firstOrFail();
            SalesProfile::query()->withTrashed()->whereIn('id', $profileIds)->orderBy('id')->lockForUpdate()->get();
            SalesTargetVersion::query()->whereIn('sales_profile_id', $profileIds)
                ->where(function ($periods) use ($sourceStart, $sourceEnd, $targetStart, $targetEnd) {
                    $periods->where(fn ($source) => $source->whereDate('period_start', $sourceStart)->whereDate('period_end', $sourceEnd))
                        ->orWhere(fn ($target) => $target->whereDate('period_start', $targetStart)->whereDate('period_end', $targetEnd));
                })->orderBy('sales_profile_id')->orderBy('version')->lockForUpdate()->get();
            $preview = $this->previewTargetCopy($companyId, $sourcePeriodStart, $targetPeriodStart, $profileIds);
            abort_unless(hash_equals($preview['preview_checksum'], $previewChecksum), 409,
                'The approved source or destination target evidence changed; refresh the copy preview.');
            abort_unless($preview['copyable_count'] > 0, 422, 'No selected Profile has one approved source target to copy.');

            $batch = SalesTargetCopyBatch::create([
                'company_id' => $companyId,
                'source_period_start' => $sourceStart->toDateString(), 'source_period_end' => $sourceEnd->toDateString(),
                'target_period_start' => $targetStart->toDateString(), 'target_period_end' => $targetEnd->toDateString(),
                'selection_snapshot' => $preview['rows'], 'preview_checksum' => $previewChecksum,
                'reason' => trim($reason), 'prepared_by' => $actorUserId, 'prepared_at' => now(),
                'idempotency_key' => $idempotencyKey, 'request_payload_checksum' => $requestChecksum,
            ]);
            foreach ($preview['rows'] as $row) {
                if ($row['action'] !== 'create_draft') continue;
                $version = (int) SalesTargetVersion::query()->where('sales_profile_id', $row['sales_profile_id'])
                    ->whereDate('period_start', $targetStart)->whereDate('period_end', $targetEnd)->max('version') + 1;
                $payload = [
                    'company_id' => $companyId, 'sales_profile_id' => $row['sales_profile_id'],
                    'source' => 'approved_month_copy',
                    'copy_batch_id' => $batch->id, 'copied_from_target_id' => $row['source_target_id'],
                    'period_start' => $targetStart->toDateString(), 'period_end' => $targetEnd->toDateString(),
                    'new_sales_target_lkr' => $row['new_sales_target_lkr'],
                    'eligible_collections_target_lkr' => $row['eligible_collections_target_lkr'],
                    'status' => 'draft', 'version' => $version, 'reason' => trim($reason),
                    'prepared_by' => $actorUserId, 'prepared_at' => now(),
                ];
                $payload['payload_checksum'] = hash('sha256', CanonicalJson::encode($payload));
                SalesTargetVersion::create($payload);
            }

            $this->events->record('sales', $companyId, 'sales_target_copy_batch', $batch->id,
                'sales.performance.targets_copied_to_draft', 1, 1, [
                    'source_period_start' => $sourceStart->toDateString(),
                    'target_period_start' => $targetStart->toDateString(),
                    'copied_count' => $preview['copyable_count'], 'blocked_count' => $preview['blocked_count'],
                    'preview_checksum' => $previewChecksum,
                ], $batch->prepared_at, $idempotencyKey);

            return $batch->load('targets');
        }, 3);
    }

    public function previewTargetImport(
        string $companyId,
        string $targetPeriodStart,
        UploadedFile $file,
        ?array $authorizedProfileIds,
    ): array {
        [$targetStart, $targetEnd] = $this->monthlyPeriod($targetPeriodStart);
        $temporaryPath = $file->getRealPath();
        abort_unless(is_string($temporaryPath) && $temporaryPath !== '', 422, 'The target import file could not be read.');
        $fileChecksum = hash_file('sha256', $temporaryPath);
        abort_unless(is_string($fileChecksum) && strlen($fileChecksum) === 64, 422,
            'The target import file checksum could not be created.');

        $parsed = $this->parseTargetImportCsv($temporaryPath);
        $codes = collect($parsed)->pluck('sales_code')->filter()->unique()->values();
        $profiles = SalesProfile::query()->withTrashed()->with('staff')->where('company_id', $companyId)
            ->whereIn('sales_code', $codes)
            ->when($authorizedProfileIds !== null, fn ($query) => $query->whereIn('id', $authorizedProfileIds))
            ->get()->keyBy('sales_code');
        $duplicateCodes = collect($parsed)->pluck('sales_code')->filter()->countBy()->filter(fn ($count) => $count > 1);

        $rows = collect($parsed)->map(function (array $row) use (
            $profiles, $duplicateCodes, $targetStart, $targetEnd
        ): array {
            $profile = $profiles->get($row['sales_code']);
            $errors = $row['errors'];
            if ($row['sales_code'] !== '' && $duplicateCodes->has($row['sales_code'])) {
                $errors[] = ['field_name' => 'sales_code', 'error_code' => 'duplicate_sales_code',
                    'message' => 'The Sales code appears more than once in this file.'];
            }
            if ($row['sales_code'] !== '' && ! $profile) {
                $errors[] = ['field_name' => 'sales_code', 'error_code' => 'profile_not_found_or_outside_scope',
                    'message' => 'The Sales code is not available in the selected legal entity and authorised scope.'];
            }
            $profileEffective = $profile && ! $profile->trashed() && $profile->status === 'active'
                && $profile->effective_from?->lte($targetEnd)
                && (! $profile->effective_until || $profile->effective_until->gt($targetStart))
                && $profile->staff && ! $profile->staff->trashed()
                && (! $profile->staff->employment_ended_at || $profile->staff->employment_ended_at->gt($targetStart));
            if ($profile && ! $profileEffective) {
                $errors[] = ['field_name' => 'sales_code', 'error_code' => 'profile_not_effective',
                    'message' => 'The Sales Profile is not effective for the destination month.'];
            }
            $destination = $profile ? SalesTargetVersion::query()->where('sales_profile_id', $profile->id)
                ->whereDate('period_start', $targetStart)->whereDate('period_end', $targetEnd)
                ->orderByDesc('version')->first() : null;

            return [
                'row_number' => $row['row_number'], 'sales_code' => $row['sales_code'],
                'sales_profile_id' => $profile?->id,
                'new_sales_target_lkr' => $row['new_sales_target_lkr'],
                'eligible_collections_target_lkr' => $row['eligible_collections_target_lkr'],
                'destination_latest_version' => $destination?->version,
                'destination_latest_status' => $destination?->status,
                'action' => $errors === [] ? 'create_draft' : 'blocked', 'errors' => $errors,
                'row_checksum' => hash('sha256', CanonicalJson::encode([
                    'row_number' => $row['row_number'], 'sales_code' => $row['sales_code'],
                    'new_sales_target_lkr' => $row['new_sales_target_lkr'],
                    'eligible_collections_target_lkr' => $row['eligible_collections_target_lkr'],
                ])),
            ];
        })->values()->all();
        $snapshot = [
            'company_id' => $companyId, 'target_period_start' => $targetStart->toDateString(),
            'target_period_end' => $targetEnd->toDateString(), 'file_checksum' => $fileChecksum,
            'required_headers' => ['sales_code', 'new_sales_target_lkr', 'eligible_collections_target_lkr'],
            'rows' => $rows,
        ];

        return [...$snapshot,
            'input_rows' => count($rows),
            'accepted_rows' => collect($rows)->where('action', 'create_draft')->count(),
            'rejected_rows' => collect($rows)->where('action', 'blocked')->count(),
            'preview_checksum' => hash('sha256', CanonicalJson::encode($snapshot)),
            'write_performed' => false,
        ];
    }

    public function importTargets(
        string $companyId,
        string $targetPeriodStart,
        UploadedFile $file,
        ?array $authorizedProfileIds,
        string $previewChecksum,
        string $reason,
        string $idempotencyKey,
        string $actorUserId,
    ): object {
        $preview = $this->previewTargetImport($companyId, $targetPeriodStart, $file, $authorizedProfileIds);
        $requestChecksum = hash('sha256', CanonicalJson::encode([
            'company_id' => $companyId, 'target_period_start' => $preview['target_period_start'],
            'file_checksum' => $preview['file_checksum'], 'preview_checksum' => $previewChecksum,
            'reason' => trim($reason), 'actor_user_id' => $actorUserId,
        ]));
        $storedPath = null;

        try {
            return DB::transaction(function () use (
                $companyId, $targetPeriodStart, $file, $authorizedProfileIds, $preview, $previewChecksum,
                $reason, $idempotencyKey, $actorUserId, $requestChecksum, &$storedPath
            ): object {
                DB::table('companies')->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $existing = DB::table('domain_transfer_jobs')->where('domain', 'sales')
                    ->where('job_type', 'sales_target_csv')->where('company_id', $companyId)
                    ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    abort_unless(hash_equals((string) $existing->request_payload_checksum, $requestChecksum), 409,
                        'The target-import idempotency key was reused with different evidence.');
                    if ($authorizedProfileIds !== null) {
                        abort_if(SalesTargetVersion::query()->where('import_job_id', $existing->id)
                            ->whereNotIn('sales_profile_id', $authorizedProfileIds)->exists(), 403,
                            'The original target import now contains a Sales Profile outside your current scope.');
                    }
                    return $this->targetImportResult($existing->id);
                }

                abort_unless(hash_equals($preview['preview_checksum'], $previewChecksum), 409,
                    'The target import file or destination evidence changed; refresh the import preview.');
                abort_unless($preview['accepted_rows'] > 0, 422, 'No import row is eligible to create a target draft.');
                $profileIds = collect($preview['rows'])->where('action', 'create_draft')->pluck('sales_profile_id')->all();
                SalesProfile::query()->withTrashed()->whereIn('id', $profileIds)->orderBy('id')->lockForUpdate()->get();
                [$targetStart, $targetEnd] = $this->monthlyPeriod($preview['target_period_start']);
                SalesTargetVersion::query()->whereIn('sales_profile_id', $profileIds)
                    ->whereDate('period_start', $targetStart)->whereDate('period_end', $targetEnd)
                    ->orderBy('sales_profile_id')->orderBy('version')->lockForUpdate()->get();
                $preview = $this->previewTargetImport(
                    $companyId, $targetPeriodStart, $file, $authorizedProfileIds
                );
                abort_unless(hash_equals($preview['preview_checksum'], $previewChecksum), 409,
                    'The target import scope, file or destination evidence changed; refresh the import preview.');

                $jobId = (string) Str::uuid();
                $extension = strtolower((string) $file->getClientOriginalExtension());
                $storedPath = $companyId.'/target-imports/'.now()->format('Y/m').'/'.$jobId.'.'.($extension ?: 'csv');
                Storage::disk('sales_private')->putFileAs(dirname($storedPath), $file, basename($storedPath));
                $now = now();
                DB::table('domain_transfer_jobs')->insert([
                    'id' => $jobId, 'domain' => 'sales', 'company_id' => $companyId, 'direction' => 'import',
                    'job_type' => 'sales_target_csv', 'format' => 'csv', 'state' => 'completed',
                    'disk' => 'sales_private', 'path' => $storedPath, 'file_checksum' => $preview['file_checksum'],
                    'input_rows' => $preview['input_rows'], 'accepted_rows' => $preview['accepted_rows'],
                    'rejected_rows' => $preview['rejected_rows'], 'requested_by' => $actorUserId,
                    'queued_at' => $now, 'started_at' => $now, 'completed_at' => $now,
                    'failure_summary' => $preview['rejected_rows'] > 0 ? 'Some rows were rejected; inspect immutable row errors.' : null,
                    'reason' => trim($reason), 'preview_checksum' => $previewChecksum,
                    'request_payload_checksum' => $requestChecksum, 'idempotency_key' => $idempotencyKey,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($preview['rows'] as $row) {
                    foreach ($row['errors'] as $error) {
                        DB::table('domain_transfer_job_errors')->insert([
                            'id' => (string) Str::uuid(), 'transfer_job_id' => $jobId,
                            'row_number' => $row['row_number'], 'field_name' => $error['field_name'],
                            'error_code' => $error['error_code'], 'message' => $error['message'],
                            'row_checksum' => $row['row_checksum'], 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                    if ($row['action'] !== 'create_draft') continue;
                    $version = (int) SalesTargetVersion::query()->where('sales_profile_id', $row['sales_profile_id'])
                        ->whereDate('period_start', $targetStart)->whereDate('period_end', $targetEnd)->max('version') + 1;
                    $payload = [
                        'company_id' => $companyId, 'sales_profile_id' => $row['sales_profile_id'],
                        'source' => 'csv_import', 'import_job_id' => $jobId, 'import_row_number' => $row['row_number'],
                        'period_start' => $targetStart->toDateString(), 'period_end' => $targetEnd->toDateString(),
                        'new_sales_target_lkr' => $row['new_sales_target_lkr'],
                        'eligible_collections_target_lkr' => $row['eligible_collections_target_lkr'],
                        'status' => 'draft', 'version' => $version, 'reason' => trim($reason),
                        'prepared_by' => $actorUserId, 'prepared_at' => $now,
                    ];
                    $payload['payload_checksum'] = hash('sha256', CanonicalJson::encode($payload));
                    SalesTargetVersion::create($payload);
                }
                $this->events->record('sales', $companyId, 'domain_transfer_job', $jobId,
                    'sales.performance.targets_imported_to_draft', 1, 1, [
                        'target_period_start' => $targetStart->toDateString(),
                        'input_rows' => $preview['input_rows'], 'accepted_rows' => $preview['accepted_rows'],
                        'rejected_rows' => $preview['rejected_rows'], 'file_checksum' => $preview['file_checksum'],
                        'preview_checksum' => $previewChecksum,
                    ], $now, $idempotencyKey);

                return $this->targetImportResult($jobId);
            }, 3);
        } catch (\Throwable $exception) {
            if ($storedPath) Storage::disk('sales_private')->delete($storedPath);
            throw $exception;
        }
    }

    private function parseTargetImportCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        abort_unless(is_resource($handle), 422, 'The target import CSV could not be opened.');
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            abort_unless(is_array($header), 422, 'The target import CSV is empty.');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? ''));
            $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
            $required = ['sales_code', 'new_sales_target_lkr', 'eligible_collections_target_lkr'];
            abort_unless($header === $required, 422,
                'CSV headers must be exactly: sales_code,new_sales_target_lkr,eligible_collections_target_lkr.');
            $rows = []; $rowNumber = 1;
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rowNumber++;
                abort_if($rowNumber > 1001, 422, 'A target import is limited to 1000 data rows.');
                $values = array_pad($values, 3, null);
                $errors = [];
                if (count($values) !== 3) {
                    $errors[] = ['field_name' => null, 'error_code' => 'column_count_invalid',
                        'message' => 'The row must contain exactly three columns.'];
                }
                $salesCode = trim((string) ($values[0] ?? ''));
                if ($salesCode === '') {
                    $errors[] = ['field_name' => 'sales_code', 'error_code' => 'sales_code_required',
                        'message' => 'Sales code is required.'];
                } elseif (mb_strlen($salesCode) > 80) {
                    $errors[] = ['field_name' => 'sales_code', 'error_code' => 'sales_code_too_long',
                        'message' => 'Sales code cannot exceed 80 characters.'];
                }
                $newSales = $this->targetImportDecimal($values[1] ?? null, 'new_sales_target_lkr', $errors);
                $collections = $this->targetImportDecimal($values[2] ?? null, 'eligible_collections_target_lkr', $errors);
                if ($newSales === null && $collections === null
                    && ! collect($errors)->contains(fn ($error) => str_ends_with($error['error_code'], '_invalid'))) {
                    $errors[] = ['field_name' => null, 'error_code' => 'target_amount_required',
                        'message' => 'At least one target amount is required; blank remains distinct from zero.'];
                }
                $rows[] = [
                    'row_number' => $rowNumber, 'sales_code' => $salesCode,
                    'new_sales_target_lkr' => $newSales,
                    'eligible_collections_target_lkr' => $collections, 'errors' => $errors,
                ];
            }
            abort_if($rows === [], 422, 'The target import CSV has no data rows.');
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function targetImportDecimal(mixed $value, string $field, array &$errors): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (! preg_match('/^(\d{1,16})(?:\.(\d{1,4}))?$/', $value, $matches)) {
            $errors[] = ['field_name' => $field, 'error_code' => $field.'_invalid',
                'message' => 'Use a non-negative LKR amount with at most 16 whole digits and 4 decimal places.'];
            return null;
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($matches[2] ?? '', '0');
        return $whole.($fraction !== '' ? '.'.$fraction : '');
    }

    private function targetImportResult(string $jobId): object
    {
        $job = DB::table('domain_transfer_jobs')->whereKey($jobId)->firstOrFail();
        $job->errors = DB::table('domain_transfer_job_errors')->where('transfer_job_id', $jobId)
            ->orderBy('row_number')->orderBy('field_name')->get();
        $job->targets = SalesTargetVersion::query()->where('import_job_id', $jobId)
            ->orderBy('import_row_number')->get();
        unset($job->disk, $job->path, $job->request_payload_checksum, $job->idempotency_key);
        return $job;
    }

    private function monthlyPeriod(string $periodStart): array
    {
        $start = CarbonImmutable::parse($periodStart);
        abort_unless($start->isStartOfMonth(), 422, 'Target periods must start on the first calendar day of a month.');

        return [$start->startOfDay(), $start->endOfMonth()->startOfDay()];
    }

    public function preview(string $companyId, string $from, string $to, $cutoff, ?array $alertRules = null): array
    {
        $rows = $this->calculateRows($companyId, $from, $to, $cutoff, $alertRules)->values();
        return [
            'ranking_policy' => self::RANKING_POLICY,
            'aging_missing_lineage_count' => (int) ($rows->max('aging_profile_lineage_missing_count') ?? 0),
            'rows' => $rows->all(),
        ];
    }

    public function freeze(string $companyId, string $from, string $to, string $periodType, $cutoff, string $key, string $actorUserId): SalesKpiSnapshot
    {
        abort(409, 'Direct snapshot generation is disabled; use the governed period-close preview and commit workflow.');
    }

    public function storeAlertPolicy(array $data, string $actorUserId): SalesAlertPolicyVersion
    {
        $data['rules'] = $this->alertPolicyContract->normalize($data['rules']);
        abort_unless(Staff::query()->where('company_id', $data['company_id'])
            ->where('user_id', $data['rules']['evaluation']['owner_user_id'])
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->exists(), 422, 'The alert owner must be an active internal Staff user in the selected legal entity.');
        return DB::transaction(function () use ($data, $actorUserId) {
            DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->first();
            $version = (int) SalesAlertPolicyVersion::query()->where('company_id', $data['company_id'])->lockForUpdate()->max('version') + 1;
            return SalesAlertPolicyVersion::create($data + [
                'version' => $version, 'status' => 'draft', 'prepared_by' => $actorUserId,
                'rules_checksum' => hash('sha256', CanonicalJson::encode($data['rules'])),
            ]);
        });
    }

    public function approveAlertPolicy(SalesAlertPolicyVersion $policy, string $actorUserId): SalesAlertPolicyVersion
    {
        return DB::transaction(function () use ($policy, $actorUserId) {
            DB::table('companies')->where('id', $policy->company_id)->lockForUpdate()->first();
            $locked = SalesAlertPolicyVersion::query()->lockForUpdate()->findOrFail($policy->id);
            if ($locked->status === 'approved') return $locked;
            abort_if($locked->prepared_by === $actorUserId, 403, 'Alert policy maker and approver must be different users.');
            abort_unless($locked->status === 'draft', 422, 'Only draft alert policies can be approved.');
            SalesAlertPolicyVersion::query()->where('company_id', $locked->company_id)->where('status', 'approved')
                ->whereDate('effective_from', '<=', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $locked->effective_from))
                ->update(['status' => 'superseded']);
            $locked->update(['status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => now()]);
            return $locked->refresh();
        });
    }

    public function actOnAlert(
        SalesPerformanceAlert $alert,
        string $action,
        int $expectedVersion,
        string $reason,
        string $key,
        string $actorUserId,
        ?string $snoozedUntil = null,
    ): SalesPerformanceAlert
    {
        return DB::transaction(function () use ($alert, $action, $expectedVersion, $reason, $key, $actorUserId, $snoozedUntil) {
            $locked = SalesPerformanceAlert::query()->lockForUpdate()->findOrFail($alert->id);
            $reason = trim($reason);
            $snoozeAt = $snoozedUntil === null ? null : CarbonImmutable::parse($snoozedUntil)->utc();
            $payload = [
                'alert_id' => $locked->id, 'action' => $action, 'expected_version' => $expectedVersion,
                'reason' => $reason, 'snoozed_until' => $snoozeAt?->toIso8601String(), 'actor_user_id' => $actorUserId,
            ];
            $checksum = hash('sha256', CanonicalJson::encode($payload));
            $replay = DB::table('sales_performance_alert_action_events')
                ->where('company_id', $locked->company_id)->where('idempotency_key', $key)->first();
            if ($replay) {
                abort_unless($replay->alert_id === $locked->id && hash_equals($replay->request_payload_checksum, $checksum),
                    409, 'Alert action idempotency key was already used for a different request.');
                return $locked;
            }

            abort_unless($locked->event_version === $expectedVersion, 409, 'Alert changed after it was loaded. Refresh and try again.');
            abort_unless($reason !== '', 422, 'An alert action reason is required.');
            $fromStatus = $locked->status;
            $toStatus = $fromStatus;
            $changes = [];
            if ($action === 'acknowledge') {
                abort_unless($fromStatus === 'open', 422, 'Only an open alert can be acknowledged.');
                $toStatus = 'acknowledged';
                $changes = ['acknowledged_at' => now(), 'resolved_at' => null, 'resolution_note' => null, 'snoozed_until' => null];
            } elseif ($action === 'resolve') {
                abort_unless(in_array($fromStatus, ['open', 'acknowledged'], true), 422, 'Only an open or acknowledged alert can be resolved.');
                $toStatus = 'resolved';
                $changes = ['resolved_at' => now(), 'resolution_note' => $reason, 'snoozed_until' => null];
            } elseif ($action === 'reopen') {
                abort_unless($fromStatus === 'resolved', 422, 'Only a resolved alert can be reopened.');
                $toStatus = 'open';
                $changes = ['acknowledged_at' => null, 'resolved_at' => null, 'resolution_note' => null, 'snoozed_until' => null];
            } elseif ($action === 'snooze') {
                abort_unless(in_array($fromStatus, ['open', 'acknowledged'], true), 422, 'Only an open or acknowledged alert can be snoozed.');
                abort_unless($snoozeAt !== null && $snoozeAt->isAfter(now()), 422, 'Snooze time must be in the future.');
                $changes = ['snoozed_until' => $snoozeAt];
            } elseif ($action === 'escalate') {
                abort_unless(in_array($fromStatus, ['open', 'acknowledged'], true), 422, 'Only an open or acknowledged alert can be escalated.');
                $changes = ['escalation_level' => $locked->escalation_level + 1, 'escalated_at' => now(), 'snoozed_until' => null];
            } else {
                abort(422, 'Unsupported alert action.');
            }

            $now = now();
            $toVersion = $locked->event_version + 1;
            $locked->update($changes + ['status' => $toStatus, 'event_version' => $toVersion, 'last_action_at' => $now]);
            DB::table('sales_performance_alert_action_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $locked->company_id, 'alert_id' => $locked->id, 'action' => $action,
                'from_status' => $fromStatus, 'to_status' => $toStatus, 'from_version' => $expectedVersion,
                'to_version' => $toVersion, 'reason' => $reason, 'snoozed_until' => $snoozeAt,
                'escalation_level' => $action === 'escalate' ? $locked->escalation_level : null,
                'request_payload_checksum' => $checksum, 'idempotency_key' => $key,
                'actor_user_id' => $actorUserId, 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->events->record('sales', $locked->company_id, 'sales_performance_alert', $locked->id,
                'sales.performance.alert_'.$action, $toVersion, 1, [
                    'from_status' => $fromStatus, 'to_status' => $toStatus, 'from_version' => $expectedVersion,
                    'to_version' => $toVersion, 'action_event_checksum' => $checksum,
                    'snoozed_until' => $snoozeAt?->toIso8601String(),
                    'escalation_level' => $action === 'escalate' ? $locked->escalation_level : null,
                ], $now, $key);
            return $locked->refresh();
        }, 3);
    }

    private function calculateRows(string $companyId, string $from, string $to, $cutoff, ?array $alertRules = null): Collection
    {
        $overdueAsOf = CarbonImmutable::parse($to)->min(CarbonImmutable::parse($cutoff))->toDateString();
        $profiles = SalesProfile::query()->with('staff')->where('company_id', $companyId)
            ->where('effective_from', '<=', $to)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $from))->get();
        $agingSource = $this->frozenAging->source(
            $companyId, $profiles->pluck('id')->all(), $to, (string) $cutoff, true,
        );
        $agingByProfile = $agingSource['rows']->groupBy('sales_profile_id');
        $taskFacts = $alertRules === null ? [] : $this->taskInterventionFacts->forClosedPeriod(
            $companyId, $profiles->pluck('id')->all(), $from, $to, (string) $cutoff, $alertRules,
        );
        $facts = DB::table('sales_metric_facts')->selectRaw('sales_profile_id, metric_type, business_classification, SUM(quantity) quantity, SUM(amount_lkr) amount')
            ->where('company_id', $companyId)->whereBetween('occurred_on', [$from, $to])->where('occurred_at', '<=', $cutoff)
            ->groupBy('sales_profile_id', 'metric_type', 'business_classification')->get()->groupBy('sales_profile_id');
        $collectionFacts = SalesMetricFact::query()->where('company_id', $companyId)->where('metric_type', 'eligible_collection')
            ->whereBetween('occurred_on', [$from, $to])->where('occurred_at', '<=', $cutoff)
            ->orderBy('occurred_at')->orderBy('id')->get()->groupBy('sales_profile_id');
        $commissionFacts = SalesMetricFact::query()->where('company_id', $companyId)->where('metric_type', 'commission_earned')
            ->whereBetween('occurred_on', [$from, $to])->where('occurred_at', '<=', $cutoff)
            ->orderBy('occurred_at')->orderBy('id')->get()->groupBy('sales_profile_id');
        $breakdowns = $this->metricBreakdowns->resolve(
            $collectionFacts->flatten(1)->concat($commissionFacts->flatten(1))->values(), $companyId, $from, $to,
        );
        $targets = SalesTargetVersion::query()->where('company_id', $companyId)->where('status', 'approved')
            ->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from)->get()->groupBy('sales_profile_id');
        $adjustmentEvidenceIssues = DB::table('booking_commercial_value_adjustments as adjustment')
            ->leftJoin('sales_metric_facts as fact', function ($join) {
                $join->on('fact.source_id', '=', 'adjustment.id')
                    ->where('fact.source_type', 'commercial_value_adjustment')
                    ->where('fact.metric_type', 'new_sales_adjustment');
            })->selectRaw('adjustment.acquisition_sales_profile_id, COUNT(*) issue_count')
            ->where('adjustment.company_id', $companyId)->where('adjustment.counts_as_new_sales_adjustment', true)
            ->whereDate('adjustment.effective_at', '>=', $from)->whereDate('adjustment.effective_at', '<=', $to)
            ->where('adjustment.effective_at', '<=', $cutoff)->where(function ($query) {
                $query->whereNull('adjustment.delta_lkr_amount')->orWhereNull('fact.id')
                    ->orWhereColumn('fact.company_id', '!=', 'adjustment.company_id')
                    ->orWhereColumn('fact.sales_profile_id', '!=', 'adjustment.acquisition_sales_profile_id')
                    ->orWhereColumn('fact.amount_lkr', '!=', 'adjustment.delta_lkr_amount');
            })->groupBy('adjustment.acquisition_sales_profile_id')
            ->pluck('issue_count', 'adjustment.acquisition_sales_profile_id');

        $allocationTotals = DB::table('booking_payment_schedule_allocations')->selectRaw('booking_payment_schedule_id, SUM(amount) allocated')
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>', $cutoff))
            ->where('allocated_at', '<=', $cutoff)->groupBy('booking_payment_schedule_id');
        $overdue = DB::table('booking_payment_schedules as schedule')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'schedule.booking_id')
            ->leftJoinSub($allocationTotals, 'allocation', fn ($join) => $join->on('allocation.booking_payment_schedule_id', '=', 'schedule.id'))
            ->selectRaw('attribution.collection_sales_profile_id sales_profile_id, COUNT(schedule.id) overdue_count, SUM(schedule.amount - COALESCE(allocation.allocated, 0)) overdue_lkr, MIN(schedule.due_date) oldest_due_date')
            ->where('attribution.company_id', $companyId)->where(fn ($q) => $q->whereNull('schedule.deleted_at')->orWhere('schedule.deleted_at', '>', $cutoff))
            ->where('schedule.created_at', '<=', $cutoff)->whereDate('schedule.due_date', '<', $overdueAsOf)
            ->whereRaw('schedule.amount > COALESCE(allocation.allocated, 0)')
            ->groupBy('attribution.collection_sales_profile_id')->get()->keyBy('sales_profile_id');

        $rows = $profiles->map(function (SalesProfile $profile) use ($facts, $collectionFacts, $commissionFacts, $breakdowns, $targets, $adjustmentEvidenceIssues, $overdue, $taskFacts, $from, $to, $overdueAsOf, $agingByProfile, $agingSource) {
            $metric = fn (string $type, ?string $classification = null, string $field = 'amount') => (float) ($facts->get($profile->id, collect())->first(fn ($fact) => $fact->metric_type === $type && ($classification === null || $fact->business_classification === $classification))?->{$field} ?? 0);
            $target = $this->proratedTargets($targets->get($profile->id, collect()), $from, $to);
            $newTarget = $target['new_sales']['amount_lkr'];
            $collectionTarget = $target['eligible_collections']['amount_lkr'];
            $newSales = $metric('new_sales', 'new_business');
            $newSalesAdjustment = $metric('new_sales_adjustment', 'new_business');
            $netNewSales = $newSales + $newSalesAdjustment;
            $adjustmentEvidenceIssueCount = (int) ($adjustmentEvidenceIssues->get($profile->id) ?? 0);
            $newSalesValueComplete = $adjustmentEvidenceIssueCount === 0;
            $profileCollectionFacts = $collectionFacts->get($profile->id, collect());
            $profileCommissionFacts = $commissionFacts->get($profile->id, collect());
            $breakdownAmount = fn (Collection $source, string $dimension, string $value) => (float) $source
                ->filter(fn (SalesMetricFact $fact) => data_get($breakdowns, "{$fact->id}.{$dimension}") === $value)
                ->sum(fn (SalesMetricFact $fact) => (float) $fact->amount_lkr);
            $missingBreakdownCount = fn (Collection $source, string $dimension) => $source
                ->filter(fn (SalesMetricFact $fact) => data_get($breakdowns, "{$fact->id}.{$dimension}") === null)->count();
            $currentBookingCollections = $breakdownAmount($profileCollectionFacts, 'collection_cohort', 'current_period_booking');
            $priorBookingCollections = $breakdownAmount($profileCollectionFacts, 'collection_cohort', 'prior_period_booking');
            $collectionCohortMissing = $missingBreakdownCount($profileCollectionFacts, 'collection_cohort');
            $currentBookingCommission = $breakdownAmount($profileCommissionFacts, 'collection_cohort', 'current_period_booking');
            $priorBookingCommission = $breakdownAmount($profileCommissionFacts, 'collection_cohort', 'prior_period_booking');
            $commissionCohortMissing = $missingBreakdownCount($profileCommissionFacts, 'collection_cohort');
            $oneTimeCommission = $breakdownAmount($profileCommissionFacts, 'commission_category', 'one_time');
            $longTermCommission = $breakdownAmount($profileCommissionFacts, 'commission_category', 'long_term');
            $commissionCategoryMissing = $missingBreakdownCount($profileCommissionFacts, 'commission_category');
            $commissionTotal = (float) $profileCommissionFacts->sum(fn (SalesMetricFact $fact) => (float) $fact->amount_lkr);
            $collectionTotal = (float) $profileCollectionFacts->sum(fn (SalesMetricFact $fact) => (float) $fact->amount_lkr);
            $aging = $this->frozenAging->aggregate($agingByProfile->get($profile->id, collect()));
            return [
                'sales_profile_id' => $profile->id, 'staff_id' => $profile->staff_id,
                'staff_code' => $profile->staff?->code ?: $profile->sales_code,
                'new_sales_target_lkr' => $newTarget, 'collection_target_lkr' => $collectionTarget,
                'new_sales_target_state' => $target['new_sales']['state'],
                'collection_target_state' => $target['eligible_collections']['state'],
                'target_period_basis' => $target['period_basis'],
                'target_proration_applied' => $target['proration_applied'],
                'target_configuration_snapshot' => $target,
                'new_sales_lkr' => $newSalesValueComplete ? $netNewSales : null,
                'gross_new_sales_lkr' => $newSales,
                'new_sales_adjustment_lkr' => $newSalesValueComplete ? $newSalesAdjustment : null,
                'net_new_sales_lkr' => $newSalesValueComplete ? $netNewSales : null,
                'new_sales_value_state' => $newSalesValueComplete ? 'complete' : 'incomplete',
                'new_sales_adjustment_evidence_issue_count' => $adjustmentEvidenceIssueCount,
                'new_booking_collections_lkr' => $currentBookingCollections,
                'existing_booking_collections_lkr' => $priorBookingCollections, 'eligible_collections_lkr' => $collectionTotal,
                'collection_cohort_state' => $collectionCohortMissing === 0 ? 'complete' : 'incomplete',
                'collection_cohort_missing_count' => $collectionCohortMissing,
                'commission_new_business_lkr' => $currentBookingCommission,
                'commission_existing_business_lkr' => $priorBookingCommission,
                'commission_earned_lkr' => $commissionTotal,
                'commission_current_period_booking_lkr' => $currentBookingCommission,
                'commission_prior_period_booking_lkr' => $priorBookingCommission,
                'commission_cohort_state' => $commissionCohortMissing === 0 ? 'complete' : 'incomplete',
                'commission_cohort_missing_count' => $commissionCohortMissing,
                'commission_one_time_lkr' => $oneTimeCommission,
                'commission_long_term_lkr' => $longTermCommission,
                'commission_category_state' => $commissionCategoryMissing === 0 ? 'complete' : 'incomplete',
                'commission_category_missing_count' => $commissionCategoryMissing,
                'prior_booking_commission_ratio_percent' => $commissionCohortMissing === 0 && $commissionTotal > 0 ? round($priorBookingCommission / $commissionTotal * 100, 4) : null,
                'prior_booking_collection_ratio_percent' => $collectionCohortMissing === 0 && $collectionTotal > 0 ? round($priorBookingCollections / $collectionTotal * 100, 4) : null,
                'long_term_commission_share_percent' => $commissionCategoryMissing === 0 && $commissionTotal > 0 ? round($longTermCommission / $commissionTotal * 100, 4) : null,
                'commission_dimension_missing_count' => $commissionCohortMissing + $commissionCategoryMissing,
                'new_bookings_count' => (int) $metric('new_sales', 'new_business', 'quantity'),
                'new_customers_count' => (int) $metric('new_customer', null, 'quantity'),
                'activities_count' => (int) $metric('sales_activity', null, 'quantity'),
                'overdue_collections_count' => (int) ($overdue->get($profile->id)?->overdue_count ?? 0),
                'overdue_collections_lkr' => max(0, (float) ($overdue->get($profile->id)?->overdue_lkr ?? 0)),
                'oldest_overdue_age_days' => $overdue->get($profile->id)?->oldest_due_date
                    ? CarbonImmutable::parse($overdue->get($profile->id)->oldest_due_date)->diffInDays(CarbonImmutable::parse($overdueAsOf))
                    : null,
                ...$aging,
                'aging_profile_lineage_missing_count' => $agingSource['missing_lineage_count'],
                ...($taskFacts[$profile->id] ?? [
                    'overdue_task_count' => null, 'oldest_overdue_task_age_days' => null,
                    'missed_next_action_count' => null, 'missed_next_action_lookback_completed_months' => null,
                    'task_deadline_or_history_missing_count' => null, 'task_intervention_evidence_checksum' => null,
                ]),
                'new_sales_achievement_percent' => ! $newSalesValueComplete || $newTarget === null || $newTarget <= 0 ? null : round($netNewSales / $newTarget * 100, 4),
                'collection_achievement_percent' => $collectionTarget === null || $collectionTarget <= 0 ? null : round($collectionTotal / $collectionTarget * 100, 4),
                'new_sales_target_variance_lkr' => ! $newSalesValueComplete || $newTarget === null ? null : round($netNewSales - $newTarget, 4),
                'collection_target_variance_lkr' => $collectionTarget === null ? null : round($collectionTotal - $collectionTarget, 4),
            ];
        });

        $sorted = $rows->sort(function ($a, $b) {
            foreach (['new_sales_achievement_percent', 'collection_achievement_percent', 'net_new_sales_lkr', 'eligible_collections_lkr'] as $key) {
                $av = $a[$key] ?? -INF; $bv = $b[$key] ?? -INF;
                if ($av != $bv) return $bv <=> $av;
            }
            return strcmp($a['staff_code'], $b['staff_code']);
        })->values();
        $rank = 0; $previous = null;
        return $sorted->map(function ($row, $index) use (&$rank, &$previous) {
            if ($row['new_sales_achievement_percent'] === null) { $row['display_rank'] = null; return $row; }
            $key = json_encode(array_intersect_key($row, array_flip(['new_sales_achievement_percent', 'collection_achievement_percent', 'net_new_sales_lkr', 'eligible_collections_lkr'])));
            if ($key !== $previous) $rank = $index + 1;
            $previous = $key; $row['display_rank'] = $rank; return $row;
        });
    }

    private function proratedTargets(Collection $targets, string $from, string $to): array
    {
        $businessTimezone = config('sales.business_timezone');
        abort_unless(is_string($businessTimezone) && in_array($businessTimezone, \DateTimeZone::listIdentifiers(), true),
            409, 'An approved Sales business timezone is required for target period calculations.');
        $fromDate = CarbonImmutable::parse($from, $businessTimezone)->startOfDay();
        $toDate = CarbonImmutable::parse($to, $businessTimezone)->startOfDay();
        $periods = collect();
        for ($month = $fromDate->startOfMonth(); $month->lte($toDate->startOfMonth()); $month = $month->addMonth()) {
            $periods->push($month->toDateString());
        }
        $metric = function (string $field) use ($targets, $periods, $fromDate, $toDate, $businessTimezone): array {
            $amount = 0.0; $configured = 0; $missing = []; $ambiguous = []; $prorated = false;
            foreach ($periods as $periodStart) {
                $monthStart = CarbonImmutable::parse($periodStart);
                $monthEnd = $monthStart->endOfMonth();
                $candidates = $targets->filter(function (SalesTargetVersion $target) use ($field, $monthStart, $monthEnd, $businessTimezone) {
                    $targetStart = CarbonImmutable::parse($target->period_start->toDateString(), $businessTimezone)->startOfDay();
                    $targetEnd = CarbonImmutable::parse($target->period_end->toDateString(), $businessTimezone)->startOfDay();
                    return $target->{$field} !== null && $targetStart->lte($monthEnd) && $targetEnd->gte($monthStart);
                })->values();
                if ($candidates->isEmpty()) { $missing[] = $periodStart; continue; }
                if ($candidates->count() !== 1) { $ambiguous[] = $periodStart; continue; }
                /** @var SalesTargetVersion $target */
                $target = $candidates->first();
                $targetStart = CarbonImmutable::parse($target->period_start->toDateString(), $businessTimezone)->startOfDay();
                $targetEnd = CarbonImmutable::parse($target->period_end->toDateString(), $businessTimezone)->startOfDay();
                $overlapStart = $fromDate->max($targetStart);
                $overlapEnd = $toDate->min($targetEnd);
                $appliedDays = $overlapStart->diffInDays($overlapEnd) + 1;
                $targetDays = $targetStart->diffInDays($targetEnd) + 1;
                $ratio = $appliedDays / $targetDays;
                $amount += (float) $target->{$field} * $ratio;
                $configured++;
                $prorated = $prorated || $appliedDays !== $targetDays;
            }
            $complete = $ambiguous === [] && $configured === $periods->count();
            $state = $ambiguous !== [] ? 'ambiguous_configuration'
                : ($configured === 0 ? 'not_configured'
                    : (! $complete ? 'partial_configuration' : (abs($amount) < 0.0000001 ? 'configured_zero' : 'configured')));
            return [
                'state' => $state,
                'amount_lkr' => $complete ? round($amount, 4) : null,
                'expected_month_count' => $periods->count(),
                'configured_month_count' => $configured,
                'missing_period_starts' => $missing,
                'ambiguous_period_starts' => $ambiguous,
                'proration_applied' => $prorated,
            ];
        };
        $newSales = $metric('new_sales_target_lkr');
        $collections = $metric('eligible_collections_target_lkr');
        $versions = $targets->sortBy([['period_start', 'asc'], ['version', 'asc']])->map(fn (SalesTargetVersion $target) => [
            'target_id' => $target->id,
            'version' => (int) $target->version,
            'source' => $target->source,
            'period_start' => $target->period_start->toDateString(),
            'period_end' => $target->period_end->toDateString(),
            'approved_at' => $target->approved_at?->toIso8601String(),
            'payload_checksum' => $target->payload_checksum,
        ])->values()->all();

        return [
            'new_sales' => $newSales,
            'eligible_collections' => $collections,
            'business_timezone' => $businessTimezone,
            'period_basis' => ($newSales['proration_applied'] || $collections['proration_applied'])
                ? 'calendar_day_prorated' : 'full_month_sum',
            'proration_applied' => $newSales['proration_applied'] || $collections['proration_applied'],
            'versions' => $versions,
        ];
    }

    private function assertPeriod(string $from, string $to, string $periodType): void
    {
        $start = CarbonImmutable::parse($from); $end = CarbonImmutable::parse($to);
        if ($periodType === 'month') abort_unless($start->isStartOfMonth() && $end->isSameDay($start->endOfMonth()), 422, 'Monthly snapshots require a complete calendar month.');
        if ($periodType === 'quarter') abort_unless($start->month % 3 === 1 && $start->isStartOfMonth() && $end->isSameDay($start->addMonths(2)->endOfMonth()), 422, 'Quarter snapshots require a complete calendar quarter.');
        if ($periodType === 'year') abort_unless($start->isStartOfYear() && $end->isSameDay($start->endOfYear()), 422, 'Year snapshots require a complete calendar year.');
    }

    public function alertEvaluationContext(SalesKpiSnapshot $snapshot): array
    {
        abort_unless(config('sales.features.performance_alert_evaluations', false), 409,
            'Sales performance alert evaluation is not enabled.');
        abort_unless($snapshot->status === 'frozen' && $snapshot->period_type === 'month', 409,
            'Only the current frozen monthly Sales snapshot can be evaluated.');
        $policyId = data_get($snapshot->source_reconciliation_snapshot, 'alert_policy_version_id');
        abort_unless(is_string($policyId) && $policyId !== '', 409,
            'The frozen Sales snapshot has no reconciled alert-policy lineage.');
        $policy = SalesAlertPolicyVersion::query()->whereKey($policyId)->where('company_id', $snapshot->company_id)
            ->whereIn('status', ['approved', 'superseded'])->whereDate('effective_from', '<=', $snapshot->period_start)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $snapshot->period_end))->first();
        abort_unless($policy && $policy->approved_at && $policy->approved_at->lte($snapshot->generated_at), 409,
            'The frozen Sales snapshot alert-policy lineage is invalid.');
        $rules = $this->alertPolicyContract->normalize($policy->rules);
        abort_unless(Staff::query()->where('company_id', $snapshot->company_id)
            ->where('user_id', $rules['evaluation']['owner_user_id'])
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->exists(), 409, 'The frozen alert policy owner is no longer an active internal Staff user in this legal entity.');
        $timezone = config('sales.business_timezone');
        abort_unless(is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true), 409,
            'An approved Sales business timezone is required for alert evaluation.');
        $scheduledAt = CarbonImmutable::parse($snapshot->period_end->toDateString(), $timezone)->addDay()
            ->addDays($rules['evaluation']['grace_days'])
            ->setTimeFromTimeString($rules['evaluation']['schedule']['local_time'])->utc();
        $minimumElapsedAt = CarbonImmutable::parse($snapshot->period_start->toDateString(), $timezone)
            ->addDays($rules['evaluation']['minimum_elapsed_days'])->startOfDay()->utc();
        $dueAt = $scheduledAt->max($minimumElapsedAt);
        return ['policy' => $policy, 'rules' => $rules, 'due_at' => $dueAt,
            'already_evaluated' => DB::table('sales_alert_evaluation_runs')->where('snapshot_id', $snapshot->id)
                ->where('policy_version_id', $policy->id)->exists()];
    }

    public function evaluateSnapshotAlerts(SalesKpiSnapshot $snapshot): object
    {
        return DB::transaction(function () use ($snapshot): object {
            DB::table('companies')->whereKey($snapshot->company_id)->lockForUpdate()->firstOrFail();
            $snapshot = SalesKpiSnapshot::query()->with('rows')->lockForUpdate()->findOrFail($snapshot->id);
            $context = $this->alertEvaluationContext($snapshot);
            $policy = $context['policy']; $rules = $context['rules']; $dueAt = $context['due_at'];
            $existing = DB::table('sales_alert_evaluation_runs')->where('snapshot_id', $snapshot->id)
                ->where('policy_version_id', $policy->id)->first();
            if ($existing) return $existing;
            abort_if(now()->lt($dueAt), 409, 'The approved Sales alert evaluation schedule is not due yet.');

            $alertsCreated = 0; $suppressed = [];
            foreach ($snapshot->rows as $row) {
                $metrics = $row->metric_snapshot;
                $checks = [];
                if ($rules['no_new_sales']['enabled'] && (int) $row->new_bookings_count === 0) {
                    $checks['no_new_sales'] = [$rules['no_new_sales']['severity'],
                        'No factual New Sales were recorded in the completed month.',
                        ['operator' => 'equals', 'quantity' => 0], ['actual_quantity' => (int) $row->new_bookings_count]];
                }
                if ($rules['no_sales_activity']['enabled'] && (int) $row->activities_count === 0) {
                    $checks['no_sales_activity'] = [$rules['no_sales_activity']['severity'],
                        'No factual Sales activities were recorded in the completed month.',
                        ['operator' => 'equals', 'quantity' => 0], ['actual_quantity' => (int) $row->activities_count]];
                }
                $overdueRule = $rules['overdue_collections'];
                if ($overdueRule['enabled'] && (float) $row->overdue_collections_lkr >= $overdueRule['minimum_amount_lkr']
                    && (int) ($metrics['oldest_overdue_age_days'] ?? 0) >= $overdueRule['minimum_age_days']) {
                    $checks['overdue_collections'] = [$overdueRule['severity'],
                        'Attributed overdue collections crossed the approved amount and age thresholds.',
                        ['minimum_amount_lkr' => $overdueRule['minimum_amount_lkr'], 'minimum_age_days' => $overdueRule['minimum_age_days']],
                        ['actual_amount_lkr' => (float) $row->overdue_collections_lkr,
                            'oldest_age_days' => (int) $metrics['oldest_overdue_age_days']]];
                }
                $taskHistoryMissing = (int) ($metrics['task_deadline_or_history_missing_count'] ?? 0);
                $overdueTaskRule = $rules['overdue_tasks'];
                if ($overdueTaskRule['enabled']) {
                    if ($taskHistoryMissing > 0 || ($metrics['overdue_task_count'] ?? null) === null
                        || ($metrics['task_intervention_evidence_checksum'] ?? null) === null) {
                        $suppressed[] = ['sales_profile_id' => $row->sales_profile_id, 'rule' => 'overdue_tasks',
                            'reason' => 'governed_task_deadline_or_history_missing', 'missing_count' => $taskHistoryMissing];
                    } elseif ((int) $metrics['overdue_task_count'] >= $overdueTaskRule['minimum_count']
                        && (int) ($metrics['oldest_overdue_task_age_days'] ?? 0) >= $overdueTaskRule['minimum_age_days']) {
                        $checks['overdue_tasks'] = [$overdueTaskRule['severity'],
                            'Factual CRM tasks still open or in progress at period end crossed the approved count and age thresholds.',
                            ['minimum_count' => $overdueTaskRule['minimum_count'],
                                'minimum_age_days' => $overdueTaskRule['minimum_age_days'],
                                'status_basis' => $overdueTaskRule['status_basis'], 'owner_basis' => $overdueTaskRule['owner_basis']],
                            ['actual_count' => (int) $metrics['overdue_task_count'],
                                'oldest_age_days' => (int) $metrics['oldest_overdue_task_age_days'],
                                'task_intervention_evidence_checksum' => $metrics['task_intervention_evidence_checksum']]];
                    }
                }
                $missedActionRule = $rules['repeatedly_missed_next_actions'];
                if ($missedActionRule['enabled']) {
                    if ($taskHistoryMissing > 0 || ($metrics['missed_next_action_count'] ?? null) === null
                        || ($metrics['task_intervention_evidence_checksum'] ?? null) === null) {
                        $suppressed[] = ['sales_profile_id' => $row->sales_profile_id,
                            'rule' => 'repeatedly_missed_next_actions',
                            'reason' => 'governed_task_deadline_or_history_missing', 'missing_count' => $taskHistoryMissing];
                    } elseif ((int) $metrics['missed_next_action_count'] >= $missedActionRule['minimum_count']) {
                        $checks['repeatedly_missed_next_actions'] = [$missedActionRule['severity'],
                            'Factual CRM tasks that remained open or in progress at their deadline crossed the approved repeated-miss threshold.',
                            ['minimum_count' => $missedActionRule['minimum_count'],
                                'lookback_completed_months' => $missedActionRule['lookback_completed_months'],
                                'deadline_basis' => $missedActionRule['deadline_basis'],
                                'owner_basis' => $missedActionRule['owner_basis'], 'source' => $missedActionRule['source']],
                            ['actual_count' => (int) $metrics['missed_next_action_count'],
                                'lookback_completed_months' => $metrics['missed_next_action_lookback_completed_months'],
                                'task_intervention_evidence_checksum' => $metrics['task_intervention_evidence_checksum']]];
                    }
                }
                $relianceRule = $rules['recurring_commission_reliance'];
                if ($relianceRule['enabled']) {
                    if (($metrics['new_sales_value_state'] ?? 'incomplete') !== 'complete'
                        || ($metrics['commission_dimension_missing_count'] ?? 0) > 0
                        || ($metrics['prior_booking_commission_ratio_percent'] ?? null) === null
                        || $row->new_sales_achievement_percent === null) {
                        $suppressed[] = ['sales_profile_id' => $row->sales_profile_id, 'rule' => 'recurring_commission_reliance',
                            'reason' => 'required_dimension_or_target_missing'];
                    } elseif ($metrics['prior_booking_commission_ratio_percent'] >= $relianceRule['prior_booking_commission_percent']
                        && (float) $row->new_sales_achievement_percent < $relianceRule['new_sales_achievement_below_percent']) {
                        $checks['recurring_commission_reliance'] = [$relianceRule['severity'],
                            'Prior-period booking commission reliance crossed the approved threshold while New Sales target achievement was below its approved threshold.',
                            ['prior_booking_commission_percent' => $relianceRule['prior_booking_commission_percent'],
                                'new_sales_achievement_below_percent' => $relianceRule['new_sales_achievement_below_percent']],
                            ['prior_booking_commission_ratio_percent' => $metrics['prior_booking_commission_ratio_percent'],
                                'prior_booking_collection_ratio_percent' => $metrics['prior_booking_collection_ratio_percent'],
                                'long_term_commission_share_percent' => $metrics['long_term_commission_share_percent'],
                                'new_sales_achievement_percent' => (float) $row->new_sales_achievement_percent]];
                    }
                }
                $declineRule = $rules['decline_against_completed_month_average'];
                if ($declineRule['enabled']) {
                    $latestFrozenMonthly = DB::table('sales_kpi_snapshots')
                        ->selectRaw('company_id, period_start, period_end, MAX(version) latest_version')
                        ->where('company_id', $snapshot->company_id)->where('status', 'frozen')
                        ->where('period_type', 'month')->whereDate('period_end', '<', $snapshot->period_start)
                        ->groupBy('company_id', 'period_start', 'period_end');
                    $history = DB::table('sales_kpi_snapshot_rows as row')
                        ->join('sales_kpi_snapshots as snapshot', 'snapshot.id', '=', 'row.snapshot_id')
                        ->joinSub($latestFrozenMonthly, 'latest', function ($join) {
                            $join->on('latest.company_id', '=', 'snapshot.company_id')
                                ->on('latest.period_start', '=', 'snapshot.period_start')
                                ->on('latest.period_end', '=', 'snapshot.period_end')
                                ->on('latest.latest_version', '=', 'snapshot.version');
                        })
                        ->where('row.sales_profile_id', $row->sales_profile_id)->where('snapshot.status', 'frozen')
                        ->where('snapshot.period_type', 'month')
                        ->where('row.new_sales_value_state', 'complete')->whereNotNull('row.net_new_sales_lkr')
                        ->whereDate('snapshot.period_end', '<', $snapshot->period_start)->orderByDesc('snapshot.period_end')
                        ->limit($declineRule['baseline_months'])->get(['snapshot.period_start', 'snapshot.period_end', 'row.net_new_sales_lkr']);
                    $expectedPeriods = collect(range(1, $declineRule['baseline_months']))
                        ->map(fn (int $monthsBack) => CarbonImmutable::parse($snapshot->period_start)
                            ->subMonthsNoOverflow($monthsBack)->startOfMonth()->toDateString());
                    $historyPeriodsComplete = $history->pluck('period_start')
                        ->map(fn ($periodStart) => CarbonImmutable::parse($periodStart)->toDateString())
                        ->values()->all() === $expectedPeriods->all();
                    if (($metrics['new_sales_value_state'] ?? 'incomplete') !== 'complete'
                        || $row->net_new_sales_lkr === null || $history->count() !== $declineRule['baseline_months']
                        || ! $historyPeriodsComplete) {
                        $suppressed[] = ['sales_profile_id' => $row->sales_profile_id, 'rule' => 'new_sales_decline',
                            'reason' => 'completed_month_baseline_incomplete', 'available_months' => $history->count(),
                            'required_months' => $declineRule['baseline_months']];
                    } else {
                        $average = (float) $history->avg('net_new_sales_lkr');
                        $decline = $average > 0 ? ($average - (float) $row->net_new_sales_lkr) / $average * 100 : 0;
                        if ($decline >= $declineRule['percent']) {
                            $checks['new_sales_decline'] = [$declineRule['severity'],
                                'New Sales declined against the approved completed-calendar-month baseline.',
                                ['decline_percent' => $declineRule['percent'], 'baseline_months' => $declineRule['baseline_months']],
                                ['actual_decline_percent' => round($decline, 4), 'baseline_average_lkr' => round($average, 4),
                                    'comparison_periods' => $history->values()->all()]];
                        }
                    }
                }
                foreach ($checks as $type => [$severity, $explanation, $threshold, $comparison]) {
                    $evaluation = ['snapshot_id' => $snapshot->id, 'policy_version_id' => $policy->id,
                        'sales_profile_id' => $row->sales_profile_id, 'alert_type' => $type,
                        'threshold' => $threshold, 'comparison' => $comparison, 'metrics' => $metrics];
                    $deduplicationKey = "{$snapshot->id}:{$row->sales_profile_id}:{$type}";
                    $legacy = SalesPerformanceAlert::query()->where('deduplication_key', $deduplicationKey)
                        ->whereNull('evaluation_checksum')->exists();
                    abort_if($legacy, 409, 'A pre-governance alert requires reviewed disposition before evaluation.');
                    $alert = SalesPerformanceAlert::firstOrCreate(['deduplication_key' => $deduplicationKey], [
                        'company_id' => $snapshot->company_id, 'sales_profile_id' => $row->sales_profile_id,
                        'snapshot_id' => $snapshot->id, 'policy_version_id' => $policy->id, 'alert_type' => $type,
                        'severity' => $severity, 'status' => 'open', 'explanation' => $explanation,
                        'assigned_to' => $rules['evaluation']['owner_user_id'],
                        'evidence_snapshot' => $metrics, 'policy_contract_snapshot' => $rules,
                        'threshold_snapshot' => $threshold, 'comparison_snapshot' => $comparison,
                        'evaluation_checksum' => hash('sha256', CanonicalJson::encode($evaluation)), 'detected_at' => now(),
                    ]);
                    $alertsCreated++;
                }
            }
            if ($rules['evaluation']['baseline_completeness'] === 'require_complete'
                && collect($suppressed)->contains(fn ($item) => $item['reason'] === 'completed_month_baseline_incomplete')) {
                abort(409, 'The approved policy requires a complete comparison baseline; no alert evaluation was written.');
            }
            $result = ['snapshot_id' => $snapshot->id, 'snapshot_checksum' => $snapshot->snapshot_checksum,
                'policy_version_id' => $policy->id, 'policy_checksum' => $policy->rules_checksum,
                'scheduled_for' => $dueAt->toIso8601String(), 'profile_count' => $snapshot->rows->count(),
                'alert_count' => $alertsCreated, 'suppressed_rules' => $suppressed,
                'missing_data_behavior' => $rules['evaluation']['missing_data_behavior']];
            $runId = (string) Str::uuid(); $now = now();
            DB::table('sales_alert_evaluation_runs')->insert([
                'id' => $runId, 'company_id' => $snapshot->company_id, 'snapshot_id' => $snapshot->id,
                'policy_version_id' => $policy->id, 'scheduled_for' => $dueAt, 'evaluated_at' => $now,
                'status' => 'completed', 'profile_count' => $snapshot->rows->count(), 'alert_count' => $alertsCreated,
                'suppressed_rule_count' => count($suppressed), 'result_snapshot' => json_encode($result, JSON_THROW_ON_ERROR),
                'result_checksum' => hash('sha256', CanonicalJson::encode($result)),
                'idempotency_key' => "alert-evaluation:{$snapshot->id}:{$policy->id}", 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->events->record('sales', $snapshot->company_id, 'sales_alert_evaluation', $runId,
                'sales.performance.alerts_evaluated', 1, 1, ['snapshot_id' => $snapshot->id,
                    'policy_version_id' => $policy->id, 'alert_count' => $alertsCreated,
                    'suppressed_rule_count' => count($suppressed), 'result_checksum' => hash('sha256', CanonicalJson::encode($result))],
                $now, "alert-evaluation:{$snapshot->id}:{$policy->id}");
            return DB::table('sales_alert_evaluation_runs')->find($runId);
        }, 3);
    }
}
