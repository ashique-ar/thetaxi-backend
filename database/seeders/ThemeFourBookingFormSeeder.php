<?php

namespace Database\Seeders;

use App\Models\BookingFormTab;
use App\Models\Company;
use App\Models\Service\ServiceType;
use Illuminate\Database\Seeder;

class ThemeFourBookingFormSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure global base service types are restored and active
        ServiceType::withTrashed()
            ->whereIn('code', ['self_drive', 'with_driver', 'wedding_hire', 'corporate', 'airport_transfers', 'ride_now'])
            ->restore();

        ServiceType::whereIn('code', ['self_drive', 'with_driver', 'wedding_hire', 'corporate', 'airport_transfers', 'ride_now'])
            ->update(['is_active' => true]);

        // 2. Resolve or create Casons company
        $company = Company::where('domain', 'casons.lk')->first()
            ?? Company::updateOrCreate(
                ['domain' => 'casons.lk'],
                ['name' => 'Casons', 'website' => 'https://casons.lk', 'is_active' => true, 'is_default' => false]
            );

        $companyId = (string) $company->id;

        // 3. Ensure Theme 4 Booking Form Tabs are enabled
        $tabConfigs = [
            ['code' => 'self_drive', 'label' => 'Self Drive', 'service_type_code' => 'self_drive', 'sort_order' => 1, 'enabled' => true],
            ['code' => 'with_driver', 'label' => 'With Driver', 'service_type_code' => 'with_driver', 'sort_order' => 2, 'enabled' => true],
            ['code' => 'airport_transfers', 'label' => 'Airport Transfer', 'service_type_code' => 'airport_transfers', 'sort_order' => 3, 'enabled' => true],
            ['code' => 'ride_now', 'label' => 'Drop & Pickup', 'service_type_code' => 'ride_now', 'sort_order' => 4, 'enabled' => true],
            ['code' => 'wedding_hire', 'label' => 'Wedding', 'service_type_code' => 'wedding_hire', 'sort_order' => 5, 'enabled' => true],
            ['code' => 'corporate', 'label' => 'Corporate', 'service_type_code' => 'corporate', 'sort_order' => 6, 'enabled' => true],
        ];

        foreach ($tabConfigs as $tabCfg) {
            BookingFormTab::where('code', $tabCfg['code'])->update([
                'enabled' => true,
                'service_type_code' => $tabCfg['service_type_code'],
                'sort_order' => $tabCfg['sort_order'],
            ]);
        }

        // 4. Seed Casons-scoped Service Types with Theme 4 vertical/card form configurations

        // --- Self Drive ---
        ServiceType::updateOrCreate(
            [
                'code' => 'self_drive',
                'context' => 'public',
                'owner_type' => 'company',
                'owner_id' => $companyId,
            ],
            [
                'name' => 'Self Drive',
                'pricing_mode' => 'day',
                'uses_dropoff_time' => true,
                'allow_return_trip' => false,
                'is_active' => true,
                'frontend_category' => 'rental',
                'form_config' => [
                    'pickup_location' => [
                        'type' => 'location',
                        'label' => 'Pickup Location',
                        'custom_label' => 'Pickup Location',
                        'required' => true,
                        'order' => 1,
                        'row' => 1,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup',
                        'location_mode' => 'predefined_or_custom',
                        'placeholder' => 'Select pickup location',
                        'default' => 'Casons Head Office, 181, Gothami Gardens, Gothami Road, Rajagiriya, Sri Lanka.',
                        'default_lat' => '6.9271',
                        'default_lng' => '79.8612',
                        'options' => [
                            [
                                'value' => 'CASONS_HQ',
                                'label' => 'Casons Head Office',
                                'action' => 'fixed_location',
                                'address' => 'Casons Head Office, 181, Gothami Gardens, Gothami Road, Rajagiriya, Sri Lanka.',
                                'latitude' => 6.9271,
                                'longitude' => 79.8612,
                            ],
                            [
                                'value' => 'MATTALA_AIRPORT',
                                'label' => 'Mattala Airport',
                                'action' => 'fixed_location',
                                'address' => 'Mattala Rajapaksa International Airport, Hambantota',
                                'latitude' => 6.2847,
                                'longitude' => 81.1242,
                            ],
                            [
                                'value' => 'BIA_AIRPORT',
                                'label' => 'BIA Airport',
                                'action' => 'fixed_location',
                                'address' => 'Bandaranaike International Airport, Katunayake',
                                'latitude' => 7.1808,
                                'longitude' => 79.8841,
                            ],
                            [
                                'value' => 'JAFFNA_AIRPORT',
                                'label' => 'Jaffna Airport',
                                'action' => 'fixed_location',
                                'address' => 'Jaffna International Airport, Palaly',
                                'latitude' => 9.7923,
                                'longitude' => 80.0701,
                            ],
                            [
                                'value' => 'custom',
                                'label' => 'My Doorstep (Search Google)',
                                'action' => 'google_search',
                            ],
                        ],
                    ],
                    'pickup_date' => [
                        'type' => 'date',
                        'label' => 'Pickup Date',
                        'required' => true,
                        'order' => 2,
                        'row' => 2,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup_date',
                    ],
                    'pickup_time' => [
                        'type' => 'time',
                        'label' => 'Pickup Time',
                        'required' => true,
                        'order' => 3,
                        'row' => 2,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup_time',
                        'default' => '09:00',
                    ],
                    'dropoff_date' => [
                        'type' => 'date',
                        'label' => 'Return Date',
                        'required' => true,
                        'order' => 4,
                        'row' => 3,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'dropoff_date',
                    ],
                    'dropoff_time' => [
                        'type' => 'time',
                        'label' => 'Return Time',
                        'required' => true,
                        'order' => 5,
                        'row' => 3,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'dropoff_time',
                        'default' => '09:00',
                    ],
                ],
            ]
        );

        // --- With Driver ---
        ServiceType::updateOrCreate(
            [
                'code' => 'with_driver',
                'context' => 'public',
                'owner_type' => 'company',
                'owner_id' => $companyId,
            ],
            [
                'name' => 'With Driver',
                'pricing_mode' => 'day',
                'uses_dropoff_time' => true,
                'allow_return_trip' => false,
                'is_active' => true,
                'frontend_category' => 'rental',
                'form_config' => [
                    'pickup_location' => [
                        'type' => 'location',
                        'label' => 'Pickup Location',
                        'custom_label' => 'Pickup Location',
                        'required' => true,
                        'order' => 1,
                        'row' => 1,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup',
                        'location_mode' => 'predefined_or_custom',
                        'placeholder' => 'Select pickup location',
                        'default' => 'Casons Head Office, 181, Gothami Gardens, Gothami Road, Rajagiriya, Sri Lanka.',
                        'default_lat' => '6.9271',
                        'default_lng' => '79.8612',
                        'options' => [
                            [
                                'value' => 'CASONS_HQ',
                                'label' => 'Casons Head Office',
                                'action' => 'fixed_location',
                                'address' => 'Casons Head Office, 181, Gothami Gardens, Gothami Road, Rajagiriya, Sri Lanka.',
                                'latitude' => 6.9271,
                                'longitude' => 79.8612,
                            ],
                            [
                                'value' => 'MATTALA_AIRPORT',
                                'label' => 'Mattala Airport',
                                'action' => 'fixed_location',
                                'address' => 'Mattala Rajapaksa International Airport, Hambantota',
                                'latitude' => 6.2847,
                                'longitude' => 81.1242,
                            ],
                            [
                                'value' => 'BIA_AIRPORT',
                                'label' => 'BIA Airport',
                                'action' => 'fixed_location',
                                'address' => 'Bandaranaike International Airport, Katunayake',
                                'latitude' => 7.1808,
                                'longitude' => 79.8841,
                            ],
                            [
                                'value' => 'JAFFNA_AIRPORT',
                                'label' => 'Jaffna Airport',
                                'action' => 'fixed_location',
                                'address' => 'Jaffna International Airport, Palaly',
                                'latitude' => 9.7923,
                                'longitude' => 80.0701,
                            ],
                            [
                                'value' => 'custom',
                                'label' => 'My Doorstep (Search Google)',
                                'action' => 'google_search',
                            ],
                        ],
                    ],
                    'pickup_date' => [
                        'type' => 'date',
                        'label' => 'Pickup Date',
                        'required' => true,
                        'order' => 2,
                        'row' => 2,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup_date',
                    ],
                    'pickup_time' => [
                        'type' => 'time',
                        'label' => 'Pickup Time',
                        'required' => true,
                        'order' => 3,
                        'row' => 2,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup_time',
                        'default' => '09:00',
                    ],
                    'dropoff_date' => [
                        'type' => 'date',
                        'label' => 'Return Date',
                        'required' => true,
                        'order' => 4,
                        'row' => 3,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'dropoff_date',
                    ],
                    'dropoff_time' => [
                        'type' => 'time',
                        'label' => 'Return Time',
                        'required' => true,
                        'order' => 5,
                        'row' => 3,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'dropoff_time',
                        'default' => '09:00',
                    ],
                ],
            ]
        );

        // --- Airport Transfers ---
        ServiceType::updateOrCreate(
            [
                'code' => 'airport_transfers',
                'context' => 'public',
                'owner_type' => 'company',
                'owner_id' => $companyId,
            ],
            [
                'name' => 'Airport Transfer',
                'pricing_mode' => 'trip',
                'uses_dropoff_time' => false,
                'allow_return_trip' => false,
                'is_active' => true,
                'frontend_category' => 'airport',
                'form_config' => [
                    'transfer_type' => [
                        'type' => 'radio',
                        'label' => 'Transfer Type',
                        'required' => true,
                        'order' => 1,
                        'row' => 1,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'transfer_type',
                        'options' => [
                            ['value' => 'from-airport', 'label' => 'From Airport'],
                            ['value' => 'to-airport', 'label' => 'To Airport'],
                        ],
                        'default' => 'from-airport',
                    ],
                    'pickup_location' => [
                        'type' => 'location',
                        'label' => 'Pickup Location',
                        'required' => true,
                        'order' => 2,
                        'row' => 2,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup',
                        'location_mode' => 'conditional',
                        'condition_field' => 'transfer_type',
                        'conditions' => [
                            'from-airport' => ['type' => 'airport'],
                            'to-airport' => ['type' => 'location'],
                        ],
                        'placeholder' => 'Enter pickup location',
                        'default' => 'Colombo BIA Airport',
                        'default_lat' => '7.1808',
                        'default_lng' => '79.8841',
                    ],
                    'dropoff_location' => [
                        'type' => 'location',
                        'label' => 'Drop Off Location',
                        'required' => true,
                        'order' => 3,
                        'row' => 3,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'dropoff',
                        'location_mode' => 'conditional',
                        'condition_field' => 'transfer_type',
                        'conditions' => [
                            'from-airport' => ['type' => 'location'],
                            'to-airport' => ['type' => 'airport'],
                        ],
                        'placeholder' => 'Enter drop off location',
                        'default' => 'Colombo, Sri Lanka',
                        'default_lat' => '6.9271',
                        'default_lng' => '79.8612',
                    ],
                    'pickup_date' => [
                        'type' => 'date',
                        'label' => 'Pickup Date',
                        'required' => true,
                        'order' => 4,
                        'row' => 4,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'date',
                    ],
                    'pickup_time' => [
                        'type' => 'time',
                        'label' => 'Pickup Time',
                        'required' => true,
                        'order' => 5,
                        'row' => 4,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'time',
                        'default' => '09:00',
                    ],
                ],
            ]
        );

        // --- Drop & Pickup (Ride Now) ---
        ServiceType::updateOrCreate(
            [
                'code' => 'ride_now',
                'context' => 'public',
                'owner_type' => 'company',
                'owner_id' => $companyId,
            ],
            [
                'name' => 'Drop & Pickup',
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
                        'width' => 'full',
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
                        'row' => 2,
                        'width' => 'full',
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
                        'row' => 3,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup_date',
                    ],
                    'pickup_time' => [
                        'type' => 'time',
                        'label' => 'Pickup Time',
                        'required' => true,
                        'order' => 4,
                        'row' => 4,
                        'width' => 'full',
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
                        'row' => 5,
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
                        'row' => 6,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'return_date',
                        'conditional' => 'is_return_trip',
                    ],
                    'return_time' => [
                        'type' => 'time',
                        'label' => 'Return Time',
                        'required' => false,
                        'order' => 7,
                        'row' => 6,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'return_time',
                        'conditional' => 'is_return_trip',
                        'default' => '18:00',
                    ],
                ],
            ]
        );

        // --- Wedding Hire ---
        ServiceType::updateOrCreate(
            [
                'code' => 'wedding_hire',
                'context' => 'public',
                'owner_type' => 'company',
                'owner_id' => $companyId,
            ],
            [
                'name' => 'Wedding Hire',
                'pricing_mode' => 'trip',
                'uses_dropoff_time' => false,
                'allow_return_trip' => false,
                'is_inquiry' => true,
                'is_active' => true,
                'frontend_category' => 'special',
                'form_config' => [
                    'pickup_location' => [
                        'type' => 'location',
                        'label' => 'Pickup Location',
                        'required' => true,
                        'order' => 1,
                        'row' => 1,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'pickup',
                        'location_mode' => 'autocomplete',
                        'placeholder' => 'Enter pickup location',
                        'default' => 'Colombo, Sri Lanka',
                        'default_lat' => '6.9271',
                        'default_lng' => '79.8612',
                    ],
                    'pickup_date' => [
                        'type' => 'date',
                        'label' => 'Wedding Date',
                        'required' => true,
                        'order' => 2,
                        'row' => 2,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'date',
                    ],
                    'pickup_time' => [
                        'type' => 'time',
                        'label' => 'Pickup Time',
                        'required' => true,
                        'order' => 3,
                        'row' => 2,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'time',
                        'default' => '09:00',
                    ],
                ],
            ]
        );

        // --- Corporate Transport ---
        ServiceType::updateOrCreate(
            [
                'code' => 'corporate',
                'context' => 'public',
                'owner_type' => 'company',
                'owner_id' => $companyId,
            ],
            [
                'name' => 'Corporate Transport',
                'pricing_mode' => 'trip',
                'uses_dropoff_time' => false,
                'allow_return_trip' => false,
                'is_inquiry' => true,
                'is_active' => true,
                'frontend_category' => 'corporate',
                'form_config' => [
                    'pickup_location' => [
                        'type' => 'location',
                        'label' => 'Pickup Location',
                        'required' => true,
                        'order' => 1,
                        'row' => 1,
                        'width' => 'full',
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
                        'row' => 2,
                        'width' => 'full',
                        'tablet_width' => 'full',
                        'mobile_width' => 'full',
                        'submit_as' => 'dropoff',
                        'location_mode' => 'autocomplete',
                        'placeholder' => 'Enter drop off location',
                        'default' => 'Colombo, Sri Lanka',
                        'default_lat' => '6.9271',
                        'default_lng' => '79.8612',
                    ],
                    'pickup_date' => [
                        'type' => 'date',
                        'label' => 'Pickup Date',
                        'required' => true,
                        'order' => 3,
                        'row' => 3,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'date',
                    ],
                    'pickup_time' => [
                        'type' => 'time',
                        'label' => 'Pickup Time',
                        'required' => true,
                        'order' => 4,
                        'row' => 3,
                        'width' => 'half',
                        'tablet_width' => 'half',
                        'mobile_width' => 'full',
                        'submit_as' => 'time',
                        'default' => '09:00',
                    ],
                ],
            ]
        );
    }
}
