<?php

namespace App\Services;

use App\Models\Booking\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates individual Booking occurrences from a recurring-template booking.
 *
 * A "template" booking has is_recurring = true and no recurring_series_id.
 * Generated occurrences clone the template and receive:
 *   - recurring_series_id   = template booking ID
 *   - recurring_sequence    = incrementing number
 *   - recurring_occurrence_date = actual date of this occurrence
 *   - is_recurring          = false  (occurrences are plain bookings)
 */
class RecurringBookingService
{
    // How many days ahead to pre-generate occurrences
    private int $lookaheadDays = 30;

    public function __construct()
    {
        $this->lookaheadDays = (int) (\App\Models\Website\WebsiteSetting::getValue(
            'recurring_booking_lookahead_days',
            30
        ) ?? 30);
    }

    // ─────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────

    /**
     * Generate all missing occurrences for a single recurring template.
     * Returns the number of new bookings created.
     */
    public function generateOccurrences(Booking $template): int
    {
        if (!$template->is_recurring || $template->recurring_series_id) {
            return 0;
        }

        $until   = $template->recurrence_end_date
            ? Carbon::parse($template->recurrence_end_date)
            : Carbon::now()->addDays($this->lookaheadDays);

        $horizon = Carbon::now()->addDays($this->lookaheadDays);
        $end     = $until->min($horizon);

        if ($end->isPast()) {
            return 0;
        }

        $dates = $this->computeOccurrenceDates($template, Carbon::today(), $end);

        if (empty($dates)) {
            return 0;
        }

        // Find already-generated occurrences so we skip existing ones
        $existing = Booking::where('recurring_series_id', $template->id)
            ->pluck('recurring_occurrence_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        $created = 0;

        foreach ($dates as $date) {
            if (in_array($date->toDateString(), $existing, true)) {
                continue;
            }

            try {
                $this->createOccurrence($template, $date, $created + 1);
                $created++;
            } catch (\Throwable $e) {
                Log::error('Failed to create recurring booking occurrence', [
                    'template_id' => $template->id,
                    'date'        => $date->toDateString(),
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    /**
     * Generate occurrences for ALL active recurring templates.
     * Called by the scheduler once per day.
     */
    public function generateAllDue(): array
    {
        $templates = Booking::where('is_recurring', true)
            ->whereNull('recurring_series_id')
            ->where(function ($q) {
                $q->whereNull('recurrence_end_date')
                  ->orWhere('recurrence_end_date', '>=', now()->toDateString());
            })
            ->whereNotIn('status', ['cancelled', 'inquiry_cancelled'])
            ->get();

        $summary = ['processed' => 0, 'created' => 0, 'errors' => 0];

        foreach ($templates as $template) {
            try {
                $count            = $this->generateOccurrences($template);
                $summary['created']    += $count;
                $summary['processed']++;
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::error('RecurringBookingService error', [
                    'template_id' => $template->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    // ─────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Compute all occurrence dates for a template between $from and $until.
     *
     * @return Carbon[]
     */
    private function computeOccurrenceDates(Booking $template, Carbon $from, Carbon $until): array
    {
        $pattern = strtolower($template->recurrence_pattern ?? 'weekly');
        $days    = $template->recurrence_days ?? [];   // e.g. ['monday','wednesday']
        $dates   = [];

        // Start from the day after the template's own from_date (or today, whichever is later)
        $start = $template->from_date
            ? Carbon::parse($template->from_date)->addDay()->max($from)
            : $from;

        $cursor = $start->copy();

        while ($cursor->lte($until)) {
            if ($this->matchesPattern($cursor, $pattern, $days)) {
                $dates[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        return $dates;
    }

    private function matchesPattern(Carbon $date, string $pattern, array $days): bool
    {
        return match ($pattern) {
            'daily'   => true,
            'weekly'  => empty($days)
                         ? true
                         : in_array(strtolower($date->englishDayOfWeek), array_map('strtolower', $days), true),
            'monthly' => true,   // every occurrence on the same day-of-month as template
            default   => false,
        };
    }

    private function createOccurrence(Booking $template, Carbon $date, int $sequence): Booking
    {
        return DB::transaction(function () use ($template, $date, $sequence) {
            // Calculate the duration shift for this occurrence
            $shift = 0;
            if ($template->from_date) {
                $templateStart = Carbon::parse($template->from_date);
                $shift         = $templateStart->diffInDays($date, false);
            }

            $fromDate = $template->from_date ? Carbon::parse($template->from_date)->addDays($shift) : null;
            $toDate   = $template->to_date   ? Carbon::parse($template->to_date)->addDays($shift)   : null;

            $nextSequence = Booking::where('recurring_series_id', $template->id)->max('recurring_sequence') ?? 0;

            $occurrence = $template->replicate([
                'id',
                'booking_number',
                'recurring_series_id',
                'recurring_sequence',
                'recurring_occurrence_date',
                'is_recurring',
                'recurrence_pattern',
                'recurrence_end_date',
                'recurrence_days',
                'created_at',
                'updated_at',
                'deleted_at',
                'invoice_number',
                'completed_at',
                'workflow_data',
            ]);

            $occurrence->id                        = (string) Str::uuid();
            $occurrence->booking_number            = $this->generateBookingNumber($template->booking_number, $nextSequence + 1);
            $occurrence->from_date                 = $fromDate;
            $occurrence->to_date                   = $toDate;
            $occurrence->recurring_series_id       = $template->id;
            $occurrence->recurring_sequence        = $nextSequence + 1;
            $occurrence->recurring_occurrence_date = $date->toDateString();
            $occurrence->is_recurring              = false;
            $occurrence->status                    = 'confirmed';
            $occurrence->created_at                = now();
            $occurrence->updated_at                = now();
            $occurrence->save();

            // Clone booking items with shifted dates
            foreach ($template->bookingItems as $item) {
                $newItem = $item->replicate(['id', 'booking_id', 'item_code', 'from_date', 'to_date', 'created_at', 'updated_at']);
                $newItem->id         = (string) Str::uuid();
                $newItem->booking_id = $occurrence->id;
                $newItem->from_date  = $item->from_date ? Carbon::parse($item->from_date)->addDays($shift) : null;
                $newItem->to_date    = $item->to_date   ? Carbon::parse($item->to_date)->addDays($shift)   : null;
                $newItem->save();
            }

            Log::info('Recurring booking occurrence created', [
                'template_id'  => $template->id,
                'occurrence_id' => $occurrence->id,
                'date'         => $date->toDateString(),
                'sequence'     => $nextSequence + 1,
            ]);

            return $occurrence;
        });
    }

    private function generateBookingNumber(string $templateNumber, int $sequence): string
    {
        // Strip any existing occurrence suffix and append new one
        $base = preg_replace('/-OCC\d+$/', '', $templateNumber);
        return sprintf('%s-OCC%04d', $base, $sequence);
    }
}
