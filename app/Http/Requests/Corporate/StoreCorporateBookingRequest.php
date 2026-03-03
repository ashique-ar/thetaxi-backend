<?php

namespace App\Http\Requests\Corporate;

use App\Models\Corporate\Corporate;
use Illuminate\Foundation\Http\FormRequest;

class StoreCorporateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_group_id' => ['required', 'uuid'],
            'pickup_location' => ['required'],
            'dropoff_location' => ['required'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'employee_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $corporateId = $this->input('corporate_id');
            $vehicleGroupId = $this->input('vehicle_group_id');

            if ($corporateId && $vehicleGroupId) {
                $corporate = Corporate::find($corporateId);

                if ($corporate && !$corporate->vehicleGroups()->where('vehicle_groups.id', $vehicleGroupId)->exists()) {
                    $validator->errors()->add(
                        'vehicle_group_id',
                        'The selected vehicle group is not assigned to your corporate.'
                    );
                }
            }
        });
    }
}
