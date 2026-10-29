<?php

namespace App\Services\Sales;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

class InquiryFirstResponseDeadlineService
{
    // shortcut: holidays are not modeled; add dated company closures when holiday-aware targets are needed.
    public function dueAt(Carbon $receivedAt, int $targetMinutes, ?array $businessHours): Carbon
    {
        if (! $businessHours) return $receivedAt->copy()->addMinutes($targetMinutes);

        $timezone = $businessHours['timezone'];
        $hoursByDay = [];
        if (isset($businessHours['opens_at'], $businessHours['closes_at'])) {
            foreach ($businessHours['days'] as $day) $hoursByDay[(int) $day] = ['opens_at' => $businessHours['opens_at'], 'closes_at' => $businessHours['closes_at']];
        } else {
            foreach ($businessHours['days'] as $day) $hoursByDay[(int) $day['day']] = $day;
        }
        $minutesPerWeek = array_sum(array_map(fn ($hours) => (int) ((strtotime($hours['closes_at']) - strtotime($hours['opens_at'])) / 60), $hoursByDay));
        if ($minutesPerWeek < 1) throw new \InvalidArgumentException('The approved inquiry-response business hours are invalid.');
        $remaining = $targetMinutes;
        $cursor = $receivedAt->copy()->setTimezone($timezone);
        $weekEnd = $cursor->copy()->startOfWeek(Carbon::MONDAY)->startOfDay()->addWeek();

        while ($cursor->lt($weekEnd)) {
            $day = $cursor->dayOfWeekIso;
            if (isset($hoursByDay[$day])) {
                $start = Carbon::parse($cursor->toDateString().' '.$hoursByDay[$day]['opens_at'], $timezone);
                $end = Carbon::parse($cursor->toDateString().' '.$hoursByDay[$day]['closes_at'], $timezone);
                if ($cursor->lt($start)) $cursor = $start;
                if ($cursor->lt($end)) {
                    $available = intdiv($end->getTimestamp() - $cursor->getTimestamp(), 60);
                    if ($remaining <= $available) return $cursor->addMinutes($remaining);
                    $remaining -= $available;
                }
            }
            $cursor = $cursor->copy()->startOfDay()->addDay();
        }

        $wholeWeeks = intdiv($remaining - 1, $minutesPerWeek);
        if ($wholeWeeks > 0) {
            $cursor->addWeeks($wholeWeeks);
            $remaining -= $wholeWeeks * $minutesPerWeek;
        }
        $weekEnd = $cursor->copy()->addWeek();
        while ($cursor->lt($weekEnd)) {
            $day = $cursor->dayOfWeekIso;
            if (isset($hoursByDay[$day])) {
                $start = Carbon::parse($cursor->toDateString().' '.$hoursByDay[$day]['opens_at'], $timezone);
                $end = Carbon::parse($cursor->toDateString().' '.$hoursByDay[$day]['closes_at'], $timezone);
                if ($cursor->lt($start)) $cursor = $start;
                if ($cursor->lt($end)) {
                    $available = intdiv($end->getTimestamp() - $cursor->getTimestamp(), 60);
                    if ($remaining <= $available) return $cursor->addMinutes($remaining);
                    $remaining -= $available;
                }
            }
            $cursor = $cursor->copy()->startOfDay()->addDay();
        }

        return $cursor;
    }

    public function constrainOverdue(Builder|EloquentBuilder $query, ?int $legacyTargetMinutes, Carbon $now): void
    {
        if (! Schema::hasColumn('inquiries', 'first_response_due_at')) {
            if ($legacyTargetMinutes !== null) $query->where('created_at', '<=', $now->copy()->subMinutes($legacyTargetMinutes));
            return;
        }
        $query->where(function ($due) use ($legacyTargetMinutes, $now): void {
            $due->whereNotNull('first_response_due_at')->where('first_response_due_at', '<=', $now);
            if ($legacyTargetMinutes !== null) {
                $due->orWhere(fn ($legacy) => $legacy->whereNull('first_response_due_at')
                    ->where('created_at', '<=', $now->copy()->subMinutes($legacyTargetMinutes)));
            }
        });
    }
}
