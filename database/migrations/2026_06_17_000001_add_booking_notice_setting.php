<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('website_settings')
            ->where('type', 'booking_notice_html')
            ->whereNull('company_id')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('website_settings')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'booking_notice_html',
            'company_id' => null,
            'value' => 'Booking Notice: Bookings must be made at least <strong>4 hours</strong> in advance.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('website_settings')
            ->where('type', 'booking_notice_html')
            ->whereNull('company_id')
            ->delete();
    }
};
