<?php

namespace App\Http\Requests;

use App\Models\Airport;
use App\Models\PredefinedLocation;
use App\Models\Service\ServiceType;
use App\Services\WebsiteSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class BookingSearchRequest extends FormRequest
{


    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        $serviceType = $data['service_type'] ?? null;
        if (!empty($serviceType)) {
            try {
                [$configuredFields] = $this->resolveServiceFormConfig((string) $serviceType);
                foreach ($configuredFields as $fieldName => $config) {
                    if (!is_array($config)) {
                        continue;
                    }

                    $submitAs = (string) ($config['submit_as'] ?? $fieldName);
                    $effectiveConfig = $this->resolveConditionalLocationDefaults($config, $data);
                    $default = $this->resolveConfiguredDefault($effectiveConfig['default'] ?? null, $effectiveConfig['type'] ?? null);
                    $submittedValueWasBlank = $this->isBlankSearchValue($data[$submitAs] ?? null);
                    if ($submitAs !== '' && $submittedValueWasBlank && $default !== null) {
                        $data[$submitAs] = $default;
                    }

                    if (($config['type'] ?? null) === 'location') {
                        $addressUsesConfiguredDefault = $default !== null
                            && trim((string) ($data[$submitAs] ?? '')) === trim((string) $default);
                        foreach (['lat', 'lng'] as $coordinate) {
                            $coordinateKey = "{$submitAs}_{$coordinate}";
                            $configuredCoordinate = $effectiveConfig['default_' . $coordinate] ?? null;
                            if (
                                $addressUsesConfiguredDefault
                                && !$this->isBlankSearchValue($configuredCoordinate)
                            ) {
                                // The configured location is one identity: label and coordinates.
                                // Hidden inputs can retain stale coordinates after validation or
                                // browser restoration, so an exact default label must always use
                                // its authoritative configured coordinates.
                                $data[$coordinateKey] = (string) $configuredCoordinate;
                            }
                        }

                        // Dynamic forms may submit as pickup_location/dropoff_location
                        // while legacy pricing consumes pickup/dropoff. Canonicalize the
                        // complete identity on the server so pricing never depends on JS
                        // creating compatibility aliases in a particular DOM order.
                        $semanticName = strtolower($fieldName . ' ' . $submitAs);
                        $canonicalPrefix = str_contains($semanticName, 'pickup')
                            ? 'pickup'
                            : (str_contains($semanticName, 'dropoff') ? 'dropoff' : null);
                        if ($canonicalPrefix && $submitAs !== '') {
                            if (!$this->isBlankSearchValue($data[$submitAs] ?? null)) {
                                $data[$canonicalPrefix] = $data[$submitAs];
                            }
                            foreach (['lat', 'lng'] as $coordinate) {
                                $sourceKey = "{$submitAs}_{$coordinate}";
                                if (!$this->isBlankSearchValue($data[$sourceKey] ?? null)) {
                                    $data["{$canonicalPrefix}_{$coordinate}"] = $data[$sourceKey];
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to apply configured booking search defaults', [
                    'service_type' => $serviceType,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // Convert DD/MM/YYYY format to Y-m-d for validation and processing
        if (isset($data['from_date']) && $this->isValidDDMMYYYY($data['from_date'])) {
            $data['from_date'] = $this->convertDDMMYYYYToYMD($data['from_date']);
        }

        if (isset($data['to_date']) && $this->isValidDDMMYYYY($data['to_date'])) {
            $data['to_date'] = $this->convertDDMMYYYYToYMD($data['to_date']);
        }

        // Legacy field support for backward compatibility
        if (isset($data['date']) && $this->isValidDDMMYYYY($data['date'])) {
            $data['date'] = $this->convertDDMMYYYYToYMD($data['date']);
        }

        if (isset($data['return_date']) && $this->isValidDDMMYYYY($data['return_date'])) {
            $data['return_date'] = $this->convertDDMMYYYYToYMD($data['return_date']);
        }

        if (isset($data['pickup_date']) && $this->isValidDDMMYYYY($data['pickup_date'])) {
            $data['pickup_date'] = $this->convertDDMMYYYYToYMD($data['pickup_date']);
        }

        if (isset($data['dropoff_date']) && $this->isValidDDMMYYYY($data['dropoff_date'])) {
            $data['dropoff_date'] = $this->convertDDMMYYYYToYMD($data['dropoff_date']);
        }

        $serviceType = $data['service_type'] ?? null;
        $isOpenPackageRequest = $this->isOpenPackageServiceConfig($serviceType, $data);

        // Package selections belong only to service forms that explicitly expose a
        // package selector. A stale query-string value from another booking tab must
        // not be validated or consumed by a service such as point-to-point.
        if ($serviceType) {
            try {
                $resolvedConfig = $this->resolveServiceFormConfig((string) $serviceType);
                $configuredFields = $resolvedConfig[0] ?? [];
                $configuredServiceTypeId = $resolvedConfig[3] ?? null;
                $hasConfiguredPackageSelector = collect($configuredFields)->contains(
                    fn($config) => is_array($config) && ($config['type'] ?? null) === 'package_select'
                );
                $activePackageIds = $hasConfiguredPackageSelector
                    ? DB::table('service_packages')
                        ->where('service_type_id', $configuredServiceTypeId)
                        ->where('is_active', true)
                        ->pluck('id')
                    : collect();

                if (!empty($configuredFields) && $activePackageIds->isEmpty()) {
                    unset($data['package_id'], $data['service_package_id']);
                } elseif ($activePackageIds->count() === 1) {
                    // Match the dynamic form's hidden-input behavior and make the
                    // single available package authoritative even for stale URLs.
                    $onlyPackageId = (string) $activePackageIds->first();
                    $data['package_id'] = $onlyPackageId;
                    $data['service_package_id'] = $onlyPackageId;
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to determine package support for booking search', [
                    'service_type' => $serviceType,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (in_array($serviceType, ['self_drive', 'with_driver'], true)) {
            if (empty($data['pickup_date']) && !empty($data['date'])) {
                $data['pickup_date'] = $data['date'];
            }
            if (empty($data['pickup_time']) && !empty($data['time'])) {
                $data['pickup_time'] = $data['time'];
            }
            if (empty($data['rental_mode'])) {
                $data['rental_mode'] = $serviceType;
            }

            // If a predefined dropoff code is provided, resolve its address/coords
            if (!empty($data['dropoff_predefined'])) {
                try {
                    $predefinedDropoff = \App\Models\PredefinedLocation::where('code', $data['dropoff_predefined'])
                        ->where('is_active', true)
                        ->first();
                    if ($predefinedDropoff) {
                        $data['dropoff'] = $data['dropoff'] ?? ($predefinedDropoff->address ?? $predefinedDropoff->name);
                        $data['dropoff_lat'] = $data['dropoff_lat'] ?? (string) $predefinedDropoff->latitude;
                        $data['dropoff_lng'] = $data['dropoff_lng'] ?? (string) $predefinedDropoff->longitude;
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to resolve predefined dropoff location', [
                        'code' => $data['dropoff_predefined'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Fallback: if dropoff is still empty, copy from pickup
            if (!$isOpenPackageRequest && empty($data['dropoff']) && !empty($data['pickup'])) {
                $data['dropoff'] = $data['pickup'];
                $data['dropoff_lat'] = $data['dropoff_lat'] ?? ($data['pickup_lat'] ?? null);
                $data['dropoff_lng'] = $data['dropoff_lng'] ?? ($data['pickup_lng'] ?? null);
            }
        }

        if ($serviceType === 'wedding_hire') {
            if (empty($data['pickup_date']) && !empty($data['date'])) {
                $data['pickup_date'] = $data['date'];
            }
            if (empty($data['pickup_time']) && !empty($data['time'])) {
                $data['pickup_time'] = $data['time'];
            }
        }

        // For dynamic service types: convert any remaining DD/MM/YYYY date fields
        // This catches custom date field names from form_config that aren't handled above
        foreach ($data as $key => $value) {
            if (is_string($value) && $this->isValidDDMMYYYY($value) && !in_array($key, [
                'from_date', 'to_date', 'date', 'return_date', 'pickup_date', 'dropoff_date'
            ], true)) {
                $data[$key] = $this->convertDDMMYYYYToYMD($value);
            }
        }

        // For any service type: resolve predefined location codes to address/coords
        // This handles dynamic forms that use predefined_or_custom location mode
        $locationPrefixes = ['pickup', 'dropoff'];
        if (!empty($serviceType)) {
            try {
                [$fields] = $this->resolveServiceFormConfig($serviceType);
                foreach ($fields as $fieldName => $config) {
                    if (($config['type'] ?? null) !== 'location') {
                        continue;
                    }

                    $submitAs = $config['submit_as'] ?? $fieldName;
                    if (is_string($submitAs) && $submitAs !== '') {
                        $locationPrefixes[] = $submitAs;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to resolve configured location fields', [
                    'service_type' => $serviceType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach (array_unique($locationPrefixes) as $locPrefix) {
            $predefinedKey = "{$locPrefix}_predefined";
            if (!empty($data[$predefinedKey]) && empty($data[$locPrefix])) {
                try {
                    $predefined = PredefinedLocation::where('code', $data[$predefinedKey])
                        ->where('is_active', true)
                        ->first();
                    if ($predefined) {
                        $data[$locPrefix] = $predefined->address ?? $predefined->name;
                        $data["{$locPrefix}_lat"] = $data["{$locPrefix}_lat"] ?? (string) $predefined->latitude;
                        $data["{$locPrefix}_lng"] = $data["{$locPrefix}_lng"] ?? (string) $predefined->longitude;
                    }
                } catch (\Exception $e) {
                    // Silently continue — formatLocation in controller will also try
                }
            }
        }

        $this->replace($data);
    }

    private function resolveConditionalLocationDefaults(array $config, array $data): array
    {
        if (($config['type'] ?? null) !== 'location' || !is_array($config['conditions'] ?? null)) {
            return $config;
        }

        $conditionField = (string) ($config['condition_field'] ?? 'transfer_type');
        $activeValue = strtolower(str_replace('-', '_', trim((string) ($data[$conditionField] ?? ''))));
        $activeCondition = collect($config['conditions'])->first(
            static fn ($condition, $value) => strtolower(str_replace('-', '_', (string) $value)) === $activeValue
        );

        if (!is_array($activeCondition)) {
            return $config;
        }

        foreach (['default', 'default_lat', 'default_lng'] as $defaultKey) {
            if (array_key_exists($defaultKey, $activeCondition)) {
                $config[$defaultKey] = $activeCondition[$defaultKey];
            }
        }

        if (strtolower((string) ($activeCondition['type'] ?? 'location')) !== 'airport') {
            return $config;
        }

        $airport = Airport::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->first(['name', 'latitude', 'longitude']);

        if (!$airport) {
            return $config;
        }

        $config['default'] = $airport->name;
        $config['default_lat'] = (string) $airport->latitude;
        $config['default_lng'] = (string) $airport->longitude;

        return $config;
    }

    private function isBlankSearchValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function resolveConfiguredDefault(mixed $default, mixed $fieldType): mixed
    {
        if ($this->isBlankSearchValue($default)) {
            return null;
        }

        if (!is_string($default)) {
            return $default;
        }

        $token = strtolower(trim($default));
        if ($fieldType === 'date') {
            if ($token === 'today') {
                return Carbon::today()->toDateString();
            }
            if ($token === 'tomorrow') {
                return Carbon::tomorrow()->toDateString();
            }
            if (preg_match('/^\+(\d+)\s*days?$/', $token, $matches)) {
                return Carbon::today()->addDays((int) $matches[1])->toDateString();
            }
        }

        if ($fieldType === 'time' && in_array($token, ['now', 'current_time'], true)) {
            return Carbon::now()->format('H:i');
        }

        return $default;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return $this->rulesForService((string) $this->input('service_type'));
    }

    /**
     * Resolve the authoritative public-search rules for a service code.
     */
    public function rulesForService(string $serviceType): array
    {
        $this->merge(['service_type' => $serviceType]);
        // Try dynamic rules first — if form_config exists, use it
        $dynamicRules = $this->buildDynamicRules($serviceType);
        if ($dynamicRules !== null) {
            return $dynamicRules;
        }

        // Fallback to legacy hardcoded rules for backward compatibility
        switch ($serviceType) {
            case 'airport_transfers':
                return $this->airportTransferRules();
            case 'point_to_point':
                return $this->dropPickupRules();
            case 'ride_now':
                return $this->rideNowRules();
            case 'day_rental':
                return $this->dayRentalRules();
            case 'self_drive':
                return $this->dayRentalRules('self_drive');
            case 'with_driver':
                return $this->dayRentalRules('with_driver');
            case 'wedding_hire':
                return $this->weddingHireRules();
            case 'custom-tour':
                return $this->customTourRules();
            case 'corporate':
            case 'corporate-transport':
                return $this->corporateTransportRules();
            default:
                return $this->defaultRules();
        }
    }

    /**
     * Build validation rules dynamically from form_config.
     * Returns null if no config found (falls back to legacy rules).
     */
    protected function buildDynamicRules(string $serviceCode): ?array
    {
        $resolvedConfig = $this->resolveServiceFormConfig($serviceCode);
        [$fields, $usesDropoffTime, $allowReturnTrip] = $resolvedConfig;
        $serviceTypeId = $resolvedConfig[3] ?? null;
        $serviceHasActivePackages = $this->serviceHasActivePackages($serviceTypeId);

        // If no fields configured, return null to use legacy rules
        if (empty($fields)) {
            return null;
        }

        $rules = ['service_type' => 'required|string'];
        $activePackageRule = function () use ($serviceTypeId) {
            $rule = Rule::exists('service_packages', 'id')
                ->where(fn($query) => $query->where('is_active', true));

            if (is_string($serviceTypeId) && $serviceTypeId !== '') {
                $rule->where('service_type_id', $serviceTypeId);
            }

            return $rule;
        };

        $hasPackageSelector = false;

        foreach ($fields as $fieldName => $config) {
            $submitAs = $config['submit_as'] ?? $fieldName;
            $required = (bool) ($config['required'] ?? false);
            $type = $config['type'] ?? 'text';

            switch ($type) {
                case 'location':
                    $rules[$submitAs] = $required ? 'required|string|max:255' : 'nullable|string|max:255';
                    $rules[$submitAs . '_lat'] = 'nullable|numeric|between:-90,90';
                    $rules[$submitAs . '_lng'] = 'nullable|numeric|between:-180,180';
                    $rules[$submitAs . '_predefined'] = [
                        'nullable',
                        'string',
                        Rule::exists('predefined_locations', 'code')
                            ->where(fn($query) => $query->where('is_active', true)),
                    ];
                    break;

                case 'date':
                    $isDropoff = str_contains($fieldName, 'dropoff') || str_contains($fieldName, 'return');
                    if ($isDropoff) {
                        // Dropoff/return dates must be after pickup date
                        $rules[$submitAs] = $required
                            ? 'required|date|after_or_equal:today'
                            : 'nullable|date|after_or_equal:today';
                    } else {
                        $rules[$submitAs] = $required
                            ? 'required|date|after_or_equal:today'
                            : 'nullable|date|after_or_equal:today';
                    }
                    break;

                case 'time':
                    $rules[$submitAs] = $required
                        ? 'required|date_format:H:i'
                        : 'nullable|date_format:H:i';
                    break;

                case 'datetime':
                    $rules[$submitAs] = ($required ? 'required' : 'nullable')
                        . '|date_format:Y-m-d\TH:i|after_or_equal:today';
                    break;

                case 'radio':
                    $options = $config['options'] ?? [];
                    $validValues = array_values(array_filter(
                        array_map(
                            fn($option) => is_array($option) ? ($option['value'] ?? null) : null,
                            $options
                        ),
                        fn($value) => is_string($value) && $value !== ''
                    ));
                    $rules[$submitAs] = [
                        $required ? 'required' : 'nullable',
                        'string',
                    ];
                    if (!empty($validValues)) {
                        $rules[$submitAs][] = Rule::in($validValues);
                    }
                    break;

                case 'select':
                    $options = $config['options'] ?? [];
                    $validValues = array_values(array_filter(
                        array_map(
                            fn($option) => is_array($option) ? ($option['value'] ?? null) : null,
                            $options
                        ),
                        fn($value) => is_string($value) && $value !== ''
                    ));
                    $rules[$submitAs] = [
                        $required ? 'required' : 'nullable',
                        'string',
                    ];
                    if (!empty($validValues)) {
                        $rules[$submitAs][] = Rule::in($validValues);
                    }
                    break;

                case 'checkbox':
                    $rules[$submitAs] = $required ? 'accepted' : 'nullable|boolean';
                    break;

                case 'number':
                    $min = $config['validation']['min'] ?? null;
                    $max = $config['validation']['max'] ?? null;
                    $rule = $required ? 'required|numeric' : 'nullable|numeric';
                    if ($min !== null) $rule .= '|min:' . $min;
                    if ($max !== null) $rule .= '|max:' . $max;
                    $rules[$submitAs] = $rule;
                    break;

                case 'textarea':
                    $rules[$submitAs] = $required ? 'required|string|max:1000' : 'nullable|string|max:1000';
                    break;

                case 'package_select':
                    if (!$serviceHasActivePackages) {
                        break;
                    }

                    $hasPackageSelector = true;
                    $rules[$submitAs] = [
                        $required ? 'required' : 'nullable',
                        'uuid',
                        $activePackageRule(),
                    ];
                    break;

                default:
                    $rules[$submitAs] = $required ? 'required|string|max:255' : 'nullable|string|max:255';
                    break;
            }
        }

        // Always allow common auxiliary fields
        $rules['rental_mode'] = $rules['rental_mode'] ?? 'nullable|string';
        if ($hasPackageSelector) {
            $rules['package_id'] = $rules['package_id'] ?? [
                'nullable',
                'uuid',
                $activePackageRule(),
            ];
        }
        $rules['package_type'] = $rules['package_type'] ?? 'nullable|string';
        $rules['trip_mode'] = $rules['trip_mode'] ?? 'nullable|string|in:fixed_route,open_package';

        // Allow dropoff fields for self_drive/with_driver even if not in config
        if (in_array($serviceCode, ['self_drive', 'with_driver'])) {
            $rules['dropoff'] = $rules['dropoff'] ?? 'nullable|string|max:255';
            $rules['dropoff_lat'] = $rules['dropoff_lat'] ?? 'nullable|numeric|between:-90,90';
            $rules['dropoff_lng'] = $rules['dropoff_lng'] ?? 'nullable|numeric|between:-180,180';
            $rules['dropoff_predefined'] = $rules['dropoff_predefined'] ?? 'nullable|string';
        }

        // Return trip fields
        if ($allowReturnTrip) {
            $rules['is_return_trip'] = 'nullable|boolean';
            $rules['return_date'] = 'required_if:is_return_trip,1|nullable|date|after_or_equal:today';
            $rules['return_time'] = 'required_if:is_return_trip,1|nullable|date_format:H:i';
        }

        return $rules;
    }

    /**
     * A configured selector is usable only when the resolved service type has at
     * least one active option. This mirrors the Blade renderer, which emits no
     * package input for an empty package collection.
     */
    protected function serviceHasActivePackages(?string $serviceTypeId): bool
    {
        if (!$serviceTypeId) {
            return false;
        }

        return DB::table('service_packages')
            ->where('service_type_id', $serviceTypeId)
            ->where('is_active', true)
            ->exists();
    }

    private function isOpenPackageServiceConfig(?string $serviceCode, array $data): bool
    {
        if (($data['trip_mode'] ?? null) === 'open_package') {
            return true;
        }

        if (!$serviceCode) {
            return false;
        }

        try {
            $serviceType = ServiceType::query()
                ->where('code', $serviceCode)
                ->orWhere('id', $serviceCode)
                ->orWhere('name', $serviceCode)
                ->first();

            $config = is_array($serviceType?->form_config) ? $serviceType->form_config : [];
            $mode = $config['trip_mode'] ?? $config['booking_mode'] ?? $config['service_mode'] ?? null;
            if ($mode === 'open_package') {
                return true;
            }

            $fields = isset($config['fields']) && is_array($config['fields']) ? $config['fields'] : $config;
            unset($fields['field_mappings']);

            $pickupRequired = isset($fields['pickup_location']) && (bool) ($fields['pickup_location']['required'] ?? false);
            $packageRequired = isset($fields['service_package_id']) && (bool) ($fields['service_package_id']['required'] ?? false);
            $dropoffOptional = !isset($fields['dropoff_location']) || !(bool) ($fields['dropoff_location']['required'] ?? false);

            return $pickupRequired && $packageRequired && $dropoffOptional;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Apply booking configuration validations after base rules.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            try {
                $settingsService = app(WebsiteSettingsService::class);
                $settings = $settingsService->getBookingSettings();
                $siteTimezone = $settingsService->get('site_timezone', config('app.timezone', 'UTC'));
            } catch (\Exception $e) {
                Log::warning('Failed to load booking settings for validation', ['error' => $e->getMessage()]);
                return;
            }

            $advanceHours = max(0, (int) ($settings['booking_advance_hours'] ?? 0));
            $maxDays = (int) ($settings['booking_max_days'] ?? 0);

            $siteTimezone = $this->normalizeSiteTimezone($siteTimezone ?? null);
            [$dateField, $startDateTime] = $this->resolveStartDateTime($siteTimezone);
            if (!$startDateTime) {
                return;
            }

            // Always validate the complete local pickup timestamp. Date-only rules
            // allow a past time on today's date; advanceHours=0 must still mean now,
            // not midnight at the start of today.
            $minimumDateTime = now($siteTimezone)->addHours($advanceHours);
            if ($startDateTime->lt($minimumDateTime)) {
                if ($advanceHours > 0) {
                    $customNotice = trim((string) ($settings['booking_notice_html'] ?? ''));
                    $message = $customNotice !== ''
                        ? trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $customNotice))))
                        : "Bookings must be made at least {$advanceHours} hours in advance.";
                } else {
                    $message = 'The pickup date and time must be in the future.';
                }

                $validator->errors()->add($dateField, $message);
            }

            if ($maxDays > 0) {
                $latestAllowed = now()->addDays($maxDays)->endOfDay();
                if ($startDateTime->gt($latestAllowed)) {
                    $validator->errors()->add(
                        $dateField,
                        "Bookings can only be made up to {$maxDays} days in advance."
                    );
                }
            }
        });
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'date.after_or_equal' => 'The date must be today or in the future.',
            'date.date_format' => 'The date must be in DD/MM/YYYY format.',
            'date.required' => 'The date field is required.',
            'return_date.after_or_equal' => 'Return date must be on or after the pickup date.',
            'pickup_date.after_or_equal' => 'Pickup date must be today or in the future.',
            'pickup_date.required' => 'The pickup date field is required.',
            'dropoff_date.after_or_equal' => 'Drop-off date must be on or after pickup date (same-day rental allowed).',
            'dropoff_date.required' => 'The drop-off date field is required.',
            'time.date_format' => 'Please enter a valid time format (HH:MM).',
            'time.required' => 'The time field is required.',
            'pickup_time.required' => 'The pickup time field is required.',
            'dropoff_time.required' => 'The drop-off time field is required.',
            'passengers.required' => 'The passengers field is required.',
            'passengers.max' => 'Maximum 15 passengers allowed per booking.',
            'pickup.required' => 'The pickup location is required.',
            'dropoff.required' => 'The drop-off location is required.',
        ];
    }

    /**
     * Airport transfer validation rules.
     */
    protected function airportTransferRules(): array
    {
        return [
            'service_type' => 'required|string',
            'transfer_type' => 'required|in:from-airport,to-airport',
            'pickup' => 'required|string|max:255',
            'dropoff' => 'required|string|max:255',
            'pickup_lat' => 'nullable|numeric|between:-90,90',
            'pickup_lng' => 'nullable|numeric|between:-180,180',
            'dropoff_lat' => 'nullable|numeric|between:-90,90',
            'dropoff_lng' => 'nullable|numeric|between:-180,180',
            'date' => 'required|date|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'package_id' => 'nullable|uuid|exists:service_packages,id',
            // 'passengers' => 'required|integer|min:1|max:15'
        ];
    }

    /**
     * Drop & pickup validation rules.
     */
    protected function dropPickupRules(): array
    {
        $rules = [
            'service_type' => 'required|string',
            'pickup' => 'required|string|max:255',
            'dropoff' => 'required|string|max:255',
            'pickup_lat' => 'nullable|numeric|between:-90,90',
            'pickup_lng' => 'nullable|numeric|between:-180,180',
            'dropoff_lat' => 'nullable|numeric|between:-90,90',
            'dropoff_lng' => 'nullable|numeric|between:-180,180',
            'date' => 'required|date|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'package_id' => 'nullable|uuid|exists:service_packages,id',
            // 'passengers' => 'required|integer|min:1|max:15',
            'need_return' => 'nullable|boolean'
        ];

        // Add return transfer validation if needed
        if ($this->input('need_return') == '1') {
            $rules['return_pickup'] = 'required|string|max:255';
            $rules['return_dropoff'] = 'required|string|max:255';
            $rules['return_date'] = 'required|date|after_or_equal:date';
            $rules['return_time'] = 'required|date_format:H:i';
        }

        return $rules;
    }

    /**
     * Rental packages validation rules.
     */
    protected function rideNowRules(): array
    {
        [$fields, $usesDropoffTime, $allowReturnTrip] = $this->resolveServiceFormConfig('ride_now');

        $pickupRequired = $this->isConfiguredFieldRequired($fields, 'pickup_location', true);
        $dropoffEnabled = $this->isConfiguredFieldEnabled($fields, 'dropoff_location', true);
        $dropoffRequired = $this->isConfiguredFieldRequired($fields, 'dropoff_location', true);
        $pickupDateRequired = $this->isConfiguredFieldRequired($fields, 'pickup_date', true);
        $pickupTimeRequired = $this->isConfiguredFieldRequired($fields, 'pickup_time', true);
        $dropoffDateEnabled = $this->isConfiguredFieldEnabled($fields, 'dropoff_date', true);
        $dropoffTimeEnabled = $this->isConfiguredFieldEnabled($fields, 'dropoff_time', true);
        $dropoffDateRequired = $this->isConfiguredFieldRequired($fields, 'dropoff_date', true);
        $dropoffTimeRequired = $this->isConfiguredFieldRequired($fields, 'dropoff_time', true);

        $rules = [
            'service_type' => 'required|string',
            'pickup' => $pickupRequired ? 'required|string|max:255' : 'nullable|string|max:255',
            'pickup_lat' => 'nullable|numeric|between:-90,90',
            'pickup_lng' => 'nullable|numeric|between:-180,180',
            'pickup_date' => $pickupDateRequired ? 'required|date|after_or_equal:today' : 'nullable|date|after_or_equal:today',
            'pickup_time' => $pickupTimeRequired ? 'required|date_format:H:i' : 'nullable|date_format:H:i',
            'package_type' => 'nullable|string|in:half-day,full-day,multi-day,hourly,daily',
            'package_id' => 'nullable|uuid|exists:service_packages,id',
        ];

        if ($dropoffEnabled) {
            $rules['dropoff'] = $dropoffRequired ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['dropoff_lat'] = 'nullable|numeric|between:-90,90';
            $rules['dropoff_lng'] = 'nullable|numeric|between:-180,180';
        } else {
            $rules['dropoff'] = 'nullable|string|max:255';
            $rules['dropoff_lat'] = 'nullable|numeric|between:-90,90';
            $rules['dropoff_lng'] = 'nullable|numeric|between:-180,180';
        }

        if ($usesDropoffTime) {
            if ($dropoffDateEnabled) {
                $rules['dropoff_date'] = $dropoffDateRequired
                    ? 'required|date|after_or_equal:pickup_date'
                    : 'nullable|date|after_or_equal:pickup_date';
            }
            if ($dropoffTimeEnabled) {
                $rules['dropoff_time'] = $dropoffTimeRequired
                    ? 'required|date_format:H:i'
                    : 'nullable|date_format:H:i';
            }
        } else {
            $rules['dropoff_date'] = 'nullable|date|after_or_equal:pickup_date';
            $rules['dropoff_time'] = 'nullable|date_format:H:i';
        }

        if ($allowReturnTrip) {
            $rules['is_return_trip'] = 'nullable|boolean';
            $rules['return_date'] = 'required_if:is_return_trip,1|date|after_or_equal:pickup_date';
            $rules['return_time'] = 'required_if:is_return_trip,1|date_format:H:i';
        }

        return $rules;
    }

    /**
     * Day rental validation rules.
     */
    protected function dayRentalRules(string $serviceConfigCode = 'day_rental'): array
    {
        [$fields, $usesDropoffTime, $allowReturnTrip] = $this->resolveServiceFormConfig($serviceConfigCode);

        $pickupRequired = $this->isConfiguredFieldRequired($fields, 'pickup_location', true);
        $dropoffEnabled = $this->isConfiguredFieldEnabled($fields, 'dropoff_location', true);
        $dropoffRequired = $this->isConfiguredFieldRequired($fields, 'dropoff_location', true);
        $pickupDateRequired = $this->isConfiguredFieldRequired($fields, 'pickup_date', true);
        $pickupTimeRequired = $this->isConfiguredFieldRequired($fields, 'pickup_time', true);
        $dropoffDateEnabled = $this->isConfiguredFieldEnabled($fields, 'dropoff_date', true);
        $dropoffTimeEnabled = $this->isConfiguredFieldEnabled($fields, 'dropoff_time', true);
        $dropoffDateRequired = $this->isConfiguredFieldRequired($fields, 'dropoff_date', true);
        $dropoffTimeRequired = $this->isConfiguredFieldRequired($fields, 'dropoff_time', true);

        $rules = [
            'service_type' => 'required|string',
            'pickup' => $pickupRequired ? 'required|string|max:255' : 'nullable|string|max:255',
            'pickup_custom' => 'nullable|string|max:255',
            'pickup_predefined' => 'nullable|string|exists:predefined_locations,code',
            'pickup_location_type' => 'nullable|in:predefined,custom',
            'pickup_lat' => 'nullable|numeric|between:-90,90',
            'pickup_lng' => 'nullable|numeric|between:-180,180',
            'pickup_date' => $pickupDateRequired ? 'required|date|after_or_equal:today' : 'nullable|date|after_or_equal:today',
            'pickup_time' => $pickupTimeRequired ? 'required|date_format:H:i' : 'nullable|date_format:H:i',
            'package_type' => 'nullable|string|in:half-day,full-day,multi-day,hourly,daily',
            'package_id' => 'nullable|uuid|exists:service_packages,id',
        ];

        if ($dropoffEnabled) {
            $rules['dropoff'] = $dropoffRequired ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['dropoff_custom'] = 'nullable|string|max:255';
            $rules['dropoff_predefined'] = 'nullable|string|exists:predefined_locations,code';
            $rules['dropoff_location_type'] = 'nullable|in:predefined,custom';
            $rules['dropoff_lat'] = 'nullable|numeric|between:-90,90';
            $rules['dropoff_lng'] = 'nullable|numeric|between:-180,180';
        } else {
            // Always allow dropoff fields for self_drive/with_driver (synced from pickup or entered by user)
            $rules['dropoff'] = 'nullable|string|max:255';
            $rules['dropoff_predefined'] = 'nullable|string';
            $rules['dropoff_lat'] = 'nullable|numeric|between:-90,90';
            $rules['dropoff_lng'] = 'nullable|numeric|between:-180,180';
        }

        if ($usesDropoffTime) {
            if ($dropoffDateEnabled) {
                $rules['dropoff_date'] = $dropoffDateRequired
                    ? 'required|date|after_or_equal:pickup_date'
                    : 'nullable|date|after_or_equal:pickup_date';
            }
            if ($dropoffTimeEnabled) {
                $rules['dropoff_time'] = $dropoffTimeRequired
                    ? 'required|date_format:H:i'
                    : 'nullable|date_format:H:i';
            }
        } else {
            $rules['dropoff_date'] = 'nullable|date|after_or_equal:pickup_date';
            $rules['dropoff_time'] = 'nullable|date_format:H:i';
        }

        if ($allowReturnTrip) {
            $rules['is_return_trip'] = 'nullable|boolean';
            $rules['return_date'] = 'required_if:is_return_trip,1|date|after_or_equal:pickup_date';
            $rules['return_time'] = 'required_if:is_return_trip,1|date_format:H:i';
        }

        return $rules;
    }

    /**
     * Wedding hire search validation rules.
     */
    protected function weddingHireRules(): array
    {
        return [
            'service_type' => 'required|string',
            'pickup' => 'required|string|max:255',
            'pickup_lat' => 'nullable|numeric|between:-90,90',
            'pickup_lng' => 'nullable|numeric|between:-180,180',
            'dropoff' => 'nullable|string|max:255',
            'dropoff_lat' => 'nullable|numeric|between:-90,90',
            'dropoff_lng' => 'nullable|numeric|between:-180,180',
            'date' => 'required|date|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'package_hours' => 'nullable|integer|min:1',
            'package_id' => 'nullable|uuid|exists:service_packages,id',
        ];
    }

    /**
     * Resolve service form config flags and dynamic fields.
     */
    protected function resolveServiceFormConfig(string $serviceCode): array
    {
        $candidateCodes = [$serviceCode];
        $fallbackMap = [
            'self_drive' => ['day_rental'],
            'with_driver' => ['day_rental'],
            'wedding_hire' => ['day_rental'],
            'wedding' => ['wedding_hire', 'day_rental'],
        ];

        if (isset($fallbackMap[$serviceCode])) {
            $candidateCodes = array_merge($candidateCodes, $fallbackMap[$serviceCode]);
        }

        $candidateCodes = array_values(array_unique(array_filter($candidateCodes)));

        $serviceType = null;
        foreach ($candidateCodes as $candidateCode) {
            $serviceType = ServiceType::query()
                ->publicContext()
                ->where('code', $candidateCode)
                ->where('is_active', true)
                ->first();

            if ($serviceType) {
                break;
            }
        }

        $usesDropoffTimeDefault = $serviceCode === 'ride_now' ? false : true;
        $usesDropoffTime = $serviceType?->uses_dropoff_time ?? $usesDropoffTimeDefault;
        $allowReturnTrip = $serviceType?->allow_return_trip ?? false;
        $resolvedPublicConfig = $serviceType
            ? app(\App\Services\DynamicServiceConfigurationService::class)
                ->getServiceFormConfiguration($serviceType->code)
            : [];
        $fields = is_array($resolvedPublicConfig['fields'] ?? null)
            ? $resolvedPublicConfig['fields']
            : [];
        $fieldMappings = is_array($resolvedPublicConfig['field_mappings'] ?? null)
            ? $resolvedPublicConfig['field_mappings']
            : [];

        return [$fields, $usesDropoffTime, $allowReturnTrip, $serviceType?->id, $fieldMappings];
    }

    /**
     * Determine whether a dynamic field is enabled for a service.
     */
    protected function isConfiguredFieldEnabled(array $fields, string $field, bool $default): bool
    {
        if (empty($fields)) {
            return $default;
        }

        return array_key_exists($field, $fields);
    }

    /**
     * Determine whether a dynamic field should be required.
     */
    protected function isConfiguredFieldRequired(array $fields, string $field, bool $default): bool
    {
        if (!$this->isConfiguredFieldEnabled($fields, $field, $default)) {
            return false;
        }

        if (empty($fields)) {
            return $default;
        }

        return (bool) ($fields[$field]['required'] ?? $default);
    }

    /**
     * Custom tour validation rules.
     */
    protected function customTourRules(): array
    {
        return [
            'service_type' => 'required|string',
            'tour_title' => 'required|string|max:255',
            'starting_location' => 'required|string|max:255',
            'starting_lat' => 'nullable|numeric|between:-90,90',
            'starting_lng' => 'nullable|numeric|between:-180,180',
            'pickup_date' => 'required|date|after_or_equal:today',
            'destinations' => 'required|array|min:1',
            'destinations.*' => 'required|string|max:255',
            // 'passengers' => 'nullable|integer|min:1|max:15'
        ];
    }

    /**
     * Corporate transport validation rules.
     */
    protected function corporateTransportRules(): array
    {
        return [
            'service_type' => 'required|string',
            'company_name' => 'required|string|max:255',
            'contact_person' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'requirements' => 'required|string|max:1000'
        ];
    }

    /**
     * Default validation rules.
     */
    protected function defaultRules(): array
    {
        return [
            'service_type' => 'required|string',
            'date' => 'required|date|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'package_id' => 'nullable|uuid|exists:service_packages,id'
        ];
    }

    /**
     * Check if date string is in DD/MM/YYYY format.
     */
    protected function isValidDDMMYYYY(string $date): bool
    {
        return preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date);
    }

    /**
     * Convert DD/MM/YYYY to Y-m-d format.
     */
    protected function convertDDMMYYYYToYMD(string $date): string
    {
        try {
            $carbon = Carbon::createFromFormat('d/m/Y', $date);
            return $carbon->format('Y-m-d');
        } catch (\Exception $e) {
            return $date; // Return original if conversion fails
        }
    }

    /**
     * Get formatted dates for display (DD/MM/YYYY).
     */
    public function getFormattedDate(string $field): ?string
    {
        $date = $this->input($field);
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('d/m/Y');
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Get Carbon instance for date field.
     */
    public function getCarbonDate(string $field): ?Carbon
    {
        $date = $this->input($field);
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve the primary booking start date/time field for validation.
     */
    protected function resolveStartDateTime(string $timezone): array
    {
        $dateField = null;
        $timeField = null;

        if ($this->filled('pickup_date')) {
            $dateField = 'pickup_date';
            $timeField = $this->filled('pickup_time') ? 'pickup_time' : null;
        } elseif ($this->filled('date')) {
            $dateField = 'date';
            $timeField = $this->filled('time') ? 'time' : null;
        } elseif ($this->filled('from_date')) {
            $dateField = 'from_date';
            $timeField = $this->filled('from_time') ? 'from_time' : null;
        }

        // The date may use a legacy canonical name while its paired time uses a
        // form-specific submit_as key (for example transfer_time). Always inspect
        // the active form config when either half of the pickup timestamp is missing.
        if ((!$dateField || !$timeField) && $this->filled('service_type')) {
            try {
                $resolvedConfig = $this->resolveServiceFormConfig((string) $this->input('service_type'));
                $configuredFields = is_array($resolvedConfig[0] ?? null) ? $resolvedConfig[0] : [];
                $fieldMappings = is_array($resolvedConfig[4] ?? null) ? $resolvedConfig[4] : [];

                $mappedDateField = $this->resolveMappedRequestField(
                    data_get($fieldMappings, 'dates.from_date'),
                    $configuredFields
                );
                $mappedTimeField = $this->resolveMappedRequestField(
                    data_get($fieldMappings, 'dates.from_time'),
                    $configuredFields
                );

                if ($mappedDateField && $this->filled($mappedDateField)) {
                    $dateField = $mappedDateField;
                }
                if ($mappedTimeField && $this->filled($mappedTimeField)) {
                    $timeField = $mappedTimeField;
                }

                if (!$dateField) {
                    foreach ($configuredFields as $fieldName => $config) {
                        if (!is_array($config) || !in_array(($config['type'] ?? null), ['date', 'datetime'], true)) {
                            continue;
                        }

                        $submitAs = (string) ($config['submit_as'] ?? $fieldName);
                        $normalizedName = strtolower((string) $fieldName . ' ' . $submitAs);
                        $isDropoff = str_contains($normalizedName, 'dropoff')
                            || str_contains($normalizedName, 'return')
                            || str_contains(strtolower($submitAs), 'to_');

                        if (!$isDropoff && $this->filled($submitAs)) {
                            $dateField = $submitAs;
                            break;
                        }
                    }
                }

                if ($dateField && !$timeField && !str_contains((string) $this->input($dateField), 'T')) {
                    foreach ($configuredFields as $fieldName => $config) {
                        if (!is_array($config) || ($config['type'] ?? null) !== 'time') {
                            continue;
                        }

                        $submitAs = (string) ($config['submit_as'] ?? $fieldName);
                        $normalizedName = strtolower((string) $fieldName . ' ' . $submitAs);
                        $isDropoff = str_contains($normalizedName, 'dropoff')
                            || str_contains($normalizedName, 'return')
                            || str_contains(strtolower($submitAs), 'to_');

                        if (!$isDropoff && $this->filled($submitAs)) {
                            $timeField = $submitAs;
                            break;
                        }
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to resolve configured booking start field', [
                    'service_type' => $this->input('service_type'),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (!$dateField) {
            return [null, null];
        }

        try {
            $date = Carbon::parse((string) $this->input($dateField), $timezone);
        } catch (\Throwable $exception) {
            return [$dateField, null];
        }

        if ($timeField) {
            try {
                $date->setTimeFromTimeString($this->input($timeField));
            } catch (\Exception $e) {
                Log::warning('Failed to parse booking time for validation', [
                    'field' => $timeField,
                    'value' => $this->input($timeField),
                ]);
            }
        }

        return [$dateField, $date];
    }

    private function normalizeSiteTimezone(mixed $timezone): string
    {
        $timezone = trim((string) $timezone);

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    /**
     * Convert a canonical field mapping (config key or submit_as value) into the
     * actual request key emitted by the dynamic public form.
     */
    private function resolveMappedRequestField(mixed $mapping, array $configuredFields): ?string
    {
        $mapping = trim((string) $mapping);
        if ($mapping === '') {
            return null;
        }

        if (isset($configuredFields[$mapping]) && is_array($configuredFields[$mapping])) {
            return (string) ($configuredFields[$mapping]['submit_as'] ?? $mapping);
        }

        foreach ($configuredFields as $fieldName => $config) {
            if (!is_array($config)) {
                continue;
            }

            $submitAs = (string) ($config['submit_as'] ?? $fieldName);
            if ($submitAs === $mapping) {
                return $submitAs;
            }
        }

        return $mapping;
    }
}
