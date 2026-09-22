<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        $keys = [
            'sms_booking_confirmation_template', 'sms_quotation_requested_template',
            'sms_inquiry_received_template', 'sms_driver_dispatched_template',
            'sms_trip_completion_template', 'sms_payment_confirmation_template',
            'sms_driver_assignment_fallback_template',
        ];

        foreach (DB::table('website_settings')->whereIn('type', $keys)->get(['id', 'value']) as $setting) {
            $updated = str_replace(
                ['www.thetaxi.lk', '011 286 1111', 'TheTaxi'],
                ['{company_website}', '{company_phone}', '{company_name}'],
                $setting->value ?? ''
            );
            if ($updated !== $setting->value) {
                DB::table('website_settings')->where('id', $setting->id)->update(['value' => $updated]);
            }
        }
    }

    public function down(): void {}
};
