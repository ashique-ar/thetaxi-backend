<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Booking\BookingChannel;

class BookingChannelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $channels = [
            'Walk in',
            'Phone',
            'Mail',
            'Web',
            'AP Travel Counter',
            'AP Office',
            'Casons Travels',
            'Casons Taxi',
            'Taxi.lk',
            'Rental Cars',
            'Rideways',
            'Cartrawler',
            'Economy Bookings',
            'Airport Transfer',
            'Other',
        ];

        foreach ($channels as $name) {
            BookingChannel::firstOrCreate(
                ['name' => $name],
                ['description' => null]
            );
        }
    }
}
