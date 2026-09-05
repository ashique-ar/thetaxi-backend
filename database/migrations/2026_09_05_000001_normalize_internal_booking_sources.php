<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')
            ->whereNotNull('workflow_data->cart_items')
            ->update([
                'booking_source' => 'public',
                'created_from' => 'web',
            ]);

        DB::table('bookings')
            ->whereNull('workflow_data->cart_items')
            ->where(function ($query) {
                $query->whereIn('booking_source', ['internal', 'dashboard'])
                    ->orWhereNull('booking_source');
            })
            ->update([
                'booking_source' => 'internal',
                'created_from' => 'internal',
            ]);

        DB::table('bookings')
            ->where(function ($query) {
                $query->where('is_corporate_booking', true)
                    ->orWhereNotNull('corporate_account_id');
            })
            ->update(['booking_source' => 'corporate']);
    }

    public function down(): void
    {
        // Source normalization cannot be reversed without reintroducing invalid data.
    }
};
