<?php
// app/Http/Requests/Company/Company/CreateCompanyRequest.php
namespace App\Http\Requests\Company\Company;

use Illuminate\Foundation\Http\FormRequest;

class CreateCompanyRequest extends FormRequest
{


    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'region_id' => ['nullable', 'exists:regions,id'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'city' => ['nullable', 'string'],
        ];
    }
}
