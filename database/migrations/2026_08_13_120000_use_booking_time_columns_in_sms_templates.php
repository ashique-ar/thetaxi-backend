<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TEMPLATES = [
        'sms_booking_confirmation_template' => [
            'old' => "Your booking with TheTaxi is confirmed.\n\nBooking #: {booking_number}\nPickup: {pickup_datetime}\n\nFor assistance: 011 286 1111",
            'new' => "Your booking with TheTaxi is confirmed.\n\nBooking #: {booking_number}\nPickup date: {pickup_date}\nPickup time: {pickup_time}\n\nFor assistance: 011 286 1111",
        ],
        'sms_driver_assignment_fallback_template' => [
            'old' => "New booking assigned.\n\nBooking #: {booking_number}\nPickup: {pickup_datetime}\nCustomer: {customer_name}\n\nPlease check TheTaxi Driver App.",
            'new' => "New booking assigned.\n\nBooking #: {booking_number}\nPickup date: {pickup_date}\nPickup time: {pickup_time}\nCustomer: {customer_name}\n\nPlease check TheTaxi Driver App.",
        ],
        'sms_driver_dispatched_template' => [
            'old' => "Your Taxi is on the way.\n\nBooking #: {booking_number}\nDriver: {driver_name}\nMobile: {driver_mobile}\nVehicle: {vehicle_description}\nVehicle No: {vehicle_number}\n\nTheTaxi: 011 286 1111",
            'new' => "Your Taxi is on the way.\n\nBooking #: {booking_number}\nPickup: {pickup_date} {pickup_time}\nDriver: {driver_name}\nMobile: {driver_mobile}\nVehicle: {vehicle_description}\nVehicle No: {vehicle_number}\n\nTheTaxi: 011 286 1111",
        ],
        'sms_driver_arrived_template' => [
            'old' => "Your Taxi has arrived at the pickup location.\n\nBooking #: {booking_number}\nDriver: {driver_name}\nVehicle: {vehicle_number}\nDriver Mobile: {driver_mobile}",
            'new' => "Your Taxi has arrived at the pickup location.\n\nBooking #: {booking_number}\nScheduled pickup: {pickup_date} {pickup_time}\nDriver: {driver_name}\nVehicle: {vehicle_number}\nDriver Mobile: {driver_mobile}",
        ],
        'sms_admin_booking_summary_template' => [
            'old' => "NEW BOOKING CONFIRMED\n\nBooking: #{booking_number}\nCustomer: {customer_name}\nMobile: {customer_mobile}\nPickup: {pickup_datetime}\nFrom: {origin}\nTo: {destination}\nItems/Trips: {item_count}\nTotal: {currency} {total}",
            'new' => "NEW BOOKING CONFIRMED\n\nBooking: #{booking_number}\nCustomer: {customer_name}\nMobile: {customer_mobile}\nPickup date: {pickup_date}\nPickup time: {pickup_time}\nFrom: {origin}\nTo: {destination}\nItems/Trips: {item_count}\nTotal: {currency} {total}",
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        $hasCompanyId = Schema::hasColumn('website_settings', 'company_id');

        foreach (self::TEMPLATES as $type => $templates) {
            // Upgrade only the shipped legacy text, at every scope. Business-owned
            // custom templates remain untouched, while global and company copies
            // created by the settings screen stay consistent.
            DB::table('website_settings')
                ->where('type', $type)
                ->where('value', $templates['old'])
                ->update(['value' => $templates['new'], 'updated_at' => now()]);

            $global = DB::table('website_settings')->where('type', $type);
            if ($hasCompanyId) {
                $global->whereNull('company_id');
            }

            if (!$global->exists()) {
                $setting = [
                    'id' => (string) Str::uuid(),
                    'type' => $type,
                    'value' => $templates['new'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if ($hasCompanyId) {
                    $setting['company_id'] = null;
                }
                DB::table('website_settings')->insert($setting);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        foreach (self::TEMPLATES as $type => $templates) {
            DB::table('website_settings')
                ->where('type', $type)
                ->where('value', $templates['new'])
                ->update(['value' => $templates['old'], 'updated_at' => now()]);
        }
    }
};
