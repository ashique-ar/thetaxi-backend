<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Website checkout has always persisted cart_items at the workflow root.
        DB::table('bookings')
            ->whereNotNull('workflow_data->cart_items')
            ->where(function ($query) {
                $query->whereNull('is_corporate_booking')
                    ->orWhere('is_corporate_booking', false);
            })
            ->update([
                'booking_source' => 'public',
                'created_from' => 'web',
            ]);
    }

    public function down(): void
    {
        // Historical source recovery is intentionally retained.
    }
};
