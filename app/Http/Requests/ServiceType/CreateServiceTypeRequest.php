<?php
// app/Http/Requests/ServiceType/CreateServiceTypeRequest.php
namespace App\Http\Requests\ServiceType;

use Illuminate\Foundation\Http\FormRequest;

class CreateServiceTypeRequest extends FormRequest
{


    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:service_types,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:service_types,slug'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'integer'],
            'is_internal' => ['nullable', 'boolean'],
            'terms' => ['nullable', 'string'],
        ];
    }
}
