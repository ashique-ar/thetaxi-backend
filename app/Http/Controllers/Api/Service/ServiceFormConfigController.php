<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceType;
use App\Models\Service\ServicePackage;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ServiceFormConfigController extends Controller
{
    /**
     * Get dynamic form configuration for a service type
     */
    public function getFormConfig(string $serviceTypeId): JsonResponse
    {
        try {
            $serviceType = ServiceType::with(['packages' => function ($query) {
                $query->where('is_active', true)->orderBy('sort_order');
            }])->find($serviceTypeId);

            if (!$serviceType) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Service type not found',
                ], 404);
            }

            $config = $this->buildFormConfig($serviceType);

            return response()->json([
                'status' => 'success',
                'data' => $config,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve form configuration',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build dynamic form configuration based on service type
     */
    private function buildFormConfig(ServiceType $serviceType): array
    {
        $config = [
            'service_type' => [
                'id' => $serviceType->id,
                'code' => $serviceType->code,
                'name' => $serviceType->name,
                'type' => $serviceType->type,
                'pricing_mode' => $serviceType->pricing_mode,
                'uses_dropoff_time' => (bool) $serviceType->uses_dropoff_time,
                'allow_return_trip' => (bool) $serviceType->allow_return_trip,
                'frontend_category' => $serviceType->frontend_category,
                'minimum_km' => $serviceType->minimum_km,
            ],
            'fields' => $this->getFieldsConfig($serviceType),
            'packages' => $this->getPackagesConfig($serviceType),
            'location_restrictions' => $this->getLocationRestrictions($serviceType),
            'validation_rules' => $this->getValidationRules($serviceType),
        ];

        return $config;
    }

    /**
     * Get field configuration based on service type
     */
    private function getFieldsConfig(ServiceType $serviceType): array
    {
        $baseFields = [];

        // Airport Transfer specific fields
        // Note: airport_drop and airport_pickup are handled via transfer_type field
        if ($serviceType->code === 'airport_transfers') {
            $airports = $this->getAirports();
            
            // Transfer type selector (from-airport / to-airport)
            $baseFields['transfer_type'] = [
                'type' => 'radio',
                'label' => 'Transfer Type',
                'required' => true,
                'order' => 0,
                'options' => [
                    ['value' => 'from-airport', 'label' => 'From Airport'],
                    ['value' => 'to-airport', 'label' => 'To Airport'],
                ],
                'default' => 'to-airport',
                'help_text' => 'Select whether you are traveling from or to the airport',
            ];

            // Pickup location - conditional based on transfer type
            $baseFields['pickup_location'] = [
                'type' => 'location',
                'label' => 'Pickup Location',
                'required' => true,
                'order' => 1,
                'location_type' => 'conditional',
                'conditions' => [
                    'from-airport' => ['type' => 'airport', 'options' => $airports],
                    'to-airport' => ['type' => 'any'],
                ],
                'placeholder' => 'Enter pickup location',
            ];

            // Dropoff location - conditional based on transfer type
            $baseFields['dropoff_location'] = [
                'type' => 'location',
                'label' => 'Drop-off Location',
                'required' => true,
                'order' => 2,
                'location_type' => 'conditional',
                'conditions' => [
                    'from-airport' => ['type' => 'any'],
                    'to-airport' => ['type' => 'airport', 'options' => $airports],
                ],
                'placeholder' => 'Enter drop-off location',
            ];

            // Date and time (single for airport transfers)
            $baseFields['date'] = [
                'type' => 'date',
                'label' => 'Transfer Date',
                'required' => true,
                'order' => 3,
            ];

            $baseFields['time'] = [
                'type' => 'time',
                'label' => 'Transfer Time',
                'required' => true,
                'order' => 4,
                'default' => '09:00',
            ];
        } else {
            // Standard fields for other services
            $baseFields['pickup_location'] = [
                'type' => 'location',
                'label' => 'Pickup Location',
                'required' => true,
                'order' => 1,
                'placeholder' => 'Enter pickup location',
            ];

            $baseFields['pickup_date'] = [
                'type' => 'date',
                'label' => 'Pickup Date',
                'required' => true,
                'order' => 2,
            ];

            $baseFields['pickup_time'] = [
                'type' => 'time',
                'label' => 'Pickup Time',
                'required' => true,
                'order' => 3,
                'default' => '09:00',
            ];

            // Dropoff location
            $baseFields['dropoff_location'] = [
                'type' => 'location',
                'label' => 'Drop-off Location',
                'required' => true,
                'order' => 4,
                'placeholder' => 'Enter drop-off location',
            ];

            // Dropoff date/time fields (if service uses them)
            if ($serviceType->uses_dropoff_time ?? true) {
                $baseFields['dropoff_date'] = [
                    'type' => 'date',
                    'label' => 'Drop-off Date',
                    'required' => true,
                    'order' => 5,
                ];

                $baseFields['dropoff_time'] = [
                    'type' => 'time',
                    'label' => 'Drop-off Time',
                    'required' => true,
                    'order' => 6,
                    'default' => '18:00',
                ];
            }
        }

        // Service packages field (if packages exist)
        if ($serviceType->packages()->where('is_active', true)->exists()) {
            $baseFields['service_package_id'] = [
                'type' => 'select',
                'label' => 'Service Package',
                'required' => false,
                'order' => 7,
                'options' => [], // Will be populated from packages config
                'help_text' => 'Select a package for better rates',
            ];
        }

        // Return trip fields (if service allows returns)
        if ($serviceType->allow_return_trip ?? false) {
            $baseFields['is_return_trip'] = [
                'type' => 'checkbox',
                'label' => 'Need Return Trip?',
                'required' => false,
                'order' => 8,
                'help_text' => 'Check if you need a return journey',
            ];

            $baseFields['return_date'] = [
                'type' => 'date',
                'label' => 'Return Date',
                'required' => false,
                'order' => 9,
                'conditional' => 'is_return_trip',
            ];

            $baseFields['return_time'] = [
                'type' => 'time',
                'label' => 'Return Time',
                'required' => false,
                'order' => 10,
                'conditional' => 'is_return_trip',
                'default' => '18:00',
            ];
        }

        return $baseFields;
    }

    /**
     * Get packages configuration
     */
    private function getPackagesConfig(ServiceType $serviceType): array
    {
        return $serviceType->packages->map(function ($package) {
            return [
                'id' => $package->id,
                'name' => $package->name,
                'code' => $package->code,
                'description' => $package->description,
                'max_km_per_day' => $package->max_km_per_day,
                'max_km_per_package' => $package->max_km_per_package,
                'price_multiplier' => $package->price_multiplier,
                'rate_type' => $package->rate_type,
                'default_duration_hours' => $package->default_duration_hours,
                'supports_return' => $package->supportsReturnTrip(),
            ];
        })->toArray();
    }

    /**
     * Get location restrictions for the service type
     */
    private function getLocationRestrictions(ServiceType $serviceType): array
    {
        $restrictions = [
            'pickup' => ['type' => 'any'],
            'dropoff' => ['type' => 'any'],
        ];

        // Airport services have specific restrictions
        if (in_array($serviceType->code, ['airport_transfers', 'airport_drop', 'airport_pickup'])) {
            $restrictions['airports'] = $this->getAirports();
        }

        return $restrictions;
    }

    /**
     * Get validation rules for the service type
     */
    private function getValidationRules(ServiceType $serviceType): array
    {
        $rules = [];

        // Airport transfers have different field names
        if ($serviceType->code === 'airport_transfers') {
            $rules = [
                'transfer_type' => 'required|in:from-airport,to-airport',
                'pickup_location' => 'required|array',
                'pickup_location.address' => 'required|string',
                'pickup_location.latitude' => 'required|numeric',
                'pickup_location.longitude' => 'required|numeric',
                'dropoff_location' => 'required|array',
                'dropoff_location.address' => 'required|string',
                'dropoff_location.latitude' => 'required|numeric',
                'dropoff_location.longitude' => 'required|numeric',
                'date' => 'required|date|after_or_equal:today',
                'time' => 'required|date_format:H:i',
            ];
        } else {
            // Standard validation for other services
            $rules = [
                'pickup_location' => 'required|array',
                'pickup_location.address' => 'required|string',
                'pickup_location.latitude' => 'required|numeric',
                'pickup_location.longitude' => 'required|numeric',
                'pickup_date' => 'required|date|after_or_equal:today',
                'pickup_time' => 'required|date_format:H:i',
                'dropoff_location' => 'required|array',
                'dropoff_location.address' => 'required|string',
                'dropoff_location.latitude' => 'required|numeric',
                'dropoff_location.longitude' => 'required|numeric',
            ];

            if ($serviceType->uses_dropoff_time ?? true) {
                $rules['dropoff_date'] = 'required|date|after_or_equal:pickup_date';
                $rules['dropoff_time'] = 'required|date_format:H:i';
            }
        }

        // Return trip validation
        if ($serviceType->allow_return_trip ?? false) {
            $rules['is_return_trip'] = 'nullable|boolean';
            
            if ($serviceType->code === 'airport_transfers') {
                $rules['return_date'] = 'required_if:is_return_trip,true|date|after:date';
                $rules['return_time'] = 'required_if:is_return_trip,true|date_format:H:i';
            } else {
                $rules['return_date'] = 'required_if:is_return_trip,true|date|after:pickup_date';
                $rules['return_time'] = 'required_if:is_return_trip,true|date_format:H:i';
            }
        }

        return $rules;
    }

    /**
     * Get list of airports in Sri Lanka
     */
    private function getAirports(): array
    {
        return [
            [
                'id' => 'BIA',
                'name' => 'Bandaranaike International Airport (BIA)',
                'code' => 'CMB',
                'city' => 'Colombo',
                'latitude' => 7.180756,
                'longitude' => 79.884117,
                'is_default' => true,
            ],
            [
                'id' => 'RML',
                'name' => 'Ratmalana Airport',
                'code' => 'RML',
                'city' => 'Colombo',
                'latitude' => 6.821986,
                'longitude' => 79.886208,
                'is_default' => false,
            ],
            [
                'id' => 'HRI',
                'name' => 'Mattala Rajapaksa International Airport',
                'code' => 'HRI',
                'city' => 'Hambantota',
                'latitude' => 6.284467,
                'longitude' => 81.124128,
                'is_default' => false,
            ],
            [
                'id' => 'JAF',
                'name' => 'Jaffna International Airport',
                'code' => 'JAF',
                'city' => 'Jaffna',
                'latitude' => 9.792333,
                'longitude' => 80.070097,
                'is_default' => false,
            ],
        ];
    }

    /**
     * Update form configuration for a service type
     */
    public function updateFormConfig(Request $request, string $serviceTypeId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'uses_dropoff_time' => 'boolean',
                'allow_return_trip' => 'boolean',
                'form_config' => 'nullable|array',
            ]);

            $serviceType = ServiceType::findOrFail($serviceTypeId);
            
            $serviceType->update([
                'uses_dropoff_time' => $validated['uses_dropoff_time'] ?? true,
                'allow_return_trip' => $validated['allow_return_trip'] ?? false,
                'form_config' => $validated['form_config'] ?? null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Form configuration updated successfully',
                'data' => [
                    'service_type' => $serviceType,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update form configuration',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
