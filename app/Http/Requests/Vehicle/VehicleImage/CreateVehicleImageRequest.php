<?php
// app/Http/Requests/Vehicle/VehicleImage/CreateVehicleImageRequest.php

namespace App\Http\Requests\Vehicle\VehicleImage;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleImageRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'vehicle_id'  => ['required','exists:vehicles,id'],
            'image_url'   => ['required','string'],
            'caption'     => ['nullable','string'],
            'sort_order'  => ['nullable','integer'],
            'is_primary'  => ['sometimes','boolean'],
        ];
    }
}
