<?php

namespace App\Services\Hr\Integration;

use App\Support\Foundation\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesKpiReviewEvidenceService
{
    private const METRICS = [
        'new_sales_target_lkr', 'collection_target_lkr', 'new_sales_lkr',
        'new_booking_collections_lkr', 'existing_booking_collections_lkr',
        'commission_new_business_lkr', 'commission_existing_business_lkr',
        'new_bookings_count', 'new_customers_count', 'new_sales_achievement_percent',
        'collection_achievement_percent',
    ];

    public function optionsForReview(object $review): array
    {
        $cycle = DB::table('hr_review_cycles')->where('id', $review->cycle_id)->firstOrFail();
        $rows = DB::table('sales_kpi_snapshot_rows as sr')
            ->join('sales_kpi_snapshots as s', 's.id', '=', 'sr.snapshot_id')
            ->join('domain_period_locks as pl', 'pl.id', '=', 's.period_lock_id')
            ->where('sr.staff_id', $review->staff_id)
            ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 's.company_id', $review->company_id))
            ->where('s.status', 'frozen')
            ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'pl.company_id', $review->company_id))
            ->where('pl.domain', 'sales')
            ->where('pl.period_type', 'month')
            ->where('pl.state', 'locked')
            ->whereDate('s.period_start', $cycle->period_start)
            ->whereDate('s.period_end', $cycle->period_end)
            ->orderByDesc('s.version')
            ->select(['sr.id as sales_kpi_snapshot_row_id', 's.id as sales_kpi_snapshot_id', 's.version as snapshot_version', 'sr.metric_snapshot', 'sr.row_checksum'])
            ->get();

        return $rows->map(function ($row) {
            $metrics = $this->verifiedMetrics($row->metric_snapshot, $row->row_checksum);
            unset($row->metric_snapshot);
            foreach (self::METRICS as $metric) $row->{$metric} = $metrics[$metric] ?? null;
            return $row;
        })->all();
    }

    public function forReview(string $id): ?object
    {
        $link = DB::table('hr_review_sales_kpi_links as l')
            ->join('hr_performance_reviews as r', 'r.id', '=', 'l.review_id')
            ->join('hr_review_cycles as c', 'c.id', '=', 'r.cycle_id')
            ->join('sales_kpi_snapshot_rows as sr', 'sr.id', '=', 'l.sales_kpi_snapshot_row_id')
            ->join('sales_kpi_snapshots as s', 's.id', '=', 'l.sales_kpi_snapshot_id')
            ->where('l.review_id', $id)
            ->whereColumn('sr.snapshot_id', 'l.sales_kpi_snapshot_id')
            ->whereColumn('r.staff_id', 'sr.staff_id')
            ->where(fn ($sameCompany) => \App\Services\CompanyIdScope::sameCompany($sameCompany, 'r.company_id', 's.company_id'))
            ->whereColumn('s.period_start', 'c.period_start')
            ->whereColumn('s.period_end', 'c.period_end')
            ->whereIn('s.status', ['frozen', 'superseded'])
            ->select([
                'l.sales_kpi_snapshot_id', 'l.sales_kpi_snapshot_row_id', 'l.snapshot_checksum', 'l.linked_at',
                's.version as snapshot_version', 's.snapshot_checksum as source_snapshot_checksum',
                'sr.metric_snapshot', 'sr.row_checksum',
            ])
            ->first();

        if (! $link) {
            abort_if(DB::table('hr_review_sales_kpi_links')->where('review_id', $id)->exists(), 409,
                'Linked Sales KPI evidence no longer matches its review or frozen snapshot.');

            return null;
        }

        $metrics = $this->verifiedMetrics($link->metric_snapshot, $link->row_checksum);

        $expectedChecksum = hash('sha256', $link->source_snapshot_checksum.'|'.$link->row_checksum);
        abort_unless(hash_equals($expectedChecksum, $link->snapshot_checksum), 409,
            'Linked Sales KPI evidence checksum does not match its frozen source.');

        $result = [
            'sales_kpi_snapshot_id' => $link->sales_kpi_snapshot_id,
            'sales_kpi_snapshot_row_id' => $link->sales_kpi_snapshot_row_id,
            'snapshot_version' => $link->snapshot_version,
            'snapshot_checksum' => $link->snapshot_checksum,
            'linked_at' => $link->linked_at,
            'row_checksum' => $link->row_checksum,
        ];
        foreach ([
            'new_sales_target_lkr', 'collection_target_lkr', 'new_sales_lkr',
            'new_booking_collections_lkr', 'existing_booking_collections_lkr',
            'commission_new_business_lkr', 'commission_existing_business_lkr',
            'new_bookings_count', 'new_customers_count', 'new_sales_achievement_percent',
            'collection_achievement_percent',
        ] as $metric) {
            $result[$metric] = $metrics[$metric] ?? null;
        }

        return (object) $result;
    }

    public function link(string $reviewId, string $rowId, string $actor): object
    {
        return DB::transaction(function () use ($reviewId, $rowId, $actor) {
            $review = DB::table('hr_performance_reviews as r')
                ->join('hr_review_cycles as c', 'c.id', '=', 'r.cycle_id')
                ->where('r.id', $reviewId)
                ->select('r.*', 'c.period_start', 'c.period_end')
                ->lockForUpdate()
                ->first();
            abort_unless($review, 404);
            abort_if(in_array($review->status, ['finalized', 'acknowledged'], true), 409,
                'A finalized review cannot be relinked.');

            $existingLink = DB::table('hr_review_sales_kpi_links')->where('review_id', $reviewId)->first();
            if ($existingLink) {
                abort_unless((string) $existingLink->sales_kpi_snapshot_row_id === $rowId, 409,
                    'Different Sales KPI evidence is already linked to this review.');
                abort_unless($this->forReview($reviewId), 409,
                    'The previously linked Sales KPI evidence no longer passes its integrity checks.');

                return $existingLink;
            }

            $row = DB::table('sales_kpi_snapshot_rows as sr')
                ->join('sales_kpi_snapshots as s', 's.id', '=', 'sr.snapshot_id')
                ->join('domain_period_locks as pl', 'pl.id', '=', 's.period_lock_id')
                ->where('sr.id', $rowId)
                ->where('sr.staff_id', $review->staff_id)
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 's.company_id', $review->company_id))
                ->where(fn ($sameCompany) => \App\Services\CompanyIdScope::sameCompany($sameCompany, 'pl.company_id', 's.company_id'))
                ->where('pl.domain', 'sales')
                ->where('pl.period_type', 'month')
                ->where('pl.state', 'locked')
                ->where('s.status', 'frozen')
                ->whereDate('s.period_start', $review->period_start)
                ->whereDate('s.period_end', $review->period_end)
                ->lockForUpdate()
                ->select('sr.id as row_id', 'sr.snapshot_id', 'sr.metric_snapshot', 'sr.row_checksum', 's.snapshot_checksum')
                ->first();
            abort_unless($row, 422, 'A frozen Sales-owned KPI row for the employee and exact review period is required.');
            $this->verifiedMetrics($row->metric_snapshot, $row->row_checksum);

            $id = (string) Str::uuid();
            DB::table('hr_review_sales_kpi_links')->insert([
                'id' => $id,
                'review_id' => $reviewId,
                'sales_kpi_snapshot_id' => $row->snapshot_id,
                'sales_kpi_snapshot_row_id' => $row->row_id,
                'snapshot_checksum' => hash('sha256', $row->snapshot_checksum.'|'.$row->row_checksum),
                'linked_at' => now(),
                'linked_by' => $actor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('hr_performance_review_events')->insert([
                'id' => (string) Str::uuid(),
                'review_id' => $reviewId,
                'event_type' => 'sales_kpi_linked',
                'from_status' => $review->status,
                'to_status' => $review->status,
                'evidence' => json_encode([
                    'sales_kpi_snapshot_id' => $row->snapshot_id,
                    'sales_kpi_snapshot_row_id' => $row->row_id,
                ], JSON_THROW_ON_ERROR),
                'actor_user_id' => $actor,
                'occurred_at' => now(),
            ]);

            return DB::table('hr_review_sales_kpi_links')->find($id);
        });
    }

    private function verifiedMetrics(string $snapshot, string $checksum): array
    {
        $metrics = json_decode($snapshot, true);
        abort_unless(json_last_error() === JSON_ERROR_NONE && is_array($metrics)
            && hash_equals(hash('sha256', CanonicalJson::encode($metrics)), $checksum), 409,
            'Sales KPI row checksum does not match its frozen source.');
        return $metrics;
    }
}
