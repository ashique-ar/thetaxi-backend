<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BookingFormTabsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tabs = [
            [
                'code' => 'airport_transfers',
                'label' => 'Airport Transfer',
                'service_type_code' => 'airport_transfers',
                'sort_order' => 1,
                'enabled' => true,
                'icon_type' => 'svg',
                'icon_data' => '<path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z" />',
                'metadata' => [
                    'form_type' => 'airport_transfer',
                    'requires_transfer_type' => true,
                ],
            ],
            [
                'code' => 'ride_now',
                'label' => 'Drop & Pickup',
                'service_type_code' => 'ride_now',
                'sort_order' => 2,
                'enabled' => true,
                'icon_type' => 'svg',
                'icon_data' => '<path d="M17 5h-2v2h2v2h2V7h2V5h-2V3h-2v2zm-2 4V7H9.01L3 13.01V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6h-6zM5 19v-4.99l4-4 4 4L9 18H5zm14 0h-6v-4l-2-2-4 4v2h12z" />',
                'metadata' => [
                    'form_type' => 'ride_now',
                ],
            ],
            [
                'code' => 'day_rental',
                'label' => 'Day Package',
                'service_type_code' => 'day_rental',
                'sort_order' => 3,
                'enabled' => true,
                'icon_type' => 'svg',
                'icon_data' => '<path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />',
                'metadata' => [
                    'form_type' => 'day_rental',
                ],
            ],
            [
                'code' => 'corporate',
                'label' => 'Corporate',
                'service_type_code' => 'corporate',
                'sort_order' => 4,
                'enabled' => true,
                'icon_type' => 'svg',
                'icon_data' => '<path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z" />',
                'metadata' => [
                    'form_type' => 'corporate',
                    'is_inquiry' => true,
                ],
            ],
            [
                'code' => 'wedding_hire',
                'label' => 'Wedding',
                'service_type_code' => 'wedding_hire',
                'sort_order' => 5,
                'enabled' => false,
                'icon_type' => 'svg',
                'icon_data' => '<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>',
                'metadata' => [
                    'form_type' => 'wedding',
                    'is_inquiry' => true,
                ],
            ],
            [
                'code' => 'self_drive',
                'label' => 'Self Drive',
                'service_type_code' => 'day_rental',
                'sort_order' => 6,
                'enabled' => false,
                'icon_type' => 'svg',
                'icon_data' => '<path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>',
                'metadata' => [
                    'form_type' => 'self_drive',
                    'rental_mode' => 'self_drive',
                ],
            ],
            [
                'code' => 'with_driver',
                'label' => 'With Driver',
                'service_type_code' => 'day_rental',
                'sort_order' => 7,
                'enabled' => false,
                'icon_type' => 'svg',
                'icon_data' => '<path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.85 7h10.29l1.08 3.11H5.77L6.85 7zM17.5 16c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm-11 0c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16z"/><circle cx="9" cy="8.5" r="1.5"/>',
                'metadata' => [
                    'form_type' => 'with_driver',
                    'rental_mode' => 'with_driver',
                ],
            ],
        ];

        foreach ($tabs as $tab) {
            \App\Models\BookingFormTab::updateOrCreate(
                ['code' => $tab['code']],
                $tab
            );
        }
    }
}
