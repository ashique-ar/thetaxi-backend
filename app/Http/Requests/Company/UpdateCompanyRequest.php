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
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'region_id' => ['sometimes', 'nullable', 'exists:regions,id'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'exists:states,id'],
            'city' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'is_default' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
