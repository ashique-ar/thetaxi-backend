<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceType;
use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Pricing\PricingContextPolicyService;

class ServiceFormConfigController extends Controller
{
    public function __construct(private readonly PricingContextPolicyService $pricingContextPolicy)
    {
    }

    private const CANONICAL_SUBMIT_AS_ALIASES = [
        'from_date' => ['from_date', 'pickup_date', 'date'],
        'from_time' => ['from_time', 'pickup_time', 'time'],
        'to_date' => ['to_date', 'dropoff_date', 'return_date'],
        'to_time' => ['to_time', 'dropoff_time', 'return_time'],
        'pickup_location' => ['pickup_location', 'pickup', 'from', 'origin'],
        'dropoff_location' => ['dropoff_location', 'dropoff', 'to', 'destination'],
    ];

    public function publicByCode(string $serviceCode): JsonResponse
    {
        $config = app(\App\Services\DynamicServiceConfigurationService::class)
            ->getServiceFormConfiguration($serviceCode);

        if (!empty($config['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $config['error'],
            ], 404);
        }

        $serviceType = $config['service_type'];

        return response()->json([
            'status' => 'success',
            'data' => [
                'service_type' => [
                    'id' => $serviceType->id,
                    'code' => $serviceType->code,
                    'name' => $serviceType->name,
                    'uses_dropoff_time' => (bool) $serviceType->uses_dropoff_time,
                    'allow_return_trip' => (bool) $serviceType->allow_return_trip,
                ],
                'fields' => $config['fields'] ?? [],
                'field_mappings' => $config['field_mappings'] ?? [],
                'config_source' => $config['config_source'] ?? null,
            ],
        ]);
    }

    /**
     * Get dynamic form configuration for a service type
     */
    public function getFormConfig(string $serviceTypeId): JsonResponse
    {
        try {
            
            $serviceType = ServiceType::find($serviceTypeId);

            if (!$serviceType) {
                \Log::warning('Service type not found: ' . $serviceTypeId);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Service type not found',
                ], 404);
            }

            $serviceType = $this->pricingContextPolicy
                ->effectiveServiceType($serviceType)
                ->load(['packages' => function ($query) {
                    $query->where('is_active', true)->orderBy('sort_order');
                }]);

            $config = $this->buildFormConfig($serviceType);

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
        $fields = $storedConfig['fields'];
        if (empty($fields)) {
            $resolved = app(\App\Services\DynamicServiceConfigurationService::class)
                ->getServiceFormConfiguration($serviceType->code);
            $fields = is_array($resolved['fields'] ?? null) ? $resolved['fields'] : [];
        }

        // Inject airport options into conditional location fields
        $airports = null;
        foreach ($fields as $key => &$field) {
            if (($field['type'] ?? '') !== 'location') continue;
            $locationMode = $field['location_mode'] ?? ($field['location_type'] ?? '');
            if ($locationMode === 'conditional' && !empty($field['conditions'])) {
                foreach ($field['conditions'] as $condValue => &$condConfig) {
                    if (($condConfig['type'] ?? '') === 'airport' && empty($condConfig['options'])) {
                        if ($airports === null) {
                            $airports = $this->getAirports();
                        }
                        $condConfig['options'] = $airports;
                    }
                }
                unset($condConfig);
                // Ensure location_type is set for Angular compatibility
                if (empty($field['location_type'])) {
                    $field['location_type'] = 'conditional';
                }
            } elseif ($locationMode === 'airport') {
                if (empty($field['location_type'])) {
                    $field['location_type'] = 'airport';
                }
            }
        }
        unset($field);

        $resolvedFieldMappings = $this->resolveFieldMappings(
            $fields,
            is_array($storedConfig['field_mappings'] ?? null) ? $storedConfig['field_mappings'] : [],
            $serviceType
        );

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
                'trip_mode' => $this->resolveTripMode($serviceType, $storedConfig, $fields),
                'disable_route_preview' => (bool) ($storedConfig['disable_route_preview'] ?? $storedConfig['route_preview_disabled'] ?? false),
                'disable_distance_estimate' => (bool) ($storedConfig['disable_distance_estimate'] ?? $storedConfig['distance_estimate_disabled'] ?? false),
            ],
            'fields' => $fields,
            'field_mappings' => $resolvedFieldMappings,
            'packages' => $this->getPackagesConfig($serviceType),
            'location_restrictions' => $this->getLocationRestrictions($serviceType),
            'validation_rules' => $this->getValidationRules($serviceType),
        ];

        // Include airports at top level for Angular portal convenience
        if ($airports === null) {
            $airports = $this->getAirports();
        }
        if (!empty($airports)) {
            $config['airports'] = $airports;
        }

        return $config;
    }

    private function resolveTripMode(ServiceType $serviceType, array $storedConfig, array $fields): string
    {
        $mode = $storedConfig['trip_mode']
            ?? $storedConfig['booking_mode']
            ?? $storedConfig['service_mode']
            ?? null;

        if (is_string($mode) && trim($mode) !== '') {
            return trim($mode);
        }

        $hasRequiredPackage = isset($fields['service_package_id'])
            && (bool) ($fields['service_package_id']['required'] ?? false);
        $hasRequiredPickup = isset($fields['pickup_location'])
            && (bool) ($fields['pickup_location']['required'] ?? false);
        $dropoffMissingOrOptional = !isset($fields['dropoff_location'])
            || !(bool) ($fields['dropoff_location']['required'] ?? false);

        if ($hasRequiredPackage && $hasRequiredPickup && $dropoffMissingOrOptional) {
            return 'open_package';
        }

        return 'fixed_route';
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
        $metaKeys = [
            'trip_mode',
            'booking_mode',
            'service_mode',
            'disable_route_preview',
            'route_preview_disabled',
            'disable_distance_estimate',
            'distance_estimate_disabled',
        ];

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
        foreach ($metaKeys as $metaKey) {
            if (isset($fieldsCandidate[$metaKey])) {
                unset($fieldsCandidate[$metaKey]);
            }
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
     * Resolve stable canonical field mappings with this precedence:
     * 1) explicitly provided/stored mapping (if points to existing field)
     * 2) derived mapping from fields + submit_as aliases
     * 3) default mapping (only when no fields are configured)
     */
    private function resolveFieldMappings(array $fields, array $storedMappings, ServiceType $serviceType): array
    {
        $derivedMappings = $this->deriveFieldMappingsFromFields($fields);
        $defaultMappings = $this->getDefaultFieldMappings($serviceType);
        $hasConfiguredFields = !empty($fields);

        $mappingShape = [
            'dates' => ['from_date', 'from_time', 'to_date', 'to_time'],
            'locations' => ['pickup_location', 'dropoff_location'],
        ];

        $resolved = [
            'dates' => [],
            'locations' => [],
        ];

        foreach ($mappingShape as $group => $keys) {
            foreach ($keys as $canonicalKey) {
                $value = $this->resolveMappedFieldName(
                    $fields,
                    $storedMappings[$group][$canonicalKey] ?? null,
                    $derivedMappings[$group][$canonicalKey] ?? null,
                    $hasConfiguredFields ? null : ($defaultMappings[$group][$canonicalKey] ?? null)
                );

                if ($value !== null) {
                    $resolved[$group][$canonicalKey] = $value;
                }
            }
        }

        return $resolved;
    }

    private function deriveFieldMappingsFromFields(array $fields): array
    {
        $derived = [
            'dates' => [],
            'locations' => [],
        ];

        $mappingShape = [
            'dates' => ['from_date', 'from_time', 'to_date', 'to_time'],
            'locations' => ['pickup_location', 'dropoff_location'],
        ];

        foreach ($mappingShape as $group => $keys) {
            foreach ($keys as $canonicalKey) {
                $fieldName = $this->findCanonicalFieldInConfig($fields, $canonicalKey);
                if ($fieldName !== null) {
                    $derived[$group][$canonicalKey] = $fieldName;
                }
            }
        }

        return $derived;
    }

    private function findCanonicalFieldInConfig(array $fields, string $canonicalKey): ?string
    {
        $aliases = self::CANONICAL_SUBMIT_AS_ALIASES[$canonicalKey] ?? [$canonicalKey];
        $normalizedAliases = array_map(fn($value) => strtolower(trim((string) $value)), $aliases);
        $expectedTypes = $this->getExpectedFieldTypes($canonicalKey);

        // 1) submit_as exact canonical + compatible type
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!$this->isFieldTypeCompatible($fieldConfig, $expectedTypes)) {
                continue;
            }

            $submitAs = strtolower((string) ($this->normalizeMappingValue($fieldConfig['submit_as'] ?? null) ?? ''));
            if ($submitAs === strtolower($canonicalKey)) {
                return $fieldName;
            }
        }

        // 2) submit_as alias + compatible type
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!$this->isFieldTypeCompatible($fieldConfig, $expectedTypes)) {
                continue;
            }

            $submitAs = strtolower((string) ($this->normalizeMappingValue($fieldConfig['submit_as'] ?? null) ?? ''));
            if ($submitAs !== '' && in_array($submitAs, $normalizedAliases, true)) {
                return $fieldName;
            }
        }

        // 3) field name alias + compatible type
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!$this->isFieldTypeCompatible($fieldConfig, $expectedTypes)) {
                continue;
            }

            if (in_array(strtolower($fieldName), $normalizedAliases, true)) {
                return $fieldName;
            }
        }

        return null;
    }

    private function resolveMappedFieldName(
        array $fields,
        $preferred,
        $derived,
        $fallback
    ): ?string {
        $candidates = [$preferred, $derived, $fallback];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeMappingValue($candidate);
            if ($normalized === null) {
                continue;
            }

            if (empty($fields) || array_key_exists($normalized, $fields)) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeMappingValue($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function getExpectedFieldTypes(string $canonicalKey): array
    {
        if (in_array($canonicalKey, ['from_date', 'to_date'], true)) {
            return ['date', 'datetime'];
        }
        if (in_array($canonicalKey, ['from_time', 'to_time'], true)) {
            return ['time', 'datetime'];
        }
        if (in_array($canonicalKey, ['pickup_location', 'dropoff_location'], true)) {
            return ['location'];
        }
        return [];
    }

    private function isFieldTypeCompatible($fieldConfig, array $expectedTypes): bool
    {
        if (empty($expectedTypes)) {
            return true;
        }
        if (!is_array($fieldConfig)) {
            return false;
        }

        $fieldType = strtolower((string) ($fieldConfig['type'] ?? ''));
        return in_array($fieldType, $expectedTypes, true);
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
                'default_duration_minutes' => $package->default_duration_minutes,
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

            $nestedFormConfig = is_array($payload['form_config'] ?? null) ? $payload['form_config'] : [];
            foreach (['trip_mode', 'booking_mode', 'service_mode', 'disable_route_preview', 'route_preview_disabled', 'disable_distance_estimate', 'distance_estimate_disabled'] as $metaKey) {
                if (!array_key_exists($metaKey, $payload) && array_key_exists($metaKey, $nestedFormConfig)) {
                    $payload[$metaKey] = $payload['form_config'][$metaKey];
                }
                if (isset($payload['form_config']) && is_array($payload['form_config']) && array_key_exists($metaKey, $payload['form_config'])) {
                    unset($payload['form_config'][$metaKey]);
                }
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
                'trip_mode' => 'nullable|string|in:fixed_route,open_package',
                'disable_route_preview' => 'boolean',
                'disable_distance_estimate' => 'boolean',
                'form_config' => 'nullable|array',
                'form_config.*.type' => 'sometimes|required|string',
                'form_config.*.label' => 'sometimes|required|string',
                'form_config.*.required' => 'boolean',
                'form_config.*.order' => 'integer',
                'form_config.*.width' => 'nullable|in:full,half,third,auto',
                'form_config.*.tablet_width' => 'nullable|in:full,half,third,auto',
                'form_config.*.mobile_width' => 'nullable|in:full,half,third,auto',
                'form_config.*.alignment' => 'nullable|in:left,center,right',
                'form_config.*.row' => 'nullable|integer',
                'form_config.*.placeholder' => 'nullable|string',
                'form_config.*.placeholder_examples' => 'nullable|array|max:10',
                'form_config.*.placeholder_examples.*' => 'nullable|string|max:120',
                'form_config.*.hint' => 'nullable|string',
                'form_config.*.submit_as' => 'nullable|string|max:50',
                'form_config.*.location_mode' => 'nullable|string|in:autocomplete,airport,predefined_or_custom,conditional',
                'form_config.*.sync_from' => 'nullable|string|max:50',
                'form_config.*.visible_when' => 'nullable|array',
                'form_config.*.default' => 'nullable|string',
                'form_config.*.default_lat' => 'nullable|string',
                'form_config.*.default_lng' => 'nullable|string',
                'form_config.*.location_type' => 'nullable|string|in:default,airport,conditional',
                'form_config.*.condition_field' => 'nullable|string',
                'form_config.*.conditions' => 'nullable|array',
                'form_config.*.validation' => 'nullable|array',
                'form_config.*.validation.min' => 'nullable|numeric',
                'form_config.*.validation.max' => 'nullable|numeric',
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
            $this->pricingContextPolicy->assertServiceTypeIsWritable($serviceType);

            $formConfig = is_array($validated['form_config'] ?? null) ? $validated['form_config'] : [];

            // Clean up user-entered mapping values before saving.
            foreach ($formConfig as $fieldName => $fieldConfig) {
                if (!is_array($fieldConfig)) {
                    continue;
                }
                if (array_key_exists('submit_as', $fieldConfig)) {
                    $formConfig[$fieldName]['submit_as'] = $this->normalizeMappingValue($fieldConfig['submit_as']);
                }
                if (array_key_exists('sync_from', $fieldConfig)) {
                    $formConfig[$fieldName]['sync_from'] = $this->normalizeMappingValue($fieldConfig['sync_from']);
                }
                if (!empty($fieldConfig['conditions']) && is_array($fieldConfig['conditions'])) {
                    $formConfig[$fieldName]['location_type'] = 'conditional';
                    $formConfig[$fieldName]['location_mode'] = 'conditional';
                    foreach ($fieldConfig['conditions'] as $conditionValue => $conditionConfig) {
                        if (!is_array($conditionConfig)) {
                            continue;
                        }
                        // Airport choices belong to the Airports master data and
                        // must not be duplicated as stale snapshots in form JSON.
                        unset($conditionConfig['options']);
                        $formConfig[$fieldName]['conditions'][$conditionValue] = $conditionConfig;
                    }
                }
            }

            $storedMappings = is_array($validated['field_mappings'] ?? null) ? $validated['field_mappings'] : [];
            $resolvedFieldMappings = $this->resolveFieldMappings($formConfig, $storedMappings, $serviceType);

            if (!empty($resolvedFieldMappings['dates']) || !empty($resolvedFieldMappings['locations'])) {
                $formConfig['field_mappings'] = $resolvedFieldMappings;
            }

            if (!empty($validated['trip_mode'])) {
                $formConfig['trip_mode'] = $validated['trip_mode'];
            }
            if (array_key_exists('disable_route_preview', $validated)) {
                $formConfig['disable_route_preview'] = (bool) $validated['disable_route_preview'];
            }
            if (array_key_exists('disable_distance_estimate', $validated)) {
                $formConfig['disable_distance_estimate'] = (bool) $validated['disable_distance_estimate'];
            }
             
            $serviceType->update([
                'uses_dropoff_time' => $validated['uses_dropoff_time'] ?? true,
                'allow_return_trip' => $validated['allow_return_trip'] ?? false,
                'allow_multiple_pickup_locations' => $validated['allow_multiple_pickup_locations'] ?? false,
                'allow_multiple_dropoff_locations' => $validated['allow_multiple_dropoff_locations'] ?? false,
                'form_config' => !empty($formConfig) ? $formConfig : null,
            ]);
            app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();

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
