<?php

namespace App\Http\Requests;

use App\Models\Service\ServiceType;
use App\Services\WebsiteSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingSearchRequest extends FormRequest
{


    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

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
        }

        if ($serviceType === 'wedding_hire') {
            if (empty($data['pickup_date']) && !empty($data['date'])) {
                $data['pickup_date'] = $data['date'];
            }
            if (empty($data['pickup_time']) && !empty($data['time'])) {
                $data['pickup_time'] = $data['time'];
            }
        }

        $this->replace($data);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $serviceType = $this->input('service_type');

        // Service-specific validation rules
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
     * Apply booking configuration validations after base rules.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            try {
                $settings = app(WebsiteSettingsService::class)->getBookingSettings();
            } catch (\Exception $e) {
                Log::warning('Failed to load booking settings for validation', ['error' => $e->getMessage()]);
                return;
            }

            $advanceHours = (int) ($settings['booking_advance_hours'] ?? 0);
            $maxDays = (int) ($settings['booking_max_days'] ?? 0);

            [$dateField, $startDateTime] = $this->resolveStartDateTime();
            if (!$startDateTime) {
                return;
            }

            if ($advanceHours > 0) {
                $minimumDateTime = now()->addHours($advanceHours);
                if ($startDateTime->lt($minimumDateTime)) {
                    $validator->errors()->add(
                        $dateField,
                        "Bookings must be made at least {$advanceHours} hours in advance."
                    );
                }
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
        $fields = [];

        if (is_array($serviceType?->form_config)) {
            $storedConfig = $serviceType->form_config;
            $fieldConfig = isset($storedConfig['fields']) && is_array($storedConfig['fields'])
                ? $storedConfig['fields']
                : $storedConfig;

            unset($fieldConfig['field_mappings']);

            $fields = array_filter($fieldConfig, function ($config) {
                return is_array($config)
                    && isset($config['type'])
                    && isset($config['label']);
            });
        }

        return [$fields, $usesDropoffTime, $allowReturnTrip];
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
    protected function resolveStartDateTime(): array
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

        if (!$dateField) {
            return [null, null];
        }

        $date = $this->getCarbonDate($dateField);
        if (!$date) {
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
}
