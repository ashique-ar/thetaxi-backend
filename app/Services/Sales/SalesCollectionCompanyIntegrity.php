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

    public function assertConsistent(Booking $booking, ?string $companyId): void
    {
        abort_unless($companyId, 409,
            'This booking has no resolved legal entity for collection history. Reconcile its attribution before continuing.');

        foreach (self::BOOKING_TABLES as $table) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'booking_id')
                || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $mismatch = DB::table($table)->where('booking_id', $booking->id)
                ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', '!=', $companyId))
                ->exists();

            abort_unless(! $mismatch, 409,
                'This booking has unresolved company ownership in its collection history. Reconcile the recorded entity before continuing.');
        }
    }
}
