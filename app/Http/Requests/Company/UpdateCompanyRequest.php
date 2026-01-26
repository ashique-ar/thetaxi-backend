<?php
// app/Http/Requests/Company/UpdateCompanyRequest.php
namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{


    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'region_id' => ['sometimes', 'nullable', 'exists:regions,id'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'district_id' => ['sometimes', 'nullable', 'exists:districts,id'],
            'city' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
