<?php

namespace App\Services;

use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DynamicServiceConfigurationService
{
    /**
     * Cache timeout for service configurations (30 minutes)
     */
    private const CACHE_TIMEOUT = 1800;

    /**
     * Frontend service categories mapping for enhanced UX
     */
    private const FRONTEND_CATEGORIES = [
        'transport' => [
            'name' => 'Transportation Services',
            'icon' => 'car-front',
            'description' => 'Professional transportation solutions',
            'color' => '#007bff'
        ],
        'airport' => [
            'name' => 'Airport Services',
            'icon' => 'airplane',
            'description' => 'Convenient airport transfers and pickups',
            'color' => '#28a745'
        ],
        'special' => [
            'name' => 'Special Events',
            'icon' => 'calendar-heart',
            'description' => 'Luxury services for special occasions',
            'color' => '#6f42c1'
        ],
        'corporate' => [
            'name' => 'Corporate Services',
            'icon' => 'building',
            'description' => 'Professional corporate transportation',
            'color' => '#343a40'
        ],
        'emergency' => [
            'name' => 'Emergency Services',
            'icon' => 'exclamation-triangle',
            'description' => 'Emergency breakdown and recovery',
            'color' => '#dc3545'
        ]
    ];

    /**
     * Service field configurations based on old form structure
     */
    private const SERVICE_FIELD_CONFIGS = [
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
                    'layout' => [
                        'width' => 'full',
                        'align' => 'center'
                    ]
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
                        'to-airport' => ['type' => 'location']
                    ],
                    'layout' => [
                        'width' => 'half',
                        'align' => 'left'
                    ]
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
                        'to-airport' => ['type' => 'airport']
                    ],
                    'layout' => [
                        'width' => 'half',
                        'align' => 'left'
                    ]
                ],
                'date' => [
                    'type' => 'date',
                    'label' => 'Date',
                    'placeholder' => 'DD/MM/YYYY',
                    'required' => true,
                    'layout' => [
                        'width' => 'half',
                        'align' => 'left'
                    ]
                ],
                'time' => [
                    'type' => 'time',
                    'label' => 'Time',
                    'placeholder' => 'HH:MM',
                    'required' => true,
                    'layout' => [
                        'width' => 'half',
                        'align' => 'left'
                    ]
                ]
            ],
            'validation_rules' => [
                'from' => 'required|string|min:5',
                'to' => 'required|string|min:5',
                'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                'time' => 'required|date_format:H:i',
                'transfer_type' => 'required|in:from-airport,to-airport'
            ],
            'field_mappings' => [
                'dates' => [
                    'from_date' => 'date',
                    'from_time' => 'time',
                ],
                'locations' => [
                    'pickup_location' => 'from',
                    'dropoff_location' => 'to'
                ]
            ],
            'form_action' => 'booking.search',
            'button_text' => 'Search For Vehicles'
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
                    'required' => true
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
                        'to-airport' => ['type' => 'location']
                    ]
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
                        'to-airport' => ['type' => 'airport']
                    ]
                ],
                'date' => [
                    'type' => 'date',
                    'label' => 'Date',
                    'placeholder' => 'DD/MM/YYYY',
                    'required' => true
                ],
                'time' => [
                    'type' => 'time',
                    'label' => 'Time',
                    'placeholder' => 'HH:MM',
                    'required' => true
                ]
            ],
            'validation_rules' => [
                'from' => 'required|string|min:5',
                'to' => 'required|string|min:5',
                'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                'time' => 'required|date_format:H:i',
                'transfer_type' => 'required|in:from-airport,to-airport'
            ],
            'field_mappings' => [
                'dates' => [
                    'from_date' => 'date',
                    'from_time' => 'time',
                ],
                'locations' => [
                    'pickup_location' => 'from',
                    'dropoff_location' => 'to'
                ]
            ],
            'form_action' => 'booking.search',
            'button_text' => 'Search For Vehicles'
        ],
        'transfers' => [
            'required_fields' => ['pickup', 'dropoff', 'date', 'time'],
            'optional_fields' => ['need_return', 'return_pickup', 'return_dropoff', 'return_date', 'return_time'],
            'special_fields' => [
                'pickup' => [
                    'type' => 'location',
                    'label' => 'Pickup Location',
                    'placeholder' => 'Enter pickup location',
                    'required' => true
                ],
                'dropoff' => [
                    'type' => 'location',
                    'label' => 'Drop Off Location',
                    'placeholder' => 'Enter drop off location',
                    'required' => true
                ],
                'pickup_date' => [
                    'type' => 'date',
                    'label' => 'Date',
                    'placeholder' => 'DD/MM/YYYY',
                    'required' => true
                ],
                'pickup_time' => [
                    'type' => 'time',
                    'label' => 'Time',
                    'placeholder' => 'HH:MM',
                    'required' => true
                ],
                'need_return' => [
                    'type' => 'checkbox',
                    'label' => 'Return Transfer',
                    'value' => '1'
                ],
                'return_pickup' => [
                    'type' => 'location',
                    'label' => 'Return Pickup Location',
                    'placeholder' => 'Return Pickup Location',
                    'conditional' => 'need_return'
                ],
                'return_dropoff' => [
                    'type' => 'location',
                    'label' => 'Return Drop Off Location',
                    'placeholder' => 'Return Drop Off Location',
                    'conditional' => 'need_return'
                ],
                'return_date' => [
                    'type' => 'date',
                    'label' => 'Return Date',
                    'placeholder' => 'DD/MM/YYYY',
                    'conditional' => 'need_return'
                ],
                'return_time' => [
                    'type' => 'time',
                    'label' => 'Return Time',
                    'placeholder' => 'HH:MM',
                    'conditional' => 'need_return'
                ]
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
                'return_time' => 'required_if:need_return,1|date_format:H:i'
            ],
            'form_action' => 'booking.search',
            'button_text' => 'Search For Vehicles'
        ],
        'chauffeur_driven' => [
            'required_fields' => ['pickup_location', 'date', 'duration_type', 'duration_value'],
            'optional_fields' => [],
            'special_fields' => [
                'pickup_location' => [
                    'type' => 'location',
                    'label' => 'Pickup Location',
                    'placeholder' => 'Enter pickup location',
                    'required' => true
                ],
                'date' => [
                    'type' => 'date',
                    'label' => 'Date',
                    'placeholder' => 'DD/MM/YYYY',
                    'required' => true
                ],
                'duration_type' => [
                    'type' => 'select',
                    'label' => 'Rental Duration',
                    'options' => ['days' => 'Days', 'hours' => 'Hours'],
                    'required' => true
                ],
                'duration_value' => [
                    'type' => 'number',
                    'label' => 'Duration',
                    'min' => 1,
                    'max' => 30,
                    'required' => true
                ]
            ],
            'validation_rules' => [
                'pickup_location' => 'required|string|min:5',
                'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                'duration_type' => 'required|in:days,hours',
                'duration_value' => 'required|integer|min:1|max:30'
            ],
            'form_action' => 'booking.search',
            'button_text' => 'Search For Vehicles'
        ],
        'self_driven' => [
            'required_fields' => ['pickup_location', 'date', 'duration_type', 'duration_value'],
            'optional_fields' => [],
            'special_fields' => [
                'pickup_location' => [
                    'type' => 'location',
                    'label' => 'Pickup Location',
                    'placeholder' => 'Enter pickup location',
                    'required' => true
                ],
                'date' => [
                    'type' => 'date',
                    'label' => 'Date',
                    'placeholder' => 'DD/MM/YYYY',
                    'required' => true
                ],
                'duration_type' => [
                    'type' => 'select',
                    'label' => 'Rental Duration',
                    'options' => ['days' => 'Days', 'hours' => 'Hours'],
                    'required' => true
                ],
                'duration_value' => [
                    'type' => 'number',
                    'label' => 'Duration',
                    'min' => 1,
                    'max' => 30,
                    'required' => true
                ]
            ],
            'validation_rules' => [
                'pickup_location' => 'required|string|min:5',
                'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                'duration_type' => 'required|in:days,hours',
                'duration_value' => 'required|integer|min:1|max:30'
            ],
            'form_action' => 'booking.search',
            'button_text' => 'Search For Vehicles'
        ],
        'corporate' => [
            'required_fields' => ['company_name', 'contact_person', 'email', 'phone', 'requirements'],
            'optional_fields' => [],
            'special_fields' => [
                'company_name' => [
                    'type' => 'text',
                    'label' => 'Company Name',
                    'placeholder' => 'Company Name'
                ],
                'contact_person' => [
                    'type' => 'text',
                    'label' => 'Contact Person',
                    'placeholder' => 'Contact Person'
                ],
                'email' => [
                    'type' => 'email',
                    'label' => 'Email Address',
                    'placeholder' => 'Email Address'
                ],
                'phone' => [
                    'type' => 'tel',
                    'label' => 'Phone Number',
                    'placeholder' => 'Phone Number'
                ],
                'requirements' => [
                    'type' => 'textarea',
                    'label' => 'Service Requirements',
                    'placeholder' => 'Describe your corporate transport requirements...',
                    'rows' => 4
                ]
            ],
            'validation_rules' => [
                'company_name' => 'required|string|min:2|max:100',
                'contact_person' => 'required|string|min:2|max:100',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|min:10|max:15',
                'requirements' => 'required|string|min:10|max:1000'
            ],
            'form_action' => 'booking.enquiry',
            'button_text' => 'Submit Enquiry'
        ],
        'corporate_self' => [
            'required_fields' => ['company_name', 'contact_person', 'email', 'phone', 'requirements'],
            'optional_fields' => [],
            'special_fields' => [
                'company_name' => [
                    'type' => 'text',
                    'label' => 'Company Name',
                    'placeholder' => 'Company Name'
                ],
                'contact_person' => [
                    'type' => 'text',
                    'label' => 'Contact Person',
                    'placeholder' => 'Contact Person'
                ],
                'email' => [
                    'type' => 'email',
                    'label' => 'Email Address',
                    'placeholder' => 'Email Address'
                ],
                'phone' => [
                    'type' => 'tel',
                    'label' => 'Phone Number',
                    'placeholder' => 'Phone Number'
                ],
                'requirements' => [
                    'type' => 'textarea',
                    'label' => 'Service Requirements',
                    'placeholder' => 'Describe your corporate self-drive requirements...',
                    'rows' => 4
                ]
            ],
            'validation_rules' => [
                'company_name' => 'required|string|min:2|max:100',
                'contact_person' => 'required|string|min:2|max:100',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|min:10|max:15',
                'requirements' => 'required|string|min:10|max:1000'
            ],
            'form_action' => 'booking.enquiry',
            'button_text' => 'Submit Enquiry'
        ],
        'wedding_hire' => [
            'required_fields' => ['pickup_location', 'date', 'time', 'duration_hours'],
            'optional_fields' => [],
            'special_fields' => [
                'pickup_location' => [
                    'type' => 'location',
                    'label' => 'Pickup Location',
                    'placeholder' => 'Enter pickup location',
                    'required' => true
                ],
                'date' => [
                    'type' => 'date',
                    'label' => 'Date',
                    'placeholder' => 'DD/MM/YYYY',
                    'required' => true
                ],
                'time' => [
                    'type' => 'time',
                    'label' => 'Time',
                    'placeholder' => 'HH:MM',
                    'required' => true
                ],
                'duration_hours' => [
                    'type' => 'select',
                    'label' => 'Duration',
                    'options' => ['4' => '4 Hours', '8' => '8 Hours', '12' => '12 Hours'],
                    'required' => true
                ]
            ],
            'validation_rules' => [
                'pickup_location' => 'required|string|min:5',
                'date' => 'required|date_format:d/m/Y|after_or_equal:today',
                'time' => 'required|date_format:H:i',
                'duration_hours' => 'required|in:4,8,12'
            ],
            'form_action' => 'booking.search',
            'button_text' => 'Search For Vehicles'
        ],
        'custom_tour' => [
            'required_fields' => ['starting_location', 'pickup_date'],
            'special_fields' => [
            ],
            'validation_rules' => [
                'tour_title' => 'nullable|string|max:255',
                'starting_location' => 'required|string|min:5',
                'starting_lat' => 'required|numeric',
                'starting_lng' => 'required|numeric',
                'pickup_date' => 'required|date_format:d/m/Y|after_or_equal:today',
                'custom_destinations' => 'nullable|string'
            ],
            'form_action' => 'booking.search',
            'button_text' => 'Search For Vehicles',
            'custom_template' => true // Indicates this needs special frontend handling
        ]
    ];

    /**
     * Get all available service types from database with enhanced configuration
     * Filters out internal services and orders by priority
     */
    public function getAllServiceTypes(): Collection
    {
        return Cache::remember('service_types_enhanced', self::CACHE_TIMEOUT, function () {
            return ServiceType::where('is_active', true)
                ->where('is_internal', false) // Filter out internal services
                ->orderBy('priority')
                ->get()
                ->map(function ($serviceType) {
                    return $this->enhanceServiceTypeData($serviceType);
                });
        });
    }

    /**
     * Get service types grouped by categories for frontend display
     * Groups services according to old form structure:

    * - Airport Transfer: airport_drop, airport_pickup  
     * - Drop & Pickup: transfers (transport category)
     * - Rental Packages: chauffeur_driven, self_driven (transport category long-term)
     * - Custom Tour: wedding_hire (special category)
     * - Corporate Transport: corporate, corporate_self (inquiry only)
     */
    public function getServiceTypesByCategory(): array
    {
        $serviceTypes = $this->getAllServiceTypes();
        $categorized = [];

        // Define category mapping based on old form structure
        $categoryMapping = [
            'airport_transfers' => [
                'category_info' => [
                    'name' => 'Airport Transfer',
                    'icon' => 'airplane',
                    'description' => 'Direct transfers to/from airport with professional service',
                    'color' => '#28a745'
                ],
                'services' => $serviceTypes->where('category', 'airport')->values()->toArray()
            ],
            'point_to_point' => [
                'category_info' => [
                    'name' => 'Drop & Pickup',
                    'icon' => 'map-pin',
                    'description' => 'Point-to-point transportation service',
                    'color' => '#007bff'
                ],
                'services' => $serviceTypes->where('code', 'transfers')->values()->toArray()
            ],
            'ride_now' => [
                'category_info' => [
                    'name' => 'Rental Packages',
                    'icon' => 'calendar',
                    'description' => 'Long-term vehicle rental with flexible duration options',
                    'color' => '#6f42c1'
                ],
                'services' => $serviceTypes->whereIn('code', ['chauffeur_driven', 'self_driven'])->values()->toArray()
            ],
            'day_rental' => [
                'category_info' => [
                    'name' => 'Day Rental',
                    'icon' => 'calendar-day',
                    'description' => 'Daily vehicle rental with driver for tours and day trips',
                    'color' => '#17a2b8'
                ],
                'services' => $serviceTypes->whereIn('code', ['day_rental'])->values()->toArray()
            ],
            'custom-tour' => [
                'category_info' => [
                    'name' => 'Custom Tour',
                    'icon' => 'star',
                    'description' => 'Custom tour packages and special event services',
                    'color' => '#e83e8c'
                ],
                'services' => $serviceTypes->whereIn('code', ['custom_tour'])->values()->toArray()
            ],
            'special-events' => [
                'category_info' => [
                    'name' => 'Special Events',
                    'icon' => 'calendar-heart',
                    'description' => 'Wedding and special occasion services',
                    'color' => '#d63384'
                ],
                'services' => $serviceTypes->whereIn('code', ['wedding_hire'])->values()->toArray()
            ],
            'corporate-transport' => [
                'category_info' => [
                    'name' => 'Corporate Transport',
                    'icon' => 'building',
                    'description' => 'Professional corporate transportation solutions',
                    'color' => '#343a40'
                ],
                'services' => $serviceTypes->where('category', 'corporate')->values()->toArray()
            ]
        ];

        // Only include categories that have services
        foreach ($categoryMapping as $categoryKey => $categoryData) {
            if (!empty($categoryData['services'])) {
                $categorized[$categoryKey] = $categoryData;
            }
        }

        return $categorized;
    }

    /**
     * Get dynamic form configuration for a specific service type
     */
    public function getServiceFormConfiguration(string $serviceCode): array
    {
        $cacheKey = "service_form_config_{$serviceCode}";

        return Cache::remember($cacheKey, self::CACHE_TIMEOUT, function () use ($serviceCode) {
            $serviceType = ServiceType::where('code', $serviceCode)->first();

            if (!$serviceType) {
                return [
                    'error' => 'Service type not found',
                    'fields' => [],
                    'validation_rules' => []
                ];
            }

            $baseConfig = self::SERVICE_FIELD_CONFIGS[$serviceCode] ?? [
                'required_fields' => ['pickup_location', 'from_date', 'passengers'],
                'optional_fields' => [],
                'special_fields' => [],
                'validation_rules' => []
            ];

            // Get pricing slabs for duration fields
            $slabs = $this->getServicePricingSlabs($serviceType->id);

            // Get common rates for additional field options
            $commonRates = $this->getServiceCommonRates($serviceType->id);

            return [
                'service_type' => $serviceType,
                'base_fields' => $baseConfig,
                'pricing_slabs' => $slabs,
                'common_rates' => $commonRates,
                'frontend_category' => $this->determineServiceCategory($serviceCode),
                'calculation_formula' => $this->getServiceCalculationFormula($serviceType->id),
                'estimated_duration_range' => $this->getServiceDurationRange($slabs),
                'supported_features' => $this->getServiceFeatures($serviceCode)
            ];
        });
    }

    /**
     * Get validation rules for a specific service type
     */
    public function getServiceValidationRules(string $serviceCode): array
    {
        $config = $this->getServiceFormConfiguration($serviceCode);
        $baseRules = [
            'service_type' => 'required|string',
            'pickup_location' => 'required|string|min:5',
            'from_date' => 'required|date|after_or_equal:today',
            'passengers' => 'required|integer|min:1|max:50'
        ];

        $serviceSpecificRules = $config['base_fields']['validation_rules'] ?? [];

        return array_merge($baseRules, $serviceSpecificRules);
    }

    /**
     * Generate frontend service options for select dropdowns
     */
    public function getFrontendServiceOptions(): array
    {
        $categorized = $this->getServiceTypesByCategory();
        $options = [];

        foreach ($categorized as $categoryKey => $categoryData) {
            $categoryInfo = $categoryData['category_info'];
            $options[] = [
                'type' => 'optgroup',
                'label' => $categoryInfo['name'],
                'disabled' => true
            ];

            foreach ($categoryData['services'] as $service) {
                $options[] = [
                    'value' => $service['code'],
                    'label' => $service['name'],
                    'description' => $service['description'],
                    'icon' => $categoryInfo['icon'],
                    'category' => $categoryKey,
                    'pricing_type' => $service['pricing_type']
                ];
            }
        }

        return $options;
    }

    /**
     * Enhanced service type data with additional metadata
     */
    private function enhanceServiceTypeData($serviceType): array
    {
        $slabs = $this->getServicePricingSlabs($serviceType->id);
        $commonRates = $this->getServiceCommonRates($serviceType->id);

        return [
            'id' => $serviceType->id,
            'code' => $serviceType->code,
            'name' => $serviceType->name,
            'description' => $serviceType->description,
            'type' => $serviceType->type,
            'priority' => $serviceType->priority,
            'is_active' => $serviceType->is_active,
            'pricing_type' => $this->determinePricingType($slabs),
            'duration_based' => $this->isDurationBased($slabs),
            'km_based' => $this->isKmBased($commonRates),
            'has_slabs' => $slabs->isNotEmpty(),
            'slab_count' => $slabs->count(),
            'min_duration' => $this->getMinDuration($slabs),
            'max_duration' => $this->getMaxDuration($slabs),
            'supported_features' => $this->getServiceFeatures($serviceType->code),
            'category' => $this->determineServiceCategory($serviceType->code)
        ];
    }

    /**
     * Determine which category a service belongs to
     */
    private function determineServiceCategory(string $serviceCode): string
    {
        $categoryMapping = [
            'airport_drop' => 'airport',
            'airport_pickup' => 'airport',
            'transfers' => 'transport',
            'chauffeur_driven' => 'transport',
            'self_driven' => 'transport',
            'wedding_hire' => 'special',
            'corporate' => 'corporate',
            'corporate_self' => 'corporate',
            'break_down_service' => 'emergency'
        ];

        return $categoryMapping[$serviceCode] ?? 'transport';
    }

    /**
     * Get pricing slabs for a service type
     */
    private function getServicePricingSlabs($serviceTypeId): Collection
    {
        return VehiclePricingSlabDefinition::where('service_type_id', $serviceTypeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get common rates for a service type
     */
    private function getServiceCommonRates($serviceTypeId): Collection
    {
        return VehiclePricingCommonRateDefinition::where('service_type_id', $serviceTypeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get calculation formula for a service type
     */
    private function getServiceCalculationFormula($serviceTypeId): ?string
    {
        // Calculation definitions use 'status' enum (active/inactive/draft)
        $calculation = VehiclePricingCalculationDefinition::where('service_type_id', $serviceTypeId)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->first();

        return $calculation?->formula;
    }

    /**
     * Determine pricing type based on slabs
     */
    private function determinePricingType($slabs): string
    {
        if ($slabs->isEmpty()) {
            return 'per_km';
        }

        $types = $slabs->pluck('type')->unique();

        if ($types->contains('flat_rate')) {
            return 'flat_rate';
        } elseif ($types->contains('per_day')) {
            return 'per_day';
        } elseif ($types->contains('per_km')) {
            return 'per_km';
        }

        return 'per_day';
    }

    /**
     * Check if service is duration-based
     */
    private function isDurationBased($slabs): bool
    {
        return $slabs->where('type', 'per_day')->isNotEmpty() ||
            $slabs->where('type', 'flat_rate')->isNotEmpty();
    }

    /**
     * Check if service is KM-based
     */
    private function isKmBased($commonRates): bool
    {
        return $commonRates->where('common_rate_type', 'per_km')->isNotEmpty();
    }

    /**
     * Get minimum duration from slabs
     */
    private function getMinDuration($slabs): array
    {
        $minDays = $slabs->where('min_days', '>', 0)->min('min_days') ?? 0;
        $minHours = $slabs->where('min_hours', '>', 0)->min('min_hours') ?? 0;

        return ['days' => $minDays, 'hours' => $minHours];
    }

    /**
     * Get maximum duration from slabs
     */
    private function getMaxDuration($slabs): array
    {
        $maxDays = $slabs->where('max_days', '>', 0)->max('max_days') ?? 0;
        $maxHours = $slabs->where('max_hours', '>', 0)->max('max_hours') ?? 0;

        return ['days' => $maxDays, 'hours' => $maxHours];
    }

    /**
     * Get duration range for display
     */
    private function getServiceDurationRange($slabs): string
    {
        if ($slabs->isEmpty()) {
            return 'Flexible';
        }

        $minDuration = $this->getMinDuration($slabs);
        $maxDuration = $this->getMaxDuration($slabs);

        if ($minDuration['days'] > 0 && $maxDuration['days'] > 0) {
            return "{$minDuration['days']} - {$maxDuration['days']} days";
        } elseif ($minDuration['hours'] > 0 && $maxDuration['hours'] > 0) {
            return "{$minDuration['hours']} - {$maxDuration['hours']} hours";
        }

        return 'Variable duration';
    }

    /**
     * Get service features based on service code
     */
    private function getServiceFeatures(string $serviceCode): array
    {
        $featureMapping = [
            'airport_drop' => ['Professional Driver', 'Flight Tracking', 'Meet & Greet', 'Fixed Pricing'],
            'airport_pickup' => ['Professional Driver', 'Flight Monitoring', 'Free Waiting', 'Meet & Greet'],
            'transfers' => ['Flexible Timing', 'Multiple Stops', 'Return Option', 'Real-time Tracking'],
            'chauffeur_driven' => ['Professional Driver', 'Unlimited KM Options', 'Multi-day Tours', 'Flexible Itinerary'],
            'self_driven' => ['Self-Drive Freedom', 'Insurance Included', 'Flexible Duration', 'Various Vehicle Types'],
            'wedding_hire' => ['Luxury Vehicles', 'Decoration Service', 'Professional Driver', 'Special Occasion Rates'],
            'corporate' => ['Corporate Rates', 'Dedicated Service', 'Monthly Contracts', 'Professional Drivers'],
            'corporate_self' => ['Corporate Rates', 'Self-Drive Fleet', 'Flexible Contracts', 'Insurance Coverage'],
            'break_down_service' => ['24/7 Availability', 'Emergency Response', 'Professional Recovery', 'Insurance Support']
        ];

        return $featureMapping[$serviceCode] ?? ['Professional Service', 'Reliable Transport', 'Competitive Pricing'];
    }

    /**
     * Clear service configuration cache
     */
    public function clearCache(): void
    {
        $cacheKeys = [
            'service_types_enhanced',
            'service_categories_grouped'
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        // Clear individual service form configs
        $serviceCodes = ServiceType::pluck('code');
        foreach ($serviceCodes as $code) {
            Cache::forget("service_form_config_{$code}");
        }

        Log::info('Dynamic service configuration cache cleared');
    }
}