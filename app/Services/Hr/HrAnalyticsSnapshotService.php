<?php

namespace App\Services\Hr;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrAnalyticsSnapshotService
{
    private const SUPPORTED_METRICS = [
        'headcount', 'joiners', 'leavers', 'vacant_positions',
        'approved_leave_minutes', 'approved_overtime_minutes', 'learning_completions',
    ];

    public function build(object $definition, string $asOfDate, array $filters): array
    {
        $contract = json_decode($definition->source_contract, true) ?: [];
        $policy = json_decode($definition->dimension_policy, true) ?: [];
        $metric = $contract['metric_kind'] ?? null;
        if (!in_array($metric, self::SUPPORTED_METRICS, true)) {
            throw ValidationException::withMessages(['source_contract.metric_kind' => 'The approved metric kind is not supported by the analytics adapter.']);
        }
        $allowedDimensions = $policy['allowed_dimensions'] ?? [];
        $dimension = $filters['dimension'] ?? null;
        if ($dimension && !in_array($dimension, $allowedDimensions, true)) {
            throw ValidationException::withMessages(['dimension' => 'The requested dimension is not approved by this metric definition.']);
        }
        $asOf = CarbonImmutable::parse($asOfDate)->endOfDay();
        $periodStart = match ($contract['period_kind'] ?? 'month') {
            'day' => $asOf->startOfDay(), 'year_to_date' => $asOf->startOfYear(), default => $asOf->startOfMonth(),
        };
        $facts = $this->facts($definition->company_id, $metric, $periodStart, $asOf);
        $grouped = $dimension ? $facts->groupBy(fn ($fact) => $this->dimension($definition->company_id, $fact, $dimension, $asOf)) : collect(['all' => $facts]);
        $minimum = max(1, (int) $definition->minimum_group_size);
        $aggregates = []; $suppression = [];
        foreach ($grouped as $group => $rows) {
            $population = $metric === 'vacant_positions' ? $rows->count() : $rows->pluck('staff_id')->filter()->unique()->count();
            if ($dimension && $population < $minimum) {
                $aggregates[$group] = ['suppressed' => true, 'value' => null, 'population' => null];
                $suppression[$group] = ['rule' => 'minimum_group_size', 'threshold' => $minimum];
                continue;
            }
            $aggregates[$group] = ['suppressed' => false, 'value' => $rows->sum('value'), 'population' => $population];
        }
        $source = $facts->map(fn ($fact) => ['id' => $fact['id'], 'value' => $fact['value'], 'source_updated_at' => $fact['source_updated_at']])->sortBy('id')->values()->all();
        return [
            'filter_snapshot' => ['dimension' => $dimension, 'metric_kind' => $metric, 'period_start' => $periodStart->toDateString(), 'period_end' => $asOf->toDateString()],
            'aggregate_payload' => ['metric_code' => $definition->metric_code, 'metric_kind' => $metric, 'unit' => $contract['unit'] ?? 'count', 'groups' => $aggregates],
            'suppression_snapshot' => ['minimum_group_size' => $minimum, 'suppressed_groups' => $suppression],
            'source_checksum' => hash('sha256', json_encode(['definition_checksum' => $definition->definition_checksum, 'as_of' => $asOf->toIso8601String(), 'sources' => $source], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    private function facts(string $companyId, string $metric, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return match ($metric) {
            'headcount' => DB::table('hr_employment_assignments')->where('company_id', $companyId)->whereDate('effective_from', '<=', $to)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $to))->get()->unique('staff_id')->map(fn ($r) => $this->fact($r->id, $r->staff_id, 1, $r->updated_at)),
            'joiners' => DB::table('hr_employment_spells')->where('company_id', $companyId)->whereBetween('joined_at', [$from->toDateString(), $to->toDateString()])->get()->map(fn ($r) => $this->fact($r->id, $r->staff_id, 1, $r->updated_at)),
            'leavers' => DB::table('hr_employment_spells')->where('company_id', $companyId)->whereBetween('terminated_at', [$from->toDateString(), $to->toDateString()])->get()->map(fn ($r) => $this->fact($r->id, $r->staff_id, 1, $r->updated_at)),
            'vacant_positions' => DB::table('hr_positions')->where('company_id', $companyId)->where('status', 'vacant')->whereDate('effective_from', '<=', $to)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $to))->get()->map(fn ($r) => ['id' => $r->id, 'staff_id' => null, 'organization_unit_id' => $r->organization_unit_id, 'value' => (int) $r->headcount_limit, 'source_updated_at' => (string) $r->updated_at]),
            'approved_leave_minutes' => DB::table('hr_leave_requests')->where('company_id', $companyId)->whereIn('status', ['approved', 'posted', 'taken'])->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get()->map(fn ($r) => $this->fact($r->id, $r->staff_id, (int) $r->reserved_minutes, $r->updated_at)),
            'approved_overtime_minutes' => DB::table('hr_work_requests')->where('company_id', $companyId)->where('request_kind', 'overtime')->where('status', 'approved')->whereBetween('starts_at', [$from, $to])->get()->map(fn ($r) => $this->fact($r->id, $r->staff_id, (int) $r->requested_minutes, $r->updated_at)),
            'learning_completions' => DB::table('hr_learning_enrollments as e')->join('hr_course_sessions as s', 's.id', '=', 'e.session_id')->join('hr_courses as c', 'c.id', '=', 's.course_id')->where('c.company_id', $companyId)->where('e.status', 'completed')->whereBetween('e.completed_at', [$from, $to])->select(['e.id', 'e.staff_id', 'e.updated_at'])->get()->map(fn ($r) => $this->fact($r->id, $r->staff_id, 1, $r->updated_at)),
        };
    }

    private function dimension(string $companyId, array $fact, string $dimension, CarbonImmutable $asOf): string
    {
        if ($dimension === 'organization_unit' && isset($fact['organization_unit_id'])) return (string) $fact['organization_unit_id'];
        if (!$fact['staff_id']) return 'unspecified';
        $assignment = DB::table('hr_employment_assignments')->where('company_id', $companyId)->where('staff_id', $fact['staff_id'])->whereDate('effective_from', '<=', $asOf)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))->latest('effective_from')->first();
        if ($dimension === 'organization_unit') return (string) ($assignment?->organization_unit_id ?? 'unspecified');
        if ($dimension === 'location') return (string) ($assignment?->location_code ?? 'unspecified');
        if ($dimension === 'staff_type') return (string) (DB::table('staff')->where('id', $fact['staff_id'])->value('staff_type') ?? 'unspecified');
        $joined = DB::table('hr_employment_spells')->where('company_id', $companyId)->where('staff_id', $fact['staff_id'])->min('joined_at');
        if (!$joined) return 'unspecified';
        $years = CarbonImmutable::parse($joined)->diffInYears($asOf);
        return $years < 1 ? 'under_1_year' : ($years < 3 ? '1_to_3_years' : ($years < 5 ? '3_to_5_years' : '5_plus_years'));
    }

    private function fact(string $id, ?string $staffId, int|float $value, mixed $updatedAt): array
    {
        return ['id' => $id, 'staff_id' => $staffId, 'value' => $value, 'source_updated_at' => (string) $updatedAt];
    }
}
