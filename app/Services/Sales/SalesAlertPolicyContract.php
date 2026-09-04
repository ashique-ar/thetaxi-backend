<?php

namespace App\Services\Sales;

use App\Support\Foundation\CanonicalJson;

class SalesAlertPolicyContract
{
    public function isComplete(array $rules): bool
    {
        try {
            $this->normalize($rules);
            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return false;
        }
    }

    public function normalize(array $rules): array
    {
        $this->assertExactKeys($rules, [
            'no_new_sales', 'no_sales_activity', 'overdue_collections', 'overdue_tasks',
            'repeatedly_missed_next_actions',
            'recurring_commission_reliance', 'decline_against_completed_month_average', 'evaluation',
        ], 'rules');

        foreach (['no_new_sales', 'no_sales_activity'] as $name) {
            $this->assertExactKeys($rules[$name] ?? null, ['enabled', 'severity'], $name);
            $this->assertBoolean($rules[$name]['enabled'], $name.'.enabled');
            $this->assertSeverity($rules[$name]['severity'], $name.'.severity');
        }
        $this->assertExactKeys($rules['overdue_collections'] ?? null,
            ['enabled', 'severity', 'minimum_amount_lkr', 'minimum_age_days'], 'overdue_collections');
        $this->assertBoolean($rules['overdue_collections']['enabled'], 'overdue_collections.enabled');
        $this->assertSeverity($rules['overdue_collections']['severity'], 'overdue_collections.severity');
        $this->assertNumber($rules['overdue_collections']['minimum_amount_lkr'], 0, 9999999999999999, 'overdue_collections.minimum_amount_lkr');
        $this->assertInteger($rules['overdue_collections']['minimum_age_days'], 1, 3660, 'overdue_collections.minimum_age_days');

        $this->assertExactKeys($rules['overdue_tasks'] ?? null,
            ['enabled', 'severity', 'minimum_count', 'minimum_age_days', 'status_basis', 'owner_basis'],
            'overdue_tasks');
        $this->assertBoolean($rules['overdue_tasks']['enabled'], 'overdue_tasks.enabled');
        $this->assertSeverity($rules['overdue_tasks']['severity'], 'overdue_tasks.severity');
        $this->assertInteger($rules['overdue_tasks']['minimum_count'], 1, 10000, 'overdue_tasks.minimum_count');
        $this->assertInteger($rules['overdue_tasks']['minimum_age_days'], 1, 3660, 'overdue_tasks.minimum_age_days');
        abort_unless($rules['overdue_tasks']['status_basis'] === 'open_or_in_progress_at_period_end', 422,
            'Overdue task status basis must use the frozen period-end task history.');
        abort_unless($rules['overdue_tasks']['owner_basis'] === 'owner_at_period_end', 422,
            'Overdue task ownership must use the frozen period-end owner.');

        $this->assertExactKeys($rules['repeatedly_missed_next_actions'] ?? null,
            ['enabled', 'severity', 'minimum_count', 'lookback_completed_months', 'deadline_basis', 'owner_basis', 'source'],
            'repeatedly_missed_next_actions');
        $this->assertBoolean($rules['repeatedly_missed_next_actions']['enabled'], 'repeatedly_missed_next_actions.enabled');
        $this->assertSeverity($rules['repeatedly_missed_next_actions']['severity'], 'repeatedly_missed_next_actions.severity');
        $this->assertInteger($rules['repeatedly_missed_next_actions']['minimum_count'], 2, 10000,
            'repeatedly_missed_next_actions.minimum_count');
        $this->assertInteger($rules['repeatedly_missed_next_actions']['lookback_completed_months'], 1, 12,
            'repeatedly_missed_next_actions.lookback_completed_months');
        abort_unless($rules['repeatedly_missed_next_actions']['deadline_basis'] === 'open_or_in_progress_at_due', 422,
            'Missed next actions must be factual tasks that were open or in progress at their deadline.');
        abort_unless($rules['repeatedly_missed_next_actions']['owner_basis'] === 'owner_at_due', 422,
            'Missed next actions must be attributed to the task owner at the deadline.');
        abort_unless($rules['repeatedly_missed_next_actions']['source'] === 'governed_sales_task_event_history', 422,
            'Missed next actions must use governed Sales task event history.');

        $this->assertExactKeys($rules['recurring_commission_reliance'] ?? null,
            ['enabled', 'severity', 'prior_booking_commission_percent', 'new_sales_achievement_below_percent'],
            'recurring_commission_reliance');
        $this->assertBoolean($rules['recurring_commission_reliance']['enabled'], 'recurring_commission_reliance.enabled');
        $this->assertSeverity($rules['recurring_commission_reliance']['severity'], 'recurring_commission_reliance.severity');
        $this->assertNumber($rules['recurring_commission_reliance']['prior_booking_commission_percent'], 0, 100,
            'recurring_commission_reliance.prior_booking_commission_percent');
        $this->assertNumber($rules['recurring_commission_reliance']['new_sales_achievement_below_percent'], 0, 100,
            'recurring_commission_reliance.new_sales_achievement_below_percent');

        $this->assertExactKeys($rules['decline_against_completed_month_average'] ?? null,
            ['enabled', 'severity', 'percent', 'baseline_months'], 'decline_against_completed_month_average');
        $this->assertBoolean($rules['decline_against_completed_month_average']['enabled'], 'decline_against_completed_month_average.enabled');
        $this->assertSeverity($rules['decline_against_completed_month_average']['severity'], 'decline_against_completed_month_average.severity');
        $this->assertNumber($rules['decline_against_completed_month_average']['percent'], 0, 100,
            'decline_against_completed_month_average.percent');
        $this->assertInteger($rules['decline_against_completed_month_average']['baseline_months'], 1, 12,
            'decline_against_completed_month_average.baseline_months');

        $evaluation = $rules['evaluation'] ?? null;
        $this->assertExactKeys($evaluation, [
            'grain', 'schedule', 'grace_days', 'minimum_elapsed_days', 'baseline_completeness',
            'missing_data_behavior', 'comparison_normalization', 'recurring_reliance_basis', 'timezone_source',
            'owner_user_id',
        ], 'evaluation');
        abort_unless($evaluation['grain'] === 'closed_calendar_month', 422,
            'Only the implemented closed-calendar-month alert grain can be activated.');
        $this->assertExactKeys($evaluation['schedule'] ?? null, ['frequency', 'local_time'], 'evaluation.schedule');
        abort_unless($evaluation['schedule']['frequency'] === 'once_after_close', 422,
            'Only once-after-close evaluation is implemented; another cadence cannot be inferred.');
        abort_unless(is_string($evaluation['schedule']['local_time'])
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $evaluation['schedule']['local_time']) === 1, 422,
            'Alert evaluation local time must use HH:MM.');
        $this->assertInteger($evaluation['grace_days'], 0, 31, 'evaluation.grace_days');
        $this->assertInteger($evaluation['minimum_elapsed_days'], 1, 31, 'evaluation.minimum_elapsed_days');
        abort_unless(in_array($evaluation['baseline_completeness'], ['require_complete', 'suppress_rule_and_flag'], true), 422,
            'Baseline completeness behavior is invalid.');
        abort_unless($evaluation['missing_data_behavior'] === 'suppress_rule_and_flag', 422,
            'Missing alert data must suppress the affected rule and create visible quality evidence.');
        abort_unless($evaluation['comparison_normalization'] === 'completed_calendar_months_only', 422,
            'Only completed-calendar-month comparison is implemented.');
        abort_unless($evaluation['recurring_reliance_basis'] === 'prior_period_booking_commission', 422,
            'Recurring reliance must use prior-period booking commission in this policy version.');
        abort_unless($evaluation['timezone_source'] === 'sales_business_timezone', 422,
            'Alert evaluation must use the approved Sales business timezone.');
        abort_unless(is_string($evaluation['owner_user_id'])
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $evaluation['owner_user_id']) === 1,
            422, 'An explicit alert owner user is required.');

        return json_decode(CanonicalJson::encode($rules), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertExactKeys(mixed $value, array $keys, string $field): void
    {
        $actual = is_array($value) ? array_keys($value) : [];
        sort($actual); sort($keys);
        abort_unless(is_array($value) && $actual === $keys, 422,
            "The {$field} policy contract has missing or extra fields.");
    }

    private function assertBoolean(mixed $value, string $field): void
    {
        abort_unless(is_bool($value), 422, "{$field} must be an explicit boolean.");
    }

    private function assertSeverity(mixed $value, string $field): void
    {
        abort_unless(in_array($value, ['low', 'medium', 'high'], true), 422, "{$field} is invalid.");
    }

    private function assertNumber(mixed $value, float $minimum, float $maximum, string $field): void
    {
        abort_unless(is_int($value) || is_float($value), 422, "{$field} must be numeric.");
        abort_unless($value >= $minimum && $value <= $maximum, 422, "{$field} is outside the supported range.");
    }

    private function assertInteger(mixed $value, int $minimum, int $maximum, string $field): void
    {
        abort_unless(is_int($value) && $value >= $minimum && $value <= $maximum, 422,
            "{$field} must be an integer between {$minimum} and {$maximum}.");
    }
}
