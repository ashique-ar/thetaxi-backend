<?php
// app/Http/Requests/ServiceType/UpdateServiceTypeRequest.php
namespace App\Http\Requests\ServiceType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceTypeRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('service_type')->id;

        return [
            'code' => ["sometimes", "required", "string", "max:50", "unique:service_types,code,{$id}"],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'nullable', 'string'],
            'slug' => ["sometimes", "nullable", "string", "max:255", "unique:service_types,slug,{$id}"],
            'thumbnail' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pricing_mode' => ['sometimes', 'nullable', 'string', 'in:trip,day,hourly'],
            'uses_dropoff_time' => ['sometimes', 'boolean'],
            'allow_return_trip' => ['sometimes', 'boolean'],
            'frontend_category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'priority' => ['sometimes', 'nullable', 'integer'],
            'is_internal' => ['sometimes', 'boolean'],
            'terms' => ['sometimes', 'nullable', 'string'],
            'minimum_km' => ['sometimes', 'nullable', 'string'],
        ];
    }
}