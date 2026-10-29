<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_DEFAULTS = [
        "Thank you for travelling with {company_name}.\n\nBooking #: {booking_number}\nYour trip has been completed.\n\nWe hope you had a pleasant journey.\n\n{company_website}",
        "Thank you for travelling with TheTaxi.\n\nBooking #: {booking_number}\nYour trip has been completed.\n\nWe hope you had a pleasant journey.\n\nwww.thetaxi.lk",
    ];

    private const NEW_DEFAULT = "Thank you for travelling with {company_name}.\n\nBooking #: {booking_number}\nYour trip has been completed.\n\nWe hope you had a pleasant journey.\nRate your trip: {feedback_url}\n\n{company_website}";

    public function up(): void
    {
        if (! Schema::hasTable('website_settings')) {
            return;
        }

        DB::table('website_settings')->where('type', 'sms_trip_completion_template')
            ->whereIn('value', self::OLD_DEFAULTS)->update(['value' => self::NEW_DEFAULT, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('website_settings')) {
            return;
        }

        DB::table('website_settings')->where('type', 'sms_trip_completion_template')
            ->where('value', self::NEW_DEFAULT)->update(['value' => self::OLD_DEFAULTS[0], 'updated_at' => now()]);
    }
};
