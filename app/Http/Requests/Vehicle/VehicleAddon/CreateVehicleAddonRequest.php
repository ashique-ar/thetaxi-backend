<?php
// app/Http/Requests/Vehicle/VehicleAddon/CreateVehicleAddonRequest.php

namespace App\Http\Requests\Vehicle\VehicleAddon;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleAddonRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'service_type_id'=> ['required','exists:service_types,id'],
            'name'          => ['required','string','max:255'],
            'thumbnail'     => ['nullable','string'],
            'min_qty'       => ['nullable','integer'],
            'max_qty'       => ['nullable','integer','gte:min_qty'],
            'description'   => ['nullable','string'],
            'amount'        => ['required','numeric'],
            'rate_type'     => ['required','in:flat,percentage'],
            'valid_from'    => ['nullable','date'],
            'valid_to'      => ['nullable','date','after_or_equal:valid_from'],
        ];
    }
}
