<?php
// app/Http/Requests/Vehicle/VehicleImage/UpdateVehicleImageRequest.php

namespace App\Http\Requests\Vehicle\VehicleImage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleImageRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'vehicle_id' => ['sometimes', 'required', 'exists:vehicles,id'],
            'image_url' => ['sometimes', 'required', 'string'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
