<?php
// app/Http/Requests/Vehicle/Discount/CreateVehicleDiscountRequest.php

namespace App\Http\Requests\Vehicle\Discount;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleDiscountRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:vehicle_discounts,code'],
            'description' => ['nullable', 'string'],
            'service_type_id' => ['required', 'exists:service_types,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'is_percentage' => ['required', 'boolean'],
            'applies_to' => ['required', 'string', 'max:100'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
