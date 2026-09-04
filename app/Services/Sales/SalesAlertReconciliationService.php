<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesAlertPolicyVersion;
use App\Models\Sales\SalesKpiSnapshot;
use App\Models\Sales\SalesKpiSnapshotRow;
use App\Models\Sales\SalesMetricFact;
use App\Models\Sales\SalesPerformanceAlert;
use App\Models\Sales\SalesTargetVersion;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Support\Facades\DB;

class SalesAlertReconciliationService
{
    public function __construct(private readonly SalesPerformanceService $performance) {}

    public function reconcile(SalesPerformanceAlert $alert, bool $includeCompanyEvidence): array
    {
        $snapshot = SalesKpiSnapshot::query()->findOrFail($alert->snapshot_id);
        $row = SalesKpiSnapshotRow::query()->where('snapshot_id', $snapshot->id)
            ->where('sales_profile_id', $alert->sales_profile_id)->firstOrFail();
        $policy = SalesAlertPolicyVersion::query()->findOrFail($alert->policy_version_id);

        abort_unless($snapshot->company_id === $alert->company_id
            && $policy->company_id === $alert->company_id, 409,
            'The alert evidence crosses legal-entity boundaries and cannot be reconciled.');

        $currentRows = collect($this->performance->preview(
            $alert->company_id,
            $snapshot->period_start->toDateString(),
            $snapshot->period_end->toDateString(),
            $snapshot->cutoff_at,
            $policy->rules,
        )['rows']);
        $currentRow = $currentRows->firstWhere('sales_profile_id', $alert->sales_profile_id);

        $checks = [];
        $this->check($checks, 'snapshot_status', 'frozen', $snapshot->status);
        $this->check($checks, 'frozen_row_checksum', $row->row_checksum,
            hash('sha256', CanonicalJson::encode($row->metric_snapshot ?? [])));
        $this->check($checks, 'current_source_to_frozen_row', $row->row_checksum,
            $currentRow === null ? null : hash('sha256', CanonicalJson::encode($currentRow)));
        $this->check($checks, 'policy_rules_checksum', $policy->rules_checksum,
            hash('sha256', CanonicalJson::encode($policy->rules ?? [])));
        $this->check($checks, 'alert_policy_snapshot_checksum', $policy->rules_checksum,
            hash('sha256', CanonicalJson::encode($alert->policy_contract_snapshot ?? [])));

        $evaluation = [
            'snapshot_id' => $snapshot->id,
            'policy_version_id' => $policy->id,
            'sales_profile_id' => $alert->sales_profile_id,
            'alert_type' => $alert->alert_type,
            'threshold' => $alert->threshold_snapshot,
            'comparison' => $alert->comparison_snapshot,
            'metrics' => $alert->evidence_snapshot,
        ];
        $this->check($checks, 'alert_evaluation_checksum', $alert->evaluation_checksum,
            hash('sha256', CanonicalJson::encode($evaluation)));

        $run = DB::table('sales_alert_evaluation_runs')->where('snapshot_id', $snapshot->id)
            ->where('policy_version_id', $policy->id)->first();
        $runPayload = $run ? $this->json($run->result_snapshot) : null;
        $this->check($checks, 'evaluation_run_status', 'completed', $run?->status);
        $this->check($checks, 'evaluation_run_result_checksum', $run?->result_checksum,
            $runPayload === null ? null : hash('sha256', CanonicalJson::encode($runPayload)));
        $evaluationOutbox = $run ? DB::table('domain_outbox_events')->where('domain', 'sales')
            ->where('aggregate_type', 'sales_alert_evaluation')->where('aggregate_id', $run->id)
            ->where('event_version', 1)->first() : null;
        $evaluationOutboxPayload = $evaluationOutbox ? $this->json($evaluationOutbox->payload) : null;
        $this->check($checks, 'evaluation_run_outbox_link', $run?->result_checksum,
            data_get($evaluationOutboxPayload, 'result_checksum'));
        $this->check($checks, 'evaluation_outbox_payload_checksum', $evaluationOutbox?->payload_checksum,
            $evaluationOutboxPayload === null ? null : hash('sha256', CanonicalJson::encode($evaluationOutboxPayload)));

        $snapshotOutbox = DB::table('domain_outbox_events')->where('domain', 'sales')
            ->where('aggregate_type', 'sales_kpi_snapshot')->where('aggregate_id', $snapshot->id)
            ->where('event_version', 1)->first();
        $snapshotOutboxPayload = $snapshotOutbox ? $this->json($snapshotOutbox->payload) : null;
        $this->check($checks, 'snapshot_outbox_link', $snapshot->source_reconciliation_checksum,
            data_get($snapshotOutboxPayload, 'reconciliation_checksum'));
        $this->check($checks, 'snapshot_outbox_payload_checksum', $snapshotOutbox?->payload_checksum,
            $snapshotOutboxPayload === null ? null : hash('sha256', CanonicalJson::encode($snapshotOutboxPayload)));

        $actions = DB::table('sales_performance_alert_action_events')->where('alert_id', $alert->id)
            ->orderBy('to_version')->get();
        $expectedVersion = 1;
        $actionEvidence = $actions->map(function (object $event) use ($alert, &$checks, &$expectedVersion): array {
            $chainValid = (int) $event->from_version === $expectedVersion
                && (int) $event->to_version === $expectedVersion + 1;
            $this->check($checks, "action_chain_version_{$event->to_version}", 'continuous', $chainValid ? 'continuous' : 'broken');
            $expectedVersion = (int) $event->to_version;
            $outbox = DB::table('domain_outbox_events')->where('domain', 'sales')
                ->where('aggregate_type', 'sales_performance_alert')->where('aggregate_id', $alert->id)
                ->where('event_version', $event->to_version)->first();
            $payload = $outbox ? $this->json($outbox->payload) : null;
            $this->check($checks, "action_outbox_link_{$event->to_version}", $event->request_payload_checksum,
                data_get($payload, 'action_event_checksum'));
            $this->check($checks, "action_outbox_payload_checksum_{$event->to_version}", $outbox?->payload_checksum,
                $payload === null ? null : hash('sha256', CanonicalJson::encode($payload)));

            return [
                'action' => $event->action,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'from_version' => (int) $event->from_version,
                'to_version' => (int) $event->to_version,
                'reason' => $event->reason,
                'snoozed_until' => $event->snoozed_until,
                'escalation_level' => $event->escalation_level === null ? null : (int) $event->escalation_level,
                'occurred_at' => $event->occurred_at,
                'evidence_checksum' => $event->request_payload_checksum,
                'outbox_status' => $outbox === null ? 'missing' : ($outbox->published_at === null ? 'pending' : 'published'),
            ];
        })->values()->all();
        $this->check($checks, 'action_history_version', (string) $alert->event_version, (string) $expectedVersion);

        $companyEvidence = null;
        if ($includeCompanyEvidence) {
            $source = $snapshot->source_reconciliation_snapshot ?? [];
            $factChecksums = SalesMetricFact::query()->where('company_id', $alert->company_id)
                ->whereBetween('occurred_on', [$snapshot->period_start->toDateString(), $snapshot->period_end->toDateString()])
                ->where('occurred_at', '<=', $snapshot->cutoff_at)->orderBy('occurred_at')->orderBy('id')
                ->pluck('fact_checksum')->all();
            $targetChecksums = SalesTargetVersion::query()->where('company_id', $alert->company_id)->where('status', 'approved')
                ->whereDate('period_start', '<=', $snapshot->period_end)->whereDate('period_end', '>=', $snapshot->period_start)
                ->orderBy('sales_profile_id')->orderBy('period_start')->pluck('payload_checksum')->all();
            $current = [
                'fact_count' => count($factChecksums),
                'fact_checksum' => hash('sha256', implode('|', $factChecksums)),
                'target_count' => count($targetChecksums),
                'target_checksum' => hash('sha256', implode('|', $targetChecksums)),
                'row_count' => $currentRows->count(),
                'row_checksum' => hash('sha256', CanonicalJson::encode($currentRows->values()->all())),
                'task_intervention_checksum' => hash('sha256', implode('|', $currentRows
                    ->pluck('task_intervention_evidence_checksum')->filter()->sort()->values()->all())),
                'task_deadline_or_history_missing_count' => $currentRows
                    ->max('task_deadline_or_history_missing_count'),
            ];
            foreach ($current as $key => $actual) {
                $this->check($checks, "company_source_{$key}", data_get($source, $key), $actual);
            }
            $this->check($checks, 'frozen_source_reconciliation_checksum', $snapshot->source_reconciliation_checksum,
                hash('sha256', CanonicalJson::encode($source)));
            $companyEvidence = [
                'frozen' => $source,
                'current_checksums' => $current,
                'snapshot_checksum' => $snapshot->snapshot_checksum,
                'source_reconciliation_checksum' => $snapshot->source_reconciliation_checksum,
            ];
        }

        return [
            'status' => collect($checks)->every(fn (array $check) => $check['status'] === 'matched') ? 'matched' : 'mismatch',
            'privacy_scope' => $includeCompanyEvidence ? 'company_view_all' : 'authorized_profile_only',
            'alert' => [
                'id' => $alert->id,
                'type' => $alert->alert_type,
                'status' => $alert->status,
                'event_version' => (int) $alert->event_version,
                'threshold' => $alert->threshold_snapshot,
                'comparison' => $alert->comparison_snapshot,
                'metrics' => $alert->evidence_snapshot,
                'evaluation_checksum' => $alert->evaluation_checksum,
            ],
            'frozen_row' => [
                'snapshot_id' => $snapshot->id,
                'period_start' => $snapshot->period_start->toDateString(),
                'period_end' => $snapshot->period_end->toDateString(),
                'cutoff_at' => $snapshot->cutoff_at?->toIso8601String(),
                'row_checksum' => $row->row_checksum,
                'policy_version_id' => $policy->id,
                'policy_version' => (int) $policy->version,
                'policy_checksum' => $policy->rules_checksum,
                'evaluation_run_status' => $run?->status ?? 'missing',
                'evaluation_result_checksum' => $run?->result_checksum,
            ],
            'actions' => $actionEvidence,
            'checks' => $checks,
            'company_reconciliation' => $companyEvidence,
        ];
    }

    private function check(array &$checks, string $code, mixed $expected, mixed $actual): void
    {
        $expectedValue = is_scalar($expected) ? (string) $expected : null;
        $actualValue = is_scalar($actual) ? (string) $actual : null;
        $matched = $expectedValue !== null && $actualValue !== null && $expectedValue !== ''
            && hash_equals($expectedValue, $actualValue);
        $checks[] = ['code' => $code, 'status' => $matched ? 'matched' : 'mismatch', 'expected' => $expected, 'actual' => $actual];
    }

    private function json(mixed $value): ?array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        if (! is_string($value) || $value === '') return null;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
