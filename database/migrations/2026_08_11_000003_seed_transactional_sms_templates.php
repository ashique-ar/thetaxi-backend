<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const DEFAULTS = [
        'sms_booking_confirmation_enabled' => 'false',
        'sms_quotation_requested_enabled' => 'false',
        'sms_inquiry_received_enabled' => 'false',
        'sms_driver_dispatched_enabled' => 'false',
        'sms_driver_arrived_enabled' => 'false',
        'sms_trip_completion_enabled' => 'false',
        'sms_payment_confirmation_enabled' => 'false',
        'sms_trip_completion_scope' => 'booking',
        'sms_driver_assignment_fallback_enabled' => 'false',
        'sms_admin_booking_summary_enabled' => 'false',
        'sms_dry_run' => 'true',
        'sms_inquiry_received_template' => "Thank you for contacting TheTaxi.\n\nRef #: {inquiry_number}\n\nWe have received your inquiry and our team will contact you shortly.\n\nCall: 011 286 1111\nwww.thetaxi.lk",
        'sms_quotation_requested_template' => "Thank you for requesting a quotation from TheTaxi.\n\nRef #: {booking_number}\n\nWe have received your request and our team will contact you shortly.\n\nCall: 011 286 1111\nwww.thetaxi.lk",
        'sms_booking_confirmation_template' => "Your booking with TheTaxi is confirmed.\n\nBooking #: {booking_number}\nPickup: {pickup_datetime}\n\nFor assistance: 011 286 1111",
        'sms_driver_assignment_fallback_template' => "New booking assigned.\n\nBooking #: {booking_number}\nPickup: {pickup_datetime}\nCustomer: {customer_name}\n\nPlease check TheTaxi Driver App.",
        'sms_driver_dispatched_template' => "Your Taxi is on the way.\n\nBooking #: {booking_number}\nDriver: {driver_name}\nMobile: {driver_mobile}\nVehicle: {vehicle_description}\nVehicle No: {vehicle_number}\n\nTheTaxi: 011 286 1111",
        'sms_driver_arrived_template' => "Your Taxi has arrived at the pickup location.\n\nBooking #: {booking_number}\nDriver: {driver_name}\nVehicle: {vehicle_number}\nDriver Mobile: {driver_mobile}",
        'sms_trip_completion_template' => "Thank you for travelling with TheTaxi.\n\nBooking #: {booking_number}\nYour trip has been completed.\n\nWe hope you had a pleasant journey.\n\nwww.thetaxi.lk",
        'sms_payment_confirmation_template' => "Payment received for your TheTaxi booking.\n\nBooking #: {booking_number}\nAmount: {currency} {amount}\nReference: {payment_reference}\n\nThank you.",
        'sms_admin_booking_summary_template' => "NEW BOOKING CONFIRMED\n\nBooking: #{booking_number}\nCustomer: {customer_name}\nMobile: {customer_mobile}\nPickup: {pickup_datetime}\nFrom: {origin}\nTo: {destination}\nItems/Trips: {item_count}\nTotal: {currency} {total}",
    ];

    public function up(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        foreach (self::DEFAULTS as $type => $value) {
            $exists = DB::table('website_settings')
                ->where('type', $type)
                ->when(Schema::hasColumn('website_settings', 'company_id'), fn ($query) => $query->whereNull('company_id'))
                ->exists();

            if (!$exists) {
                DB::table('website_settings')->insert(array_filter([
                    'id' => (string) Str::uuid(),
                    'type' => $type,
                    'value' => $value,
                    'company_id' => Schema::hasColumn('website_settings', 'company_id') ? null : false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], static fn ($value): bool => $value !== false));
            }
        }
    }

    public function down(): void
    {
        foreach (self::DEFAULTS as $type => $value) {
            DB::table('website_settings')
                ->where('type', $type)
                ->where('value', $value)
                ->when(Schema::hasColumn('website_settings', 'company_id'), fn ($query) => $query->whereNull('company_id'))
                ->delete();
        }
    }
};
