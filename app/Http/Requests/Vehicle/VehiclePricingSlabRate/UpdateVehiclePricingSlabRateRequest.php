<?php
// app/Http/Requests/Vehicle/VehiclePricingSlabRate/UpdateVehiclePricingSlabRateRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlabRate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehiclePricingSlabRateRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'vehicle_group_id'=> ['sometimes','nullable','exists:vehicle_groups,id'],
            'pricing_slab_id' => ['sometimes','required','exists:vehicle_pricing_slabs,id'],
            'rate'            => ['sometimes','nullable','numeric'],
        ];
    }
}
