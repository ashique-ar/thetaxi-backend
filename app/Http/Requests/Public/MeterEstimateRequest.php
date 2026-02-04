<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Meter Estimate Request
 * 
 * Validates requests to calculate fare estimates.
 * Requires device_uuid (from middleware) and optional distance_km or session_id.
 * 
 * @see Requirement 3.2 - Track by device_uuid without authentication
 */
class MeterEstimateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['nullable', 'uuid'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'session_id.uuid' => 'Session ID must be a valid UUID',
            'distance_km.numeric' => 'Distance must be a valid number',
            'distance_km.min' => 'Distance cannot be negative',
        ];
    }
}
