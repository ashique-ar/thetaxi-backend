<?php

namespace App\Services\Sales;

use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SalesFrozenCollectionAgingService
{
    public const BUCKETS = ['not_due', 'due_today', '1_30', '31_60', '61_90', '91_plus'];

    public function __construct(private readonly SalesPolicySettingsService $policySettings) {}

    public function source(
        string $companyId,
        array $authorizedProfileIds,
        string $asOfDate,
        string $cutoffAt,
        bool $requireCoveredOwner = false,
    ): array {
        $timezone = $this->policySettings->businessTimezone($companyId);
        abort_unless(is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true), 409,
            'An approved Sales business timezone is required for frozen collection aging.');
        $asOf = CarbonImmutable::parse($asOfDate, $timezone)->startOfDay();
        $cutoff = CarbonImmutable::parse($cutoffAt)->utc();
        $asOfBoundary = $asOf->endOfDay()->utc()->min($cutoff);

        $profileIds = collect($authorizedProfileIds)->filter()->unique()->sort()->values();
        $schedules = DB::table('booking_payment_schedules as schedule')
            ->join('bookings as booking', 'booking.id', '=', 'schedule.booking_id')
            ->leftJoin('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'schedule.booking_id')
            ->where(fn ($query) => $query->where('schedule.company_id', $companyId)
                ->orWhere(fn ($legacy) => $legacy->whereNull('schedule.company_id')->where('attribution.company_id', $companyId)))
            ->where('schedule.created_at', '<=', $cutoff)
            ->orderBy('schedule.due_date')->orderBy('schedule.id')
            ->get([
                'schedule.id', 'schedule.booking_id', 'booking.booking_number', 'schedule.due_date',
                'schedule.amount', 'schedule.source_amount', 'schedule.source_currency', 'schedule.lkr_amount',
                'schedule.is_collection_target_eligible', 'schedule.revision_number', 'schedule.created_at',
                'schedule.deleted_at', 'schedule.superseded_by_revision_id', 'attribution.id as attribution_id',
            ]);
        $scheduleIds = $schedules->pluck('id')->all();
        $bookingIds = $schedules->pluck('booking_id')->unique()->all();
        $attributionIds = $schedules->pluck('attribution_id')->filter()->unique()->all();

        $revisions = DB::table('booking_payment_schedule_revisions')
            ->whereIn('booking_id', $bookingIds)->where('created_at', '<=', $cutoff)
            ->get(['id', 'booking_id', 'revision_number', 'effective_at', 'created_at']);
        $revisionById = $revisions->keyBy('id');
        $revisionByBookingVersion = $revisions->keyBy(fn ($row) => "{$row->booking_id}:{$row->revision_number}");
        $allocations = DB::table('booking_payment_schedule_allocations')
            ->whereIn('booking_payment_schedule_id', $scheduleIds)
            ->where('created_at', '<=', $cutoff)->where('allocated_at', '<=', $asOfBoundary)
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('deleted_at', '>', $cutoff))
            ->selectRaw('booking_payment_schedule_id, SUM(amount) allocated_source_amount')
            ->groupBy('booking_payment_schedule_id')->pluck('allocated_source_amount', 'booking_payment_schedule_id');
        $events = DB::table('sales_booking_attribution_events')
            ->whereIn('attribution_id', $attributionIds)->where('created_at', '<=', $cutoff)
            ->where('effective_at', '<=', $asOfBoundary)
            ->where(fn ($query) => $query->where('event_type', 'confirmed')
                ->orWhere('field_name', 'collection_sales_profile_id'))
            ->orderBy('effective_at')->orderBy('version')->orderBy('id')->get()
            ->groupBy('attribution_id')->map->last();

        $missingLineage = 0;
        $rows = collect();
        foreach ($schedules as $schedule) {
            $revision = (int) $schedule->revision_number > 1
                ? $revisionByBookingVersion->get("{$schedule->booking_id}:{$schedule->revision_number}") : null;
            if ((int) $schedule->revision_number > 1 && ! $revision) {
                $missingLineage++;
                continue;
            }
            if ($revision && CarbonImmutable::parse($revision->effective_at)->gt($asOfBoundary)) continue;

            $superseding = $schedule->superseded_by_revision_id
                ? $revisionById->get($schedule->superseded_by_revision_id) : null;
            if ($schedule->superseded_by_revision_id && ! $superseding) {
                $missingLineage++;
                continue;
            }
            if ($superseding && CarbonImmutable::parse($superseding->effective_at)->lte($asOfBoundary)) continue;
            if ($schedule->deleted_at && CarbonImmutable::parse($schedule->deleted_at)->lte($cutoff) && ! $superseding) continue;

            $sourceAmount = (float) ($schedule->source_amount ?? $schedule->amount);
            $allocated = (float) ($allocations->get($schedule->id) ?? 0);
            $outstanding = round($sourceAmount - $allocated, 4);
            if ($outstanding <= 0) continue;
            $event = $schedule->attribution_id ? $events->get($schedule->attribution_id) : null;
            $ownerId = $event?->to_sales_profile_id;
            if (! $event || ! $ownerId) {
                $missingLineage++;
                continue;
            }
            if (! $profileIds->contains($ownerId)) {
                if ($requireCoveredOwner) $missingLineage++;
                continue;
            }
            $currency = strtoupper((string) ($schedule->source_currency ?: 'LKR'));
            $outstandingLkr = $currency === 'LKR' ? $outstanding
                : ($schedule->lkr_amount !== null && $sourceAmount > 0
                    ? round((float) $schedule->lkr_amount * $outstanding / $sourceAmount, 4) : null);
            $due = CarbonImmutable::parse($schedule->due_date, $timezone)->startOfDay();
            $daysOverdue = $due->lt($asOf) ? $due->diffInDays($asOf) : 0;
            $bucket = match (true) {
                $due->gt($asOf) => 'not_due',
                $due->isSameDay($asOf) => 'due_today',
                $daysOverdue <= 30 => '1_30',
                $daysOverdue <= 60 => '31_60',
                $daysOverdue <= 90 => '61_90',
                default => '91_plus',
            };
            $evidence = [
                'schedule_id' => $schedule->id, 'booking_id' => $schedule->booking_id,
                'booking_number' => $schedule->booking_number, 'sales_profile_id' => $ownerId,
                'due_date' => $due->toDateString(), 'aging_bucket' => $bucket,
                'source_currency' => $currency, 'scheduled_source_amount' => round($sourceAmount, 4),
                'allocated_source_amount' => round($allocated, 4), 'outstanding_source_amount' => $outstanding,
                'outstanding_lkr' => $outstandingLkr, 'lkr_state' => $outstandingLkr === null ? 'missing' : 'complete',
                'is_collection_target_eligible' => (bool) $schedule->is_collection_target_eligible,
            ];
            $evidence['source_checksum'] = hash('sha256', CanonicalJson::encode($evidence));
            $rows->push($evidence);
        }

        return [
            'as_of' => $asOf->toDateString(), 'cutoff_at' => $cutoff->toIso8601String(),
            'missing_lineage_count' => $missingLineage,
            'rows' => $rows->sortBy([['due_date', 'asc'], ['schedule_id', 'asc']])->values(),
        ];
    }

    public function aggregate(Collection $rows): array
    {
        $missing = $rows->where('lkr_state', 'missing')->count();
        $buckets = collect(self::BUCKETS)->mapWithKeys(function (string $bucket) use ($rows) {
            $source = $rows->where('aging_bucket', $bucket)->values();
            $missing = $source->where('lkr_state', 'missing')->count();
            return [$bucket => [
                'schedule_count' => $source->count(), 'missing_lkr_count' => $missing,
                'lkr_state' => $missing === 0 ? 'complete' : 'incomplete',
                'outstanding_lkr' => $missing === 0 ? round((float) $source->sum('outstanding_lkr'), 4) : null,
            ]];
        })->all();

        return [
            'aging_snapshot_state' => 'complete', 'aging_schedule_count' => $rows->count(),
            'aging_lkr_state' => $missing === 0 ? 'complete' : 'incomplete',
            'aging_missing_lkr_count' => $missing,
            'aging_outstanding_lkr' => $missing === 0 ? round((float) $rows->sum('outstanding_lkr'), 4) : null,
            'aging_buckets' => $buckets,
            'aging_source_checksum' => hash('sha256', implode('|', $rows->pluck('source_checksum')->sort()->values()->all())),
        ];
    }
}
