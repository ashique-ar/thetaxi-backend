<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Service\ServiceType;
use Illuminate\Database\Seeder;

class ThemeOneBookingFormSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('domain', 'thetaxi.lk')->first()
            ?? Company::updateOrCreate(
                ['domain' => 'thetaxi.lk'],
                ['name' => 'The Taxi', 'website' => 'https://thetaxi.lk', 'is_active' => true, 'is_default' => false]
            );

        $parentRideNow = ServiceType::publicContext()->where('code', 'ride_now')->first();

        ServiceType::updateOrCreate(
            [
                'code' => 'ride_now',
                'context' => 'public',
                'owner_type' => 'company',
                'owner_id' => (string) $company->id,
            ],
            [
                'name' => 'Drop & Pickup',
                'parent_service_type_id' => $parentRideNow?->id,
                'pricing_mode' => 'trip',
                'uses_dropoff_time' => false,
                'allow_return_trip' => true,
                'is_active' => true,
                'frontend_category' => 'ride',
                'form_config' => [
                    'pickup_location' => [
                        'type' => 'location',
                        'label' => 'Pickup Location',
                        'required' => true,
                        'order' => 1,
                        'row' => 1,
                        'width' => 'half',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup',
                        'location_mode' => 'autocomplete',
                        'placeholder' => 'Enter pickup location',
                        'default' => 'Colombo, Sri Lanka',
                        'default_lat' => '6.9271',
                        'default_lng' => '79.8612',
                    ],
                    'dropoff_location' => [
                        'type' => 'location',
                        'label' => 'Drop Off Location',
                        'required' => true,
                        'order' => 2,
                        'row' => 1,
                        'width' => 'half',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'dropoff',
                        'location_mode' => 'autocomplete',
                        'placeholder' => 'Enter drop off location',
                        'default' => 'Galle, Sri Lanka',
                        'default_lat' => '6.0535',
                        'default_lng' => '80.2210',
                    ],
                    'pickup_date' => [
                        'type' => 'date',
                        'label' => 'Pickup Date',
                        'required' => true,
                        'order' => 3,
                        'row' => 2,
                        'width' => 'half',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup_date',
                    ],
                    'pickup_time' => [
                        'type' => 'time',
                        'label' => 'Pickup Time',
                        'required' => true,
                        'order' => 4,
                        'row' => 2,
                        'width' => 'half',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup_time',
                        'default' => '09:00',
                    ],
                    'is_return_trip' => [
                        'type' => 'checkbox',
                        'label' => 'Add Return Trip',
                        'required' => false,
                        'order' => 5,
                        'row' => 3,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'is_return_trip',
                    ],
                    'return_date' => [
                        'type' => 'date',
                        'label' => 'Return Date',
                        'required' => false,
                        'order' => 6,
                        'row' => 4,
                        'width' => 'half',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'return_date',
                        'conditional' => 'is_return_trip',
                    ],
                    'return_time' => [
                        'type' => 'time',
                        'label' => 'Return Time',
                        'required' => false,
                        'order' => 7,
                        'row' => 4,
                        'width' => 'half',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'return_time',
                        'conditional' => 'is_return_trip',
                        'default' => '18:00',
                    ],
                ],
            ]
        );
    }
}
