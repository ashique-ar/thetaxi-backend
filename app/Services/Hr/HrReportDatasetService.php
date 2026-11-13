<?php

namespace App\Services\Hr;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Builds report rows from immutable snapshots and run-time state boundaries. */
class HrReportDatasetService
{
    public function rows(object $run, object $view): array
    {
        $snapshot = json_decode($run->filter_snapshot, true) ?: [];
        $filters = $snapshot['filters'] ?? $snapshot;
        $cutoffAt = (string) $run->cutoff_at;

        return match ($view->report_kind) {
            'analytics_snapshots' => $this->analytics($filters, $run->as_of_date, $cutoffAt),
            'workforce_plans' => $this->plans($filters, $run->as_of_date, $cutoffAt),
            'data_quality' => $this->quality($filters, $snapshot['data_quality_snapshot'] ?? null, $run->as_of_date, $cutoffAt),
            default => [],
        };
    }

    private function analytics(array $filters, string $asOf, string $cutoffAt): array
    {
        $query = DB::table('hr_analytics_snapshots as s')
            ->join('hr_analytics_definition_versions as d', 'd.id', '=', 's.definition_version_id')
            ->whereDate('s.as_of_date', '<=', $asOf)
            ->where('s.cutoff_at', '<=', $cutoffAt)
            ->when($filters['metric_code'] ?? null, fn ($q, $value) => $q->where('d.metric_code', $value))
            ->when($filters['dimension'] ?? null, fn ($q, $value) => $q->whereJsonContains('s.filter_snapshot->dimension', $value))
            ->when($filters['as_of_from'] ?? null, fn ($q, $value) => $q->whereDate('s.as_of_date', '>=', $value))
            ->when($filters['as_of_to'] ?? null, fn ($q, $value) => $q->whereDate('s.as_of_date', '<=', $value));

        return $query->select([
            'd.metric_code', 'd.name as metric_name', 's.as_of_date', 's.aggregate_payload as aggregate',
            's.suppression_snapshot as suppression', 's.snapshot_checksum',
        ])->orderBy('d.metric_code')->orderBy('s.as_of_date')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function plans(array $filters, string $asOf, string $cutoffAt): array
    {
        $query = DB::table('hr_workforce_plan_versions as plan')
            ->where('plan.created_at', '<=', $cutoffAt)
            ->whereDate('plan.baseline_as_of', '<=', $asOf)
            ->when($filters['as_of_from'] ?? null, fn ($q, $value) => $q->whereDate('plan.baseline_as_of', '>=', $value))
            ->when($filters['as_of_to'] ?? null, fn ($q, $value) => $q->whereDate('plan.baseline_as_of', '<=', $value));

        $statusAtCutoff = $this->planStatusAtCutoffSql();
        if (!empty($filters['status'])) {
            $query->whereRaw($statusAtCutoff.' = ?', [$cutoffAt, $cutoffAt, $cutoffAt, $filters['status']]);
        }

        $latestEventChecksum = DB::table('hr_workforce_plan_events as event')
            ->select('event.plan_checksum')
            ->whereColumn('event.plan_version_id', 'plan.id')
            ->where('event.occurred_at', '<=', $cutoffAt)
            ->orderByDesc('event.occurred_at')->orderByDesc('event.id')->limit(1);

        return $query->select([
            'plan.code as plan_code', 'plan.name as plan_name',
            DB::raw("CONCAT(plan.horizon_start, ' - ', plan.horizon_end) as horizon"),
        ])->selectRaw($statusAtCutoff.' as plan_status', [$cutoffAt, $cutoffAt, $cutoffAt])
            ->selectSub($latestEventChecksum, 'snapshot_checksum')
            ->orderBy('plan.horizon_start')->orderBy('plan.code')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function planStatusAtCutoffSql(): string
    {
        return "COALESCE((SELECT event.to_status FROM hr_workforce_plan_events AS event WHERE event.plan_version_id = plan.id AND event.occurred_at <= ? ORDER BY event.occurred_at DESC, event.id DESC LIMIT 1), CASE WHEN plan.approved_at IS NOT NULL AND plan.approved_at <= ? THEN plan.status WHEN plan.submitted_at IS NOT NULL AND plan.submitted_at <= ? THEN 'pending_approval' ELSE 'draft' END)";
    }

    private function quality(array $filters, ?array $sourceSnapshot, string $asOf, string $cutoffAt): array
    {
        $sourceChecksum = $sourceSnapshot['source_checksum'] ?? null;
        if (!$sourceSnapshot) {
            $snapshot = DB::table('hr_data_quality_snapshots')
                ->whereDate('as_of_date', '<=', $asOf)
                ->where('cutoff_at', '<=', $cutoffAt)
                ->when($filters['as_of_from'] ?? null, fn ($q, $value) => $q->whereDate('as_of_date', '>=', $value))
                ->when($filters['as_of_to'] ?? null, fn ($q, $value) => $q->whereDate('as_of_date', '<=', $value))
                ->orderByDesc('as_of_date')->orderByDesc('cutoff_at')->first();
            $sourceSnapshot = $snapshot ? json_decode($snapshot->summary_payload, true) : null;
            $sourceChecksum = $snapshot?->source_checksum;
        }

        if (!is_array($sourceSnapshot) || !isset($sourceSnapshot['issues']) || !is_array($sourceSnapshot['issues'])) {
            throw new RuntimeException('No frozen data-quality summary is available for this report run. Queue a new report to capture one.');
        }
        if ($sourceChecksum && !hash_equals(
            $sourceChecksum,
            hash('sha256', json_encode($sourceSnapshot['issues'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        )) {
            throw new RuntimeException('The frozen data-quality summary failed its integrity check.');
        }

        $inAsOfRange = empty($filters['as_of_from']) || $asOf >= $filters['as_of_from'];
        $inAsOfRange = $inAsOfRange && (empty($filters['as_of_to']) || $asOf <= $filters['as_of_to']);
        $issues = $inAsOfRange ? $sourceSnapshot['issues'] : [];
        if (!empty($filters['issue_type'])) {
            $issues = array_values(array_filter($issues, fn ($issue) => ($issue['code'] ?? null) === $filters['issue_type'] || ($issue['domain'] ?? null) === $filters['issue_type']));
        }

        return array_map(fn ($issue) => [
            'issue_code' => $issue['code'],
            'issue_domain' => $issue['domain'],
            'issue_severity' => $issue['severity'],
            'issue_count' => $issue['available'] ? $issue['count'] : 'unavailable',
        ], $issues);
    }
}
