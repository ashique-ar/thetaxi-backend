<?php
// app/Http/Requests/Vehicle/Discount/UpdateVehicleDiscountRequest.php

namespace App\Http\Requests\Vehicle\Discount;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleDiscountRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('vehicle_discount')->id;
        return [
            'code' => ["sometimes", "required", "string", "max:50", "unique:vehicle_discounts,code,{$id}"],
            'description' => ['sometimes', 'nullable', 'string'],
            'service_type_id' => ['sometimes', 'required', 'exists:service_types,id'],
            'vehicle_id' => ['sometimes', 'nullable', 'exists:vehicles,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'is_percentage' => ['sometimes', 'required', 'boolean'],
            'applies_to' => ['sometimes', 'required', 'string', 'max:100'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
