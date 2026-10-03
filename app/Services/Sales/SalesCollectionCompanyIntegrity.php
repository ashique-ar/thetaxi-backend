<?php

namespace App\Services\Sales;

use App\Models\Booking\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesCollectionCompanyIntegrity
{
    private const BOOKING_TABLES = [
        'booking_payment_schedules',
        'booking_payment_schedule_revisions',
        'booking_payment_schedule_rules',
        'booking_collection_work_items',
        'booking_collection_submissions',
        'booking_payment_receipts',
        'booking_payment_adjustments',
        'booking_commercial_value_adjustments',
    ];
    private const PROFILE_REFERENCES = [
        'booking_payment_schedules' => 'collection_sales_profile_id',
        'booking_collection_work_items' => 'assigned_sales_profile_id',
        'booking_collection_submissions' => 'submitted_by_sales_profile_id',
        'booking_commercial_value_adjustments' => 'acquisition_sales_profile_id',
    ];

    public function assertConsistent(Booking $booking, ?string $companyId): void
    {
        $this->assertMany([(string) $booking->id => $companyId]);
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

        foreach (self::BOOKING_TABLES as $table) {
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
}
