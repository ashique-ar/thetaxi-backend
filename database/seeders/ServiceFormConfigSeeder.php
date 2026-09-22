<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceFormConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = $this->defaultConfigs();

        foreach ($configs as $code => $config) {
            DB::table('service_form_configs')->updateOrInsert(
                ['service_code' => $code],
                [
                    'id'         => (string) Str::uuid(),
                    'config'     => json_encode($config),
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function defaultConfigs(): array
    {
        return [
            'airport_drop' => [
                'required_fields' => ['from', 'to', 'date', 'time'],
                'optional_fields' => [],
                'special_fields' => [
                    'transfer_type' => [
                        'type' => 'radio',
                        'label' => 'Transfer Type',
                        'options' => ['from-airport' => 'From Airport', 'to-airport' => 'To Airport'],
                        'default' => 'to-airport',
                        'required' => true,
                        'layout' => ['width' => 'full', 'align' => 'center'],
                    ],
                    'from' => [
                        'type' => 'location',
                        'label' => 'Pickup Location',
                        'placeholder' => 'Enter pickup location',
                        'required' => true,
                        'location_type' => 'conditional',
                        'condition_field' => 'transfer_type',
                        'conditions' => [
                            'from-airport' => ['type' => 'airport'],
                            'to-airport' => ['type' => 'location'],
                        ],
                        'layout' => ['width' => 'half', 'align' => 'left'],
                    ],
                    'to' => [
                        'type' => 'location',
                        'label' => 'Destination',
                        'placeholder' => 'Enter destination',
                        'required' => true,
                        'location_type' => 'conditional',
                        'condition_field' => 'transfer_type',
                        'conditions' => [
                            'from-airport' => ['type' => 'location'],
                            'to-airport' => ['type' => 'airport'],
                        ],
                        'layout' => ['width' => 'half', 'align' => 'left'],
                    ],
                    'date' => ['type' => 'date', 'label' => 'Date', 'placeholder' => 'DD/MM/YYYY', 'required' => true, 'layout' => ['width' => 'half', 'align' => 'left']],
                    'time' => ['type' => 'time', 'label' => 'Time', 'placeholder' => 'HH:MM', 'required' => true, 'layout' => ['width' => 'half', 'align' => 'left']],
                ],
                'validation_rules' => [
                    'from' => 'required|string|min:5',
                    'to' => 'required|string|min:5',
                    'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                    'time' => 'required|date_format:H:i',
                    'transfer_type' => 'required|in:from-airport,to-airport',
                ],
                'field_mappings' => [
                    'dates' => ['from_date' => 'date', 'from_time' => 'time'],
                    'locations' => ['pickup_location' => 'from', 'dropoff_location' => 'to'],
                ],
                'form_action' => 'booking.search',
                'button_text' => 'Search For Vehicles',
            ],

            'airport_pickup' => [
                'required_fields' => ['from', 'to', 'date', 'time'],
                'optional_fields' => [],
                'special_fields' => [
                    'transfer_type' => [
                        'type' => 'radio',
                        'label' => 'Transfer Type',
                        'options' => ['from-airport' => 'From Airport', 'to-airport' => 'To Airport'],
                        'default' => 'from-airport',
                        'required' => true,
                    ],
                    'from' => [
                        'type' => 'location',
                        'label' => 'Pickup Location',
                        'placeholder' => 'Enter pickup location',
                        'required' => true,
                        'location_type' => 'conditional',
                        'condition_field' => 'transfer_type',
                        'conditions' => ['from-airport' => ['type' => 'airport'], 'to-airport' => ['type' => 'location']],
                    ],
                    'to' => [
                        'type' => 'location',
                        'label' => 'Destination',
                        'placeholder' => 'Enter destination',
                        'required' => true,
                        'location_type' => 'conditional',
                        'condition_field' => 'transfer_type',
                        'conditions' => ['from-airport' => ['type' => 'location'], 'to-airport' => ['type' => 'airport']],
                    ],
                    'date' => ['type' => 'date', 'label' => 'Date', 'placeholder' => 'DD/MM/YYYY', 'required' => true],
                    'time' => ['type' => 'time', 'label' => 'Time', 'placeholder' => 'HH:MM', 'required' => true],
                ],
                'validation_rules' => [
                    'from' => 'required|string|min:5',
                    'to' => 'required|string|min:5',
                    'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                    'time' => 'required|date_format:H:i',
                    'transfer_type' => 'required|in:from-airport,to-airport',
                ],
                'field_mappings' => [
                    'dates' => ['from_date' => 'date', 'from_time' => 'time'],
                    'locations' => ['pickup_location' => 'from', 'dropoff_location' => 'to'],
                ],
                'form_action' => 'booking.search',
                'button_text' => 'Search For Vehicles',
            ],

            'transfers' => [
                'required_fields' => ['pickup', 'dropoff', 'date', 'time'],
                'optional_fields' => ['need_return', 'return_pickup', 'return_dropoff', 'return_date', 'return_time'],
                'special_fields' => [
                    'pickup' => ['type' => 'location', 'label' => 'Pickup Location', 'placeholder' => 'Enter pickup location', 'required' => true],
                    'dropoff' => ['type' => 'location', 'label' => 'Drop Off Location', 'placeholder' => 'Enter drop off location', 'required' => true],
                    'pickup_date' => ['type' => 'date', 'label' => 'Date', 'placeholder' => 'DD/MM/YYYY', 'required' => true],
                    'pickup_time' => ['type' => 'time', 'label' => 'Time', 'placeholder' => 'HH:MM', 'required' => true],
                    'need_return' => ['type' => 'checkbox', 'label' => 'Return Transfer', 'value' => '1'],
                    'return_pickup' => ['type' => 'location', 'label' => 'Return Pickup Location', 'placeholder' => 'Return Pickup Location', 'conditional' => 'need_return'],
                    'return_dropoff' => ['type' => 'location', 'label' => 'Return Drop Off Location', 'placeholder' => 'Return Drop Off Location', 'conditional' => 'need_return'],
                    'return_date' => ['type' => 'date', 'label' => 'Return Date', 'placeholder' => 'DD/MM/YYYY', 'conditional' => 'need_return'],
                    'return_time' => ['type' => 'time', 'label' => 'Return Time', 'placeholder' => 'HH:MM', 'conditional' => 'need_return'],
                ],
                'validation_rules' => [
                    'pickup' => 'required|string|min:5',
                    'dropoff' => 'required|string|min:5',
                    'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                    'time' => 'required|date_format:H:i',
                    'need_return' => 'nullable|boolean',
                    'return_pickup' => 'required_if:need_return,1|string|min:5',
                    'return_dropoff' => 'required_if:need_return,1|string|min:5',
                    'return_date' => 'required_if:need_return,1|date_format:d/m/Y|after:date',
                    'return_time' => 'required_if:need_return,1|date_format:H:i',
                ],
                'form_action' => 'booking.search',
                'button_text' => 'Search For Vehicles',
            ],

            'chauffeur_driven' => [
                'required_fields' => ['pickup_location', 'date', 'duration_type', 'duration_value'],
                'optional_fields' => [],
                'special_fields' => [
                    'pickup_location' => ['type' => 'location', 'label' => 'Pickup Location', 'placeholder' => 'Enter pickup location', 'required' => true],
                    'date' => ['type' => 'date', 'label' => 'Date', 'placeholder' => 'DD/MM/YYYY', 'required' => true],
                    'duration_type' => ['type' => 'select', 'label' => 'Rental Duration', 'options' => ['days' => 'Days', 'hours' => 'Hours'], 'required' => true],
                    'duration_value' => ['type' => 'number', 'label' => 'Duration', 'min' => 1, 'max' => 30, 'required' => true],
                ],
                'validation_rules' => [
                    'pickup_location' => 'required|string|min:5',
                    'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                    'duration_type' => 'required|in:days,hours',
                    'duration_value' => 'required|integer|min:1|max:30',
                ],
                'form_action' => 'booking.search',
                'button_text' => 'Search For Vehicles',
            ],

            'self_driven' => [
                'required_fields' => ['pickup_location', 'date', 'duration_type', 'duration_value'],
                'optional_fields' => [],
                'special_fields' => [
                    'pickup_location' => ['type' => 'location', 'label' => 'Pickup Location', 'placeholder' => 'Enter pickup location', 'required' => true],
                    'date' => ['type' => 'date', 'label' => 'Date', 'placeholder' => 'DD/MM/YYYY', 'required' => true],
                    'duration_type' => ['type' => 'select', 'label' => 'Rental Duration', 'options' => ['days' => 'Days', 'hours' => 'Hours'], 'required' => true],
                    'duration_value' => ['type' => 'number', 'label' => 'Duration', 'min' => 1, 'max' => 30, 'required' => true],
                ],
                'validation_rules' => [
                    'pickup_location' => 'required|string|min:5',
                    'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                    'duration_type' => 'required|in:days,hours',
                    'duration_value' => 'required|integer|min:1|max:30',
                ],
                'form_action' => 'booking.search',
                'button_text' => 'Search For Vehicles',
            ],

            'corporate' => [
                'required_fields' => ['company_name', 'contact_person', 'email', 'phone', 'requirements'],
                'optional_fields' => [],
                'special_fields' => [
                    'company_name' => ['type' => 'text', 'label' => 'Company Name', 'placeholder' => 'Company Name'],
                    'contact_person' => ['type' => 'text', 'label' => 'Contact Person', 'placeholder' => 'Contact Person'],
                    'email' => ['type' => 'email', 'label' => 'Email Address', 'placeholder' => 'Email Address'],
                    'phone' => ['type' => 'tel', 'label' => 'Phone Number', 'placeholder' => 'Phone Number'],
                    'requirements' => ['type' => 'textarea', 'label' => 'Service Requirements', 'placeholder' => 'Describe your corporate transport requirements...', 'rows' => 4],
                ],
                'validation_rules' => [
                    'company_name' => 'required|string|min:2|max:100',
                    'contact_person' => 'required|string|min:2|max:100',
                    'email' => 'required|email|max:255',
                    'phone' => 'required|string|min:10|max:15',
                    'requirements' => 'required|string|min:10|max:1000',
                ],
                'form_action' => 'booking.enquiry',
                'button_text' => 'Submit Enquiry',
            ],

            'corporate_self' => [
                'required_fields' => ['company_name', 'contact_person', 'email', 'phone', 'requirements'],
                'optional_fields' => [],
                'special_fields' => [
                    'company_name' => ['type' => 'text', 'label' => 'Company Name', 'placeholder' => 'Company Name'],
                    'contact_person' => ['type' => 'text', 'label' => 'Contact Person', 'placeholder' => 'Contact Person'],
                    'email' => ['type' => 'email', 'label' => 'Email Address', 'placeholder' => 'Email Address'],
                    'phone' => ['type' => 'tel', 'label' => 'Phone Number', 'placeholder' => 'Phone Number'],
                    'requirements' => ['type' => 'textarea', 'label' => 'Service Requirements', 'placeholder' => 'Describe your corporate self-drive requirements...', 'rows' => 4],
                ],
                'validation_rules' => [
                    'company_name' => 'required|string|min:2|max:100',
                    'contact_person' => 'required|string|min:2|max:100',
                    'email' => 'required|email|max:255',
                    'phone' => 'required|string|min:10|max:15',
                    'requirements' => 'required|string|min:10|max:1000',
                ],
                'form_action' => 'booking.enquiry',
                'button_text' => 'Submit Enquiry',
            ],

            'wedding_hire' => [
                'required_fields' => ['pickup_location', 'date', 'time', 'duration_hours'],
                'optional_fields' => [],
                'special_fields' => [
                    'pickup_location' => ['type' => 'location', 'label' => 'Pickup Location', 'placeholder' => 'Enter pickup location', 'required' => true],
                    'date' => ['type' => 'date', 'label' => 'Date', 'placeholder' => 'DD/MM/YYYY', 'required' => true],
                    'time' => ['type' => 'time', 'label' => 'Time', 'placeholder' => 'HH:MM', 'required' => true],
                    'duration_hours' => ['type' => 'select', 'label' => 'Duration', 'options' => ['4' => '4 Hours', '8' => '8 Hours', '12' => '12 Hours'], 'required' => true],
                ],
                'validation_rules' => [
                    'pickup_location' => 'required|string|min:5',
                    'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                    'time' => 'required|date_format:H:i',
                    'duration_hours' => 'required|in:4,8,12',
                ],
                'form_action' => 'booking.search',
                'button_text' => 'Search For Vehicles',
            ],

            'custom_tour' => [
                'required_fields' => ['starting_location', 'pickup_date'],
                'special_fields' => [],
                'validation_rules' => [
                    'tour_title' => 'nullable|string|max:255',
                    'starting_location' => 'required|string|min:5',
                    'starting_lat' => 'required|numeric',
                    'starting_lng' => 'required|numeric',
                    'pickup_date' => 'required|date_format:d/m/Y|after_or_equal:today',
                    'custom_destinations' => 'nullable|string',
                ],
                'form_action' => 'booking.search',
                'button_text' => 'Search For Vehicles',
                'custom_template' => true,
            ],
        ];
    }

}
