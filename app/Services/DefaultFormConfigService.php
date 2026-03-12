<?php

namespace App\Services;

/**
 * Provides default form_config field definitions for each service type.
 * Used as fallback when no custom config is stored in the database.
 * These match the existing hardcoded form layouts exactly.
 */
class DefaultFormConfigService
{
    /**
     * Get default form fields for a service type code.
     */
    public static function getDefaults(string $serviceCode): array
    {
        return match ($serviceCode) {
            'airport_transfers' => self::airportTransfers(),
            'ride_now' => self::rideNow(),
            'day_rental' => self::dayRental(),
            'self_drive' => self::selfDrive(),
            'with_driver' => self::withDriver(),
            'wedding_hire' => self::weddingHire(),
            'corporate' => self::corporate(),
            default => self::generic(),
        };
    }

    protected static function airportTransfers(): array
    {
        return [
            'transfer_type' => [
                'type' => 'radio',
                'label' => 'Transfer Type',
                'required' => true,
                'order' => 1,
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
                'submit_as' => 'pickup',
                'location_mode' => 'conditional',
                'condition_field' => 'transfer_type',
                'conditions' => [
                    'from-airport' => ['type' => 'airport'],
                    'to-airport' => ['type' => 'any'],
                ],
                'placeholder' => 'Enter pickup location',
                'default' => 'Colombo BIA Airport',
                'default_lat' => '7.1808',
                'default_lng' => '79.8841',
            ],
            'dropoff_location' => [
                'type' => 'location',
                'label' => 'Destination',
                'required' => true,
                'order' => 3,
                'submit_as' => 'dropoff',
                'location_mode' => 'conditional',
                'condition_field' => 'transfer_type',
                'conditions' => [
                    'from-airport' => ['type' => 'any'],
                    'to-airport' => ['type' => 'airport'],
                ],
                'placeholder' => 'Enter destination',
                'default' => 'Colombo, Sri Lanka',
                'default_lat' => '6.9271',
                'default_lng' => '79.8612',
            ],
            'date' => [
                'type' => 'date',
                'label' => 'Pickup Date',
                'required' => true,
                'order' => 4,
                'submit_as' => 'date',
            ],
            'time' => [
                'type' => 'time',
                'label' => 'Pickup Time',
                'required' => true,
                'order' => 5,
                'submit_as' => 'time',
                'default' => '09:00',
            ],
        ];
    }

    protected static function rideNow(): array
    {
        return [
            'pickup_location' => [
                'type' => 'location',
                'label' => 'Pickup Location',
                'required' => true,
                'order' => 1,
                'submit_as' => 'pickup',
                'location_mode' => 'autocomplete',
                'placeholder' => 'Pick up Location',
                'default' => 'Colombo, Sri Lanka',
                'default_lat' => '6.9271',
                'default_lng' => '79.8612',
            ],
            'dropoff_location' => [
                'type' => 'location',
                'label' => 'Drop Off Location',
                'required' => true,
                'order' => 2,
                'submit_as' => 'dropoff',
                'location_mode' => 'autocomplete',
                'placeholder' => 'Drop Off Location',
                'default' => 'Galle, Sri Lanka',
                'default_lat' => '6.0535',
                'default_lng' => '80.2210',
            ],
            'pickup_date' => [
                'type' => 'date',
                'label' => 'Pickup Date',
                'required' => true,
                'order' => 3,
                'submit_as' => 'pickup_date',
            ],
            'pickup_time' => [
                'type' => 'time',
                'label' => 'Pickup Time',
                'required' => true,
                'order' => 4,
                'submit_as' => 'pickup_time',
                'default' => '09:00',
            ],
            'package_id' => [
                'type' => 'package_select',
                'label' => 'Select Package',
                'required' => false,
                'order' => 5,
                'submit_as' => 'package_id',
                'placeholder' => 'Choose a package (optional)',
            ],
        ];
    }

    protected static function dayRental(): array
    {
        return [
            'pickup_location' => [
                'type' => 'location',
                'label' => 'Pickup Location',
                'required' => true,
                'order' => 1,
                'submit_as' => 'pickup',
                'location_mode' => 'autocomplete',
                'placeholder' => 'Pick up Location',
                'default' => 'Colombo, Sri Lanka',
                'default_lat' => '6.9271',
                'default_lng' => '79.8612',
            ],
            'pickup_date' => [
                'type' => 'date',
                'label' => 'Pickup Date',
                'required' => true,
                'order' => 2,
                'submit_as' => 'pickup_date',
            ],
            'pickup_time' => [
                'type' => 'time',
                'label' => 'Pickup Time',
                'required' => true,
                'order' => 3,
                'submit_as' => 'pickup_time',
                'default' => '09:00',
            ],
            'dropoff_date' => [
                'type' => 'date',
                'label' => 'Return Date',
                'required' => true,
                'order' => 4,
                'submit_as' => 'dropoff_date',
            ],
            'dropoff_time' => [
                'type' => 'time',
                'label' => 'Return Time',
                'required' => true,
                'order' => 5,
                'submit_as' => 'dropoff_time',
                'default' => '09:00',
            ],
            'package_id' => [
                'type' => 'package_select',
                'label' => 'Select Package',
                'required' => false,
                'order' => 6,
                'submit_as' => 'package_id',
                'placeholder' => 'Choose a package (optional)',
            ],
        ];
    }

    protected static function selfDrive(): array
    {
        return [
            'pickup_location' => [
                'type' => 'location',
                'label' => 'Pickup Location',
                'required' => true,
                'order' => 1,
                'submit_as' => 'pickup',
                'location_mode' => 'predefined_or_custom',
                'placeholder' => 'Select pickup location',
                'default' => 'Casons Head Office',
                'default_lat' => '6.9187556338924585',
                'default_lng' => '79.88803557115918',
            ],
            'date' => [
                'type' => 'date',
                'label' => 'Pickup Date',
                'required' => true,
                'order' => 2,
                'submit_as' => 'date',
            ],
            'time' => [
                'type' => 'time',
                'label' => 'Pickup Time',
                'required' => true,
                'order' => 3,
                'submit_as' => 'time',
                'default' => '09:00',
            ],
            'dropoff_date' => [
                'type' => 'date',
                'label' => 'Return Date',
                'required' => true,
                'order' => 4,
                'submit_as' => 'dropoff_date',
            ],
            'dropoff_time' => [
                'type' => 'time',
                'label' => 'Return Time',
                'required' => true,
                'order' => 5,
                'submit_as' => 'dropoff_time',
                'default' => '09:00',
            ],
        ];
    }

    protected static function withDriver(): array
    {
        // Same structure as self_drive
        return self::selfDrive();
    }

    protected static function weddingHire(): array
    {
        return [
            'pickup_location' => [
                'type' => 'location',
                'label' => 'Pickup Location',
                'required' => true,
                'order' => 1,
                'submit_as' => 'pickup',
                'location_mode' => 'autocomplete',
                'placeholder' => 'Enter pickup location',
                'default' => 'Colombo, Sri Lanka',
                'default_lat' => '6.9271',
                'default_lng' => '79.8612',
            ],
            'date' => [
                'type' => 'date',
                'label' => 'Wedding Date',
                'required' => true,
                'order' => 2,
                'submit_as' => 'date',
            ],
            'time' => [
                'type' => 'time',
                'label' => 'Pickup Time',
                'required' => true,
                'order' => 3,
                'submit_as' => 'time',
                'default' => '09:00',
            ],
        ];
    }

    protected static function corporate(): array
    {
        return [
            'company_name' => [
                'type' => 'text',
                'label' => 'Company Name',
                'required' => true,
                'order' => 1,
                'submit_as' => 'company_name',
                'placeholder' => 'Your company name',
            ],
            'contact_person' => [
                'type' => 'text',
                'label' => 'Contact Person',
                'required' => true,
                'order' => 2,
                'submit_as' => 'contact_person',
                'placeholder' => 'Contact person name',
            ],
            'email' => [
                'type' => 'text',
                'label' => 'Email',
                'required' => true,
                'order' => 3,
                'submit_as' => 'email',
                'placeholder' => 'Email address',
            ],
            'phone' => [
                'type' => 'text',
                'label' => 'Phone',
                'required' => true,
                'order' => 4,
                'submit_as' => 'phone',
                'placeholder' => 'Phone number',
            ],
            'requirements' => [
                'type' => 'textarea',
                'label' => 'Requirements',
                'required' => true,
                'order' => 5,
                'submit_as' => 'requirements',
                'placeholder' => 'Describe your transport requirements',
            ],
        ];
    }

    protected static function generic(): array
    {
        return [
            'pickup_location' => [
                'type' => 'location',
                'label' => 'Pickup Location',
                'required' => true,
                'order' => 1,
                'submit_as' => 'pickup',
                'location_mode' => 'autocomplete',
                'placeholder' => 'Enter pickup location',
                'default' => 'Colombo, Sri Lanka',
                'default_lat' => '6.9271',
                'default_lng' => '79.8612',
            ],
            'date' => [
                'type' => 'date',
                'label' => 'Date',
                'required' => true,
                'order' => 2,
                'submit_as' => 'date',
            ],
            'time' => [
                'type' => 'time',
                'label' => 'Time',
                'required' => true,
                'order' => 3,
                'submit_as' => 'time',
                'default' => '09:00',
            ],
        ];
    }
}
