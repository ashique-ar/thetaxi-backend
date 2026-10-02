<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesCommissionBusinessCalendar;
use App\Models\Sales\SalesCommissionBusinessCalendarDate;
use App\Models\Sales\SalesCommissionCycleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CommissionBusinessCalendarService
{
    public function schedule(SalesCommissionCycleVersion $cycle, CarbonImmutable $referenceDate): array
    {
        $calendar = $this->calendar($cycle, $referenceDate);
        $period = $cycle->earning_period_rule === 'previous_cutoff_to_cutoff'
            ? $this->cutoffPeriod($cycle, $calendar, $referenceDate)
            : ['period_start' => $referenceDate->startOfMonth(), 'period_end' => $referenceDate->endOfMonth()];

        $cutoff = $cycle->earning_period_rule === 'previous_cutoff_to_cutoff'
            ? $period['period_end']
            : $this->adjust($calendar, $this->nextDayOccurrence($period['period_end'], $cycle->cutoff_day), $cycle->holiday_rule);
        $finalization = $this->adjust($calendar, $this->nextDayOccurrence($cutoff, $cycle->finalization_day), $cycle->holiday_rule);
        $approval = $this->adjust($calendar, $this->nextDayOccurrence($finalization, $cycle->approval_deadline_day), $cycle->holiday_rule);
        $settlement = $this->adjust($calendar, $this->nextDayOccurrence($approval, $cycle->settlement_day), $cycle->holiday_rule);
        if ($calendar->effective_from->gt($period['period_start'])
            || ($calendar->effective_until && $calendar->effective_until->lte($settlement))) {
            throw ValidationException::withMessages(['business_calendar_id' => [
                'The business calendar effective interval must cover the earning period through settlement.',
            ]]);
        }

        return [
            ...$period,
            'cutoff_at' => $cutoff->endOfDay(),
            'finalization_at' => $finalization->endOfDay(),
            'approval_deadline_at' => $approval->endOfDay(),
            'settlement_at' => $settlement->endOfDay(),
            'calendar' => $calendar,
        ];
    }

    public function adjust(SalesCommissionBusinessCalendar $calendar, CarbonImmutable $date, string $rule): CarbonImmutable
    {
        if ($rule === 'no_movement' || $this->isBusinessDay($calendar, $date)) return $date;
        $step = $rule === 'previous_business_day' ? -1 : 1;
        for ($attempt = 0, $candidate = $date; $attempt < 366; $attempt++) {
            $candidate = $candidate->addDays($step);
            if ($this->isBusinessDay($calendar, $candidate)) return $candidate;
        }
        throw ValidationException::withMessages(['business_calendar_id' => ['No business day could be resolved within 366 days.']]);
    }

    private function cutoffPeriod(SalesCommissionCycleVersion $cycle, SalesCommissionBusinessCalendar $calendar, CarbonImmutable $reference): array
    {
        $current = $this->adjust($calendar, $reference->startOfMonth()->day($cycle->cutoff_day), $cycle->holiday_rule);
        if ($reference->lt($current)) $current = $this->adjust($calendar, $current->subMonthNoOverflow(), $cycle->holiday_rule);
        $previous = $this->adjust($calendar, $current->subMonthNoOverflow()->day($cycle->cutoff_day), $cycle->holiday_rule);
        return ['period_start' => $previous->addDay()->startOfDay(), 'period_end' => $current->endOfDay()];
    }

    private function calendar(SalesCommissionCycleVersion $cycle, CarbonImmutable $at): SalesCommissionBusinessCalendar
    {
        if (! $cycle->business_calendar_id) {
            throw ValidationException::withMessages(['business_calendar_id' => ['The commission cycle has no approved business calendar.']]);
        }
        $calendar = SalesCommissionBusinessCalendar::query()->whereKey($cycle->business_calendar_id)
            ->where('company_id', $cycle->company_id)->where('status', 'approved')
            ->whereDate('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $at))->first();
        if (! $calendar) {
            throw ValidationException::withMessages(['business_calendar_id' => ['The assigned business calendar is not approved and effective for this period.']]);
        }
        if ($calendar->timezone !== $cycle->timezone) {
            throw ValidationException::withMessages(['timezone' => ['Commission cycle and business calendar timezones must match.']]);
        }
        return $calendar;
    }

    private function isBusinessDay(SalesCommissionBusinessCalendar $calendar, CarbonImmutable $date): bool
    {
        $override = SalesCommissionBusinessCalendarDate::query()->where('calendar_id', $calendar->id)
            ->whereDate('calendar_date', $date)->first();
        if ($override) return $override->day_type === 'working_day';
        return in_array(strtolower($date->format('l')), $calendar->weekly_working_days, true);
    }

    private function nextDayOccurrence(CarbonImmutable $after, int $day): CarbonImmutable
    {
        $candidate = $after->startOfMonth()->day($day);
        return $candidate->lte($after) ? $candidate->addMonthNoOverflow() : $candidate;
    }
}
