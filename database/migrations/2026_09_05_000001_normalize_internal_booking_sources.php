<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')
            ->whereIn('booking_source', ['internal', 'dashboard'])
            ->update([
                'booking_source' => 'internal',
                'created_from' => 'internal',
            ]);
    }

    public function down(): void
    {
        // Source normalization cannot be reversed without reintroducing invalid data.
    }
};
