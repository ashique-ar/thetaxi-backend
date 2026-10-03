<?php

namespace App\Services\Sales;

use App\Models\Booking\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesCollectionCompanyIntegrity
{
    public const BOOKING_TABLES = [
        'booking_payment_schedules' => ['sequence', 'due_date', 'schedule_kind', 'status'],
        'booking_payment_schedule_revisions' => ['revision_number', 'effective_at'],
        'booking_payment_schedule_rules' => ['version', 'status', 'anchor_date'],
        'booking_collection_work_items' => ['work_type', 'status', 'due_at'],
        'booking_collection_submissions' => ['status', 'received_at'],
        'booking_payment_receipt_finality_events' => ['from_status', 'to_status', 'occurred_at'],
        'booking_collection_reminder_deliveries' => ['reminder_type', 'status', 'schedule_due_date', 'dispatched_at'],
        'booking_payment_receipts' => ['payment_method', 'finality_status', 'received_at'],
        'booking_payment_adjustments' => ['impact_dimension', 'adjustment_type', 'adjustment_effective_at'],
        'booking_commercial_value_adjustments' => ['adjustment_type', 'effective_at'],
    ];
    public const NON_REPAIRABLE_TABLES = [
        'booking_payment_receipt_finality_events',
        'booking_collection_reminder_deliveries',
    ];
    public const PROFILE_REFERENCES = [
        'booking_payment_schedules' => 'collection_sales_profile_id',
        'booking_collection_work_items' => 'assigned_sales_profile_id',
        'booking_collection_submissions' => 'submitted_by_sales_profile_id',
        'booking_commercial_value_adjustments' => 'acquisition_sales_profile_id',
    ];

    public function assertConsistent(Booking $booking, ?string $companyId): void
    {
        $this->assertMany([(string) $booking->id => $companyId]);
    }

    public function assertNoImmutableEvidenceMismatch(Booking $booking, string $companyId): void
    {
        foreach (self::NON_REPAIRABLE_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'booking_id') || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            abort_unless(! DB::table($table)->where('booking_id', $booking->id)
                ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', '!=', $companyId))->exists(), 409,
                'Immutable collection history has unresolved legal-entity ownership and requires approved historical disposition.');
        }
    }

    public function assertMany(array $companyByBookingId): void
    {
        if ($companyByBookingId === []) {
            return;
        }

        foreach ($companyByBookingId as $companyId) {
            abort_unless($companyId, 409,
                'This booking has no resolved legal entity for collection history. Reconcile its attribution before continuing.');
        }

        $bookingIdsByCompany = [];
        foreach ($companyByBookingId as $bookingId => $companyId) {
            $bookingIdsByCompany[(string) $companyId][] = $bookingId;
        }

        foreach (array_keys(self::BOOKING_TABLES) as $table) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'booking_id')
                || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $mismatch = DB::table($table)->where(function ($query) use ($bookingIdsByCompany): void {
                foreach ($bookingIdsByCompany as $companyId => $bookingIds) {
                    $query->orWhere(fn ($scope) => $scope->whereIn('booking_id', $bookingIds)
                        ->where(fn ($ownership) => $ownership->whereNull('company_id')->orWhere('company_id', '!=', $companyId)));
                }
            })->exists();

            abort_unless(! $mismatch, 409,
                'This booking has unresolved company ownership in its collection history. Reconcile the recorded entity before continuing.');
        }

        foreach (self::PROFILE_REFERENCES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'booking_id') || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $mismatch = DB::table("{$table} as source")
                ->leftJoin('sales_profiles as profile', "profile.id", '=', "source.{$column}")
                ->whereNotNull("source.{$column}")
                ->where(function ($query) use ($bookingIdsByCompany): void {
                    foreach ($bookingIdsByCompany as $companyId => $bookingIds) {
                        $query->orWhere(fn ($scope) => $scope->whereIn('source.booking_id', $bookingIds)
                            ->where(fn ($profileScope) => $profileScope->whereNull('profile.id')
                                ->orWhereNull('profile.company_id')->orWhere('profile.company_id', '!=', $companyId)));
                    }
                })->exists();

            abort_unless(! $mismatch, 409,
                'This booking has a collection owner outside its legal entity. Reconcile the owner before continuing.');
        }
    }

    public function mismatchReport(?string $bookingNumber = null, ?string $companyId = null): array
    {
        $items = [];
        $heldBookingNumbers = [];

        foreach (self::BOOKING_TABLES as $table => $referenceColumns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'booking_id') || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $availableReferences = array_values(array_filter(
                $referenceColumns,
                fn (string $column) => Schema::hasColumn($table, $column),
            ));
            $query = DB::table("{$table} as source")
                ->join('bookings as booking', 'booking.id', '=', 'source.booking_id')
                ->leftJoin('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
                ->leftJoin('companies as expected_company', 'expected_company.id', '=', 'attribution.company_id')
                ->leftJoin('companies as recorded_company', 'recorded_company.id', '=', 'source.company_id')
                ->where(fn ($scope) => $scope->whereNull('attribution.company_id')
                    ->orWhereNull('source.company_id')
                    ->orWhereColumn('source.company_id', '!=', 'attribution.company_id'))
                ->when($companyId, fn ($scope, $id) => $scope->where('attribution.company_id', $id))
                ->when($bookingNumber, fn ($scope, $number) => $scope->where('booking.booking_number', $number))
                ->orderBy('booking.booking_number')
                ->select([
                    'booking.booking_number', 'attribution.company_id as expected_company_id',
                    'expected_company.name as expected_company', 'source.company_id as recorded_company_id',
                    'recorded_company.name as recorded_company',
                ]);
            foreach ($availableReferences as $column) {
                $query->addSelect("source.{$column} as reference_{$column}");
            }

            foreach ($query->get() as $record) {
                $immutableEvidence = in_array($table, self::NON_REPAIRABLE_TABLES, true);
                if ($immutableEvidence) $heldBookingNumbers[] = $record->booking_number;
                $items[] = [
                    'booking_number' => $record->booking_number,
                    'record_type' => $table,
                    'reference' => array_filter(array_combine(
                        $availableReferences,
                        array_map(fn (string $column) => $record->{"reference_{$column}"}, $availableReferences),
                    ), fn ($value) => $value !== null && $value !== ''),
                    'issue' => $immutableEvidence ? 'historical_company_evidence_mismatch' : (! $record->expected_company_id
                        ? 'attribution_company_missing'
                        : (! $record->recorded_company_id ? 'record_company_missing' : 'record_company_mismatch')),
                    'repairable' => ! $immutableEvidence,
                    'expected_company' => $record->expected_company ?? 'Unresolved',
                    'recorded_company' => $companyId && $record->recorded_company_id
                        && $record->recorded_company_id !== $record->expected_company_id
                        ? 'Another legal entity'
                        : ($record->recorded_company ?? ($record->recorded_company_id ? 'Unavailable company record' : 'Unassigned')),
                ];
            }
        }

        foreach (self::PROFILE_REFERENCES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'booking_id') || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $availableReferences = array_values(array_filter(
                self::BOOKING_TABLES[$table] ?? [],
                fn (string $reference) => Schema::hasColumn($table, $reference),
            ));
            $query = DB::table("{$table} as source")
                ->join('bookings as booking', 'booking.id', '=', 'source.booking_id')
                ->leftJoin('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
                ->leftJoin('companies as expected_company', 'expected_company.id', '=', 'attribution.company_id')
                ->leftJoin('sales_profiles as profile', 'profile.id', '=', "source.{$column}")
                ->leftJoin('companies as owner_company', 'owner_company.id', '=', 'profile.company_id')
                ->whereNotNull("source.{$column}")
                ->where(fn ($scope) => $scope->whereNull('attribution.company_id')
                    ->orWhereNull('profile.company_id')
                    ->orWhereColumn('profile.company_id', '!=', 'attribution.company_id'))
                ->when($companyId, fn ($scope, $id) => $scope->where('attribution.company_id', $id))
                ->when($bookingNumber, fn ($scope, $number) => $scope->where('booking.booking_number', $number))
                ->orderBy('booking.booking_number')
                ->select([
                    'booking.booking_number', 'attribution.company_id as expected_company_id',
                    'expected_company.name as expected_company', 'profile.company_id as owner_company_id',
                    'owner_company.name as owner_company',
                ]);
            foreach ($availableReferences as $reference) {
                $query->addSelect("source.{$reference} as reference_{$reference}");
            }

            foreach ($query->get() as $record) {
                $items[] = [
                    'booking_number' => $record->booking_number,
                    'record_type' => $table,
                    'reference' => array_filter(array_combine(
                        $availableReferences,
                        array_map(fn (string $reference) => $record->{"reference_{$reference}"}, $availableReferences),
                    ), fn ($value) => $value !== null && $value !== ''),
                    'issue' => ! $record->expected_company_id
                        ? 'attribution_company_missing'
                        : 'profile_company_mismatch',
                    'reference_field' => $column,
                    'recorded_profile' => ! $record->expected_company_id
                        ? 'Sales Profile needs legal entity review'
                        : ($record->owner_company_id ? 'Sales Profile outside booking entity' : 'Sales Profile owner unavailable'),
                    'expected_company' => $record->expected_company ?? 'Unresolved',
                    'recorded_company' => $companyId && $record->owner_company_id
                        ? 'Another legal entity'
                        : ($record->owner_company ?? 'Unavailable Sales Profile company'),
                ];
            }
        }

        $heldBookingNumbers = array_fill_keys($heldBookingNumbers, true);
        foreach ($items as &$item) {
            if (isset($heldBookingNumbers[$item['booking_number']])) {
                $item['repairable'] = false;
                $item['hold_reason'] = 'Immutable collection history requires approved historical disposition.';
            }
        }
        unset($item);

        usort($items, fn (array $left, array $right) =>
            [$left['booking_number'], $left['record_type'], $left['issue']]
            <=> [$right['booking_number'], $right['record_type'], $right['issue']]);

        return $items;
    }

    public function mismatchSummary(array $items): array
    {
        $summary = ['total' => count($items), 'by_issue' => [], 'by_record_type' => []];
        foreach ($items as $item) {
            $summary['by_issue'][$item['issue']] = ($summary['by_issue'][$item['issue']] ?? 0) + 1;
            $summary['by_record_type'][$item['record_type']] = ($summary['by_record_type'][$item['record_type']] ?? 0) + 1;
        }
        ksort($summary['by_issue']);
        ksort($summary['by_record_type']);

        return $summary;
    }
}
