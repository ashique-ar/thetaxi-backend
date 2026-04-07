<?php
// app/Http/Requests/ServiceType/CreateServiceTypeRequest.php
namespace App\Http\Requests\ServiceType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateServiceTypeRequest extends FormRequest
{


    public function rules()
    {
        $context = (string) $this->input('context', 'portal');
        $ownerType = (string) $this->input('owner_type', '');
        $ownerId = (string) $this->input('owner_id', '');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('service_types', 'code')->where(fn ($query) => $query
                    ->where('context', $context)
                    ->where('owner_type', $ownerType)
                    ->where('owner_id', $ownerId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'context' => ['sometimes', 'required', 'string', 'max:50'],
            'owner_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('service_types', 'slug')->where(fn ($query) => $query
                    ->where('context', $context)
                    ->where('owner_type', $ownerType)
                    ->where('owner_id', $ownerId)),
            ],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'pricing_mode' => ['nullable', 'string', 'in:trip,day,hourly'],
            'uses_dropoff_time' => ['nullable', 'boolean'],
            'allow_return_trip' => ['nullable', 'boolean'],
            'frontend_category' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer'],
            'is_internal' => ['nullable', 'boolean'],
            'terms' => ['nullable', 'string'],
            'minimum_km' => ['sometimes', 'nullable'],
        ];
    }
}
