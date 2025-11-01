<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\DynamicServiceConfigurationService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

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
            case 'airport-transfer':
                return $this->airportTransferRules();
            
            case 'drop-pickup':
                return $this->dropPickupRules();
            
            case 'rental-packages':
                return $this->rentalPackagesRules();
            
            case 'custom-tour':
                return $this->customTourRules();
            
            case 'corporate-transport':
                return $this->corporateTransportRules();
            
            default:
                return $this->defaultRules();
        }
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
            'dropoff_date.after' => 'Drop-off date must be after pickup date.',
            'dropoff_date.required' => 'The drop-off date field is required.',
            'time.date_format' => 'Please enter a valid time format (HH:MM).',
            'time.required' => 'The time field is required.',
            'pickup_time.required' => 'The pickup time field is required.',
            'dropoff_time.required' => 'The drop-off time field is required.',
            'passengers.required' => 'The passengers field is required.',
            'passengers.max' => 'Maximum 15 passengers allowed per booking.',
            'from.required' => 'The pickup location is required.',
            'to.required' => 'The drop-off location is required.',
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
            'from' => 'required|string|max:255',
            'to' => 'required|string|max:255',
            'from_lat' => 'nullable|numeric|between:-90,90',
            'from_lng' => 'nullable|numeric|between:-180,180',
            'to_lat' => 'nullable|numeric|between:-90,90',
            'to_lng' => 'nullable|numeric|between:-180,180',
            'date' => 'required|date|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'passengers' => 'required|integer|min:1|max:15'
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
            'passengers' => 'required|integer|min:1|max:15',
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
    protected function rentalPackagesRules(): array
    {
        return [
            'service_type' => 'required|string',
            'pickup' => 'required|string|max:255',
            'dropoff' => 'required|string|max:255',
            'pickup_lat' => 'nullable|numeric|between:-90,90',
            'pickup_lng' => 'nullable|numeric|between:-180,180',
            'dropoff_lat' => 'nullable|numeric|between:-90,90',
            'dropoff_lng' => 'nullable|numeric|between:-180,180',
            'pickup_date' => 'required|date|after_or_equal:today',
            'pickup_time' => 'required|date_format:H:i',
            'dropoff_date' => 'required|date|after:pickup_date',
            'dropoff_time' => 'required|date_format:H:i',
            'package_type' => 'nullable|string|in:half-day,full-day,multi-day,hourly,daily',
            'passengers' => 'required|integer|min:1|max:15'
        ];
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
            'passengers' => 'nullable|integer|min:1|max:15'
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
            'time' => 'required|date_format:H:i'
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
}