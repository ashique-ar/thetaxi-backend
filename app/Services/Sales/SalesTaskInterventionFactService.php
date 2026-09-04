<?php

namespace App\Services\Sales;

use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesTaskInterventionFactService
{
    public function forClosedPeriod(
        string $companyId,
        array $profileIds,
        string $periodStart,
        string $periodEnd,
        string $cutoffAt,
        array $rules,
    ): array {
        $timezone = config('sales.business_timezone');
        abort_unless(is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true), 409,
            'An approved Sales business timezone is required for task intervention facts.');
        $lookbackMonths = $rules['repeatedly_missed_next_actions']['lookback_completed_months'];
        $start = CarbonImmutable::parse($periodStart, $timezone)->startOfDay();
        $endExclusive = CarbonImmutable::parse($periodEnd, $timezone)->addDay()->startOfDay()->utc();
        $asOf = $endExclusive->min(CarbonImmutable::parse($cutoffAt)->utc());
        $lookbackStart = $start->subMonths($lookbackMonths - 1)->startOfMonth()->utc();

        $all = DB::table('sales_tasks')->where('company_id', $companyId)
            ->where('created_at', '<', $asOf)->where('due_at', '<', $asOf)->get([
                'id', 'owner_sales_profile_id', 'due_at', 'created_at', 'deadline_contract_version',
                'deadline_snapshot', 'deadline_checksum',
            ]);
        $governed = collect();
        $missingTaskIds = [];
        foreach ($all as $task) {
            if (! $this->deadlineIsGoverned($task)) {
                $missingTaskIds[$task->id] = true;
                continue;
            }
            $governed->push($task);
        }

        $events = $governed->isEmpty() ? collect() : DB::table('sales_task_events')
            ->whereIn('task_id', $governed->pluck('id'))->where('occurred_at', '<', $asOf)
            ->orderBy('occurred_at')->orderBy('id')->get([
                'id', 'task_id', 'event_type', 'to_status', 'to_owner_sales_profile_id', 'occurred_at',
            ])->groupBy('task_id');
        $evidence = collect($profileIds)->mapWithKeys(fn (string $id) => [$id => [
            'overdue' => [], 'missed' => [],
        ]])->all();

        foreach ($governed as $task) {
            $history = $events->get($task->id, collect());
            $periodEndState = $this->stateAt($history, $asOf);
            if (! $periodEndState) {
                $missingTaskIds[$task->id] = true;
                continue;
            }
            $dueAt = $this->governedDueAt($task);
            if (in_array($periodEndState['status'], ['open', 'in_progress'], true)) {
                if (! isset($evidence[$periodEndState['owner_sales_profile_id']])) {
                    $missingTaskIds[$task->id] = true;
                } else {
                    $ageDays = $dueAt->setTimezone($timezone)->startOfDay()
                        ->diffInDays($asOf->setTimezone($timezone)->startOfDay());
                    $evidence[$periodEndState['owner_sales_profile_id']]['overdue'][] = [
                        'task_id' => $task->id, 'due_at_utc' => $dueAt->toIso8601String(),
                        'age_days' => $ageDays, 'deadline_checksum' => $task->deadline_checksum,
                    ];
                }
            }
            if ($dueAt->gte($lookbackStart)) {
                $dueState = $this->stateAt($history, $dueAt);
                if (! $dueState) {
                    $missingTaskIds[$task->id] = true;
                } elseif (in_array($dueState['status'], ['open', 'in_progress'], true)) {
                    if (! isset($evidence[$dueState['owner_sales_profile_id']])) {
                        $missingTaskIds[$task->id] = true;
                    } else {
                        $evidence[$dueState['owner_sales_profile_id']]['missed'][] = [
                            'task_id' => $task->id, 'due_at_utc' => $dueAt->toIso8601String(),
                            'deadline_checksum' => $task->deadline_checksum,
                        ];
                    }
                }
            }
        }

        $missingDeadlineCount = count($missingTaskIds);
        return collect($evidence)->map(function (array $profileEvidence) use ($lookbackMonths, $missingDeadlineCount) {
            $overdue = collect($profileEvidence['overdue'])
                ->sortBy(fn (array $row) => $row['due_at_utc'].'|'.$row['task_id'])->values()->all();
            $missed = collect($profileEvidence['missed'])
                ->sortBy(fn (array $row) => $row['due_at_utc'].'|'.$row['task_id'])->values()->all();
            $snapshot = [
                'contract' => 'governed_sales_task_event_history_v1',
                'overdue' => $overdue, 'missed' => $missed,
                'lookback_completed_months' => $lookbackMonths,
                'task_deadline_or_history_missing_count' => $missingDeadlineCount,
            ];
            return [
                'overdue_task_count' => count($overdue),
                'oldest_overdue_task_age_days' => $overdue === [] ? null : max(array_column($overdue, 'age_days')),
                'missed_next_action_count' => count($missed),
                'missed_next_action_lookback_completed_months' => $lookbackMonths,
                'task_deadline_or_history_missing_count' => $missingDeadlineCount,
                'task_intervention_evidence_checksum' => hash('sha256', CanonicalJson::encode($snapshot)),
            ];
        })->all();
    }

    private function deadlineIsGoverned(object $task): bool
    {
        if ($task->deadline_contract_version !== 'explicit_utc_v1' || ! is_string($task->deadline_checksum)) return false;
        $snapshot = json_decode((string) $task->deadline_snapshot, true);
        if (! is_array($snapshot) || ($snapshot['contract_version'] ?? null) !== 'explicit_utc_v1') return false;
        if (! hash_equals($task->deadline_checksum, hash('sha256', CanonicalJson::encode($snapshot)))) return false;
        if (! is_string($snapshot['due_at_utc'] ?? null) || $snapshot['due_at_utc'] === '') return false;
        try {
            CarbonImmutable::parse($snapshot['due_at_utc'])->utc();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function governedDueAt(object $task): CarbonImmutable
    {
        $snapshot = json_decode((string) $task->deadline_snapshot, true, 512, JSON_THROW_ON_ERROR);
        return CarbonImmutable::parse($snapshot['due_at_utc'])->utc();
    }

    private function stateAt(Collection $events, CarbonImmutable $instant): ?array
    {
        $status = null;
        $owner = null;
        foreach ($events as $event) {
            if (CarbonImmutable::parse($event->occurred_at)->utc()->gt($instant)) break;
            if ($event->to_status !== null) $status = $event->to_status;
            if ($event->to_owner_sales_profile_id !== null) $owner = $event->to_owner_sales_profile_id;
        }
        return $status !== null && $owner !== null ? ['status' => $status, 'owner_sales_profile_id' => $owner] : null;
    }
}
