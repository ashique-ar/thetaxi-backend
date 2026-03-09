<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceType;
use App\Models\Airport;
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
            \Log::info('Loading form config for service type: ' . $serviceTypeId);
            
            $serviceType = ServiceType::with(['packages' => function ($query) {
                $query->where('is_active', true)->orderBy('sort_order');
            }])->find($serviceTypeId);

            if (!$serviceType) {
                \Log::warning('Service type not found: ' . $serviceTypeId);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Service type not found',
                ], 404);
            }

            \Log::info('Building form config for: ' . $serviceType->name);
            $config = $this->buildFormConfig($serviceType);
            \Log::info('Form config built successfully');

            return response()->json([
                'status' => 'success',
                'data' => $config,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to retrieve form configuration: ' . $e->getMessage(), [
                'service_type_id' => $serviceTypeId,
                'trace' => $e->getTraceAsString()
            ]);
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
        $storedConfig = $this->extractStoredFormConfig($serviceType->form_config);

        $config = [
            'service_type' => [
                'id' => $serviceType->id,
                'code' => $serviceType->code,
                'name' => $serviceType->name,
                'type' => $serviceType->type,
                'pricing_mode' => $serviceType->pricing_mode,
                'uses_dropoff_time' => (bool) $serviceType->uses_dropoff_time,
                'allow_return_trip' => (bool) $serviceType->allow_return_trip,
                'allow_multiple_pickup_locations' => (bool) ($serviceType->allow_multiple_pickup_locations ?? false),
                'allow_multiple_dropoff_locations' => (bool) ($serviceType->allow_multiple_dropoff_locations ?? false),
                'frontend_category' => $serviceType->frontend_category,
                'minimum_km' => $serviceType->minimum_km,
            ],
            'fields' => $storedConfig['fields'] ?: $this->getFieldsConfig($serviceType),
            'field_mappings' => $storedConfig['field_mappings'] ?: $this->getDefaultFieldMappings($serviceType),
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
        // Check if custom form_config exists in database
        if ($serviceType->form_config) {
            $storedConfig = $this->extractStoredFormConfig($serviceType->form_config);
            if (!empty($storedConfig['fields'])) {
                return $storedConfig['fields'];
            }
        }

        // Otherwise, build default configuration
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
                'width' => 'full', // full, half, third
                'alignment' => 'left', // left, center, right
                'row' => 1,
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
                'width' => 'half',
                'alignment' => 'left',
                'row' => 2,
                'location_type' => 'conditional',
                'condition_field' => 'transfer_type',
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
                'width' => 'half',
                'alignment' => 'left',
                'row' => 2,
                'location_type' => 'conditional',
                'condition_field' => 'transfer_type',
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
                'width' => 'half',
                'alignment' => 'left',
                'row' => 3,
            ];

            $baseFields['time'] = [
                'type' => 'time',
                'label' => 'Transfer Time',
                'required' => true,
                'order' => 4,
                'width' => 'half',
                'alignment' => 'left',
                'row' => 3,
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
     * Split stored service form config into fields + field mappings.
     */
    private function extractStoredFormConfig(?array $storedConfig): array
    {
        if (empty($storedConfig) || !is_array($storedConfig)) {
            return [
                'fields' => [],
                'field_mappings' => [],
            ];
        }

        $fieldMappings = [];

        if (isset($storedConfig['field_mappings']) && is_array($storedConfig['field_mappings'])) {
            $fieldMappings = $storedConfig['field_mappings'];
        }

        // Support wrapped storage shape: { fields: {...}, field_mappings: {...} }
        $fieldsCandidate = $storedConfig;
        if (isset($storedConfig['fields']) && is_array($storedConfig['fields'])) {
            $fieldsCandidate = $storedConfig['fields'];
        }

        if (isset($fieldsCandidate['field_mappings'])) {
            unset($fieldsCandidate['field_mappings']);
        }

        // Keep only actual field definitions (defensive guard against metadata keys).
        $fields = array_filter($fieldsCandidate, function ($fieldConfig) {
            return is_array($fieldConfig)
                && isset($fieldConfig['type'])
                && isset($fieldConfig['label']);
        });

        return [
            'fields' => $fields,
            'field_mappings' => $fieldMappings,
        ];
    }

    /**
     * Provide default field mappings for dynamic trip editor/public booking compatibility.
     */
    private function getDefaultFieldMappings(ServiceType $serviceType): array
    {
        if ($serviceType->code === 'airport_transfers') {
            return [
                'dates' => [
                    'from_date' => 'date',
                    'from_time' => 'time',
                    'to_date' => 'date',
                    'to_time' => 'time',
                ],
                'locations' => [
                    'pickup_location' => 'pickup_location',
                    'dropoff_location' => 'dropoff_location',
                ],
            ];
        }

        return [
            'dates' => [
                'from_date' => 'pickup_date',
                'from_time' => 'pickup_time',
                'to_date' => 'dropoff_date',
                'to_time' => 'dropoff_time',
            ],
            'locations' => [
                'pickup_location' => 'pickup_location',
                'dropoff_location' => 'dropoff_location',
            ],
        ];
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
     * Get list of airports from database
     */
    private function getAirports(): array
    {
        try {
            return Airport::active()
                ->ordered()
                ->get()
                ->map(function ($airport) {
                    return [
                        'id' => $airport->id,
                        'name' => $airport->name,
                        'code' => $airport->code,
                        'city' => $airport->city,
                        'latitude' => (float) $airport->latitude,
                        'longitude' => (float) $airport->longitude,
                        'is_default' => $airport->is_default,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            \Log::error('Error loading airports for form config: ' . $e->getMessage());
            // Return empty array if airports can't be loaded
            return [];
        }
    }

    /**
     * Update form configuration for a service type
     */
    public function updateFormConfig(Request $request, string $serviceTypeId): JsonResponse
    {
        try {
            $payload = $request->all();

            // Backward compatibility: support field_mappings nested under form_config.
            if (
                empty($payload['field_mappings'])
                && isset($payload['form_config']['field_mappings'])
                && is_array($payload['form_config']['field_mappings'])
            ) {
                $payload['field_mappings'] = $payload['form_config']['field_mappings'];
            }

            // Support wrapped structure: { form_config: { fields: {...}, field_mappings: {...} } }
            if (isset($payload['form_config']['fields']) && is_array($payload['form_config']['fields'])) {
                $payload['form_config'] = $payload['form_config']['fields'];
            }

            if (isset($payload['form_config']['field_mappings'])) {
                unset($payload['form_config']['field_mappings']);
            }

            $request->replace($payload);

            $validated = $request->validate([
                'uses_dropoff_time' => 'boolean',
                'allow_return_trip' => 'boolean',
                'allow_multiple_pickup_locations' => 'boolean',
                'allow_multiple_dropoff_locations' => 'boolean',
                'form_config' => 'nullable|array',
                'form_config.*.type' => 'sometimes|required|string',
                'form_config.*.label' => 'sometimes|required|string',
                'form_config.*.required' => 'boolean',
                'form_config.*.order' => 'integer',
                'form_config.*.width' => 'nullable|in:full,half,third',
                'form_config.*.alignment' => 'nullable|in:left,center,right',
                'form_config.*.row' => 'nullable|integer',
                'form_config.*.placeholder' => 'nullable|string',
                'form_config.*.hint' => 'nullable|string',
                'form_config.*.location_type' => 'nullable|string|in:default,airport,conditional',
                'form_config.*.condition_field' => 'nullable|string',
                'form_config.*.conditions' => 'nullable|array',
                'form_config.*.options' => 'nullable|array',
                'form_config.*.options.*.value' => 'required|string',
                'form_config.*.options.*.label' => 'required|string',
                'field_mappings' => 'nullable|array',
                'field_mappings.dates' => 'nullable|array',
                'field_mappings.dates.from_date' => 'nullable|string',
                'field_mappings.dates.from_time' => 'nullable|string',
                'field_mappings.dates.to_date' => 'nullable|string',
                'field_mappings.dates.to_time' => 'nullable|string',
                'field_mappings.locations' => 'nullable|array',
                'field_mappings.locations.pickup_location' => 'nullable|string',
                'field_mappings.locations.dropoff_location' => 'nullable|string',
            ]);

            $serviceType = ServiceType::findOrFail($serviceTypeId);

            $formConfig = $validated['form_config'] ?? null;
            if (!empty($validated['field_mappings'])) {
                $formConfig = is_array($formConfig) ? $formConfig : [];
                $formConfig['field_mappings'] = $validated['field_mappings'];
            }
            
            $serviceType->update([
                'uses_dropoff_time' => $validated['uses_dropoff_time'] ?? true,
                'allow_return_trip' => $validated['allow_return_trip'] ?? false,
                'allow_multiple_pickup_locations' => $validated['allow_multiple_pickup_locations'] ?? false,
                'allow_multiple_dropoff_locations' => $validated['allow_multiple_dropoff_locations'] ?? false,
                'form_config' => $formConfig,
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
