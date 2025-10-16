<?php
// app/Http/Requests/Vehicle/VehiclePricingSlabRate/CreateVehiclePricingSlabRateRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlabRate;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehiclePricingSlabRateRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'vehicle_group_id'=> ['nullable','exists:vehicle_groups,id'],
            'pricing_slab_id' => ['required','exists:vehicle_pricing_slabs,id'],
            'rate'            => ['nullable','numeric'],
        ];
    }
}
