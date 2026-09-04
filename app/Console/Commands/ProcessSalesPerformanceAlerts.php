<?php

namespace App\Console\Commands;

use App\Models\Sales\SalesKpiSnapshot;
use App\Services\Sales\SalesPerformanceService;
use Illuminate\Console\Command;

class ProcessSalesPerformanceAlerts extends Command
{
    protected $signature = 'sales:process-performance-alerts {--commit} {--limit=100}';
    protected $description = 'Preview or commit due, policy-governed closed-month Sales alert evaluations';

    public function handle(SalesPerformanceService $performance): int
    {
        if (! config('sales.features.performance_alert_evaluations', false)) {
            $this->error('Sales performance alert evaluation is disabled.');
            return self::FAILURE;
        }
        $limit = max(1, min(500, (int) $this->option('limit')));
        $snapshots = SalesKpiSnapshot::query()->where('status', 'frozen')->where('period_type', 'month')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('sales_alert_evaluation_runs as run')
                ->whereColumn('run.snapshot_id', 'sales_kpi_snapshots.id'))
            ->orderBy('period_end')->orderBy('id')->limit($limit)->get();
        $due = 0; $committed = 0; $blocked = 0;
        foreach ($snapshots as $snapshot) {
            try {
                $context = $performance->alertEvaluationContext($snapshot);
                if ($context['already_evaluated'] || now()->lt($context['due_at'])) continue;
                $due++;
                if ($this->option('commit')) {
                    $performance->evaluateSnapshotAlerts($snapshot);
                    $committed++;
                } else {
                    $this->line("DUE {$snapshot->id} at {$context['due_at']->toIso8601String()}");
                }
            } catch (\Throwable $exception) {
                $blocked++;
                $this->warn("BLOCKED {$snapshot->id}: {$exception->getMessage()}");
            }
        }
        $this->info("Candidates {$snapshots->count()}; due {$due}; committed {$committed}; blocked {$blocked}.");
        if (! $this->option('commit') && $due > 0) $this->comment('Preview only; rerun with --commit after reviewing blockers.');
        return $blocked > 0 ? self::FAILURE : self::SUCCESS;
    }
}
