<?php
// app/Http/Requests/Vehicle/VehicleAddon/UpdateVehicleAddonRequest.php

namespace App\Http\Requests\Vehicle\VehicleAddon;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleAddonRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'service_type_id' => ['sometimes', 'required', 'exists:service_types,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'thumbnail' => ['sometimes', 'nullable', 'string'],
            'min_qty' => ['sometimes', 'nullable', 'integer'],
            'max_qty' => ['sometimes', 'nullable', 'integer', 'gte:min_qty'],
            'description' => ['sometimes', 'nullable', 'string'],
            'amount' => ['sometimes', 'required', 'numeric'],
            'rate_type' => ['sometimes', 'required', 'in:flat,percentage'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
