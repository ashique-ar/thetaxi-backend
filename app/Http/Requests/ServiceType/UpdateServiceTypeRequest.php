<?php
// app/Http/Requests/ServiceType/UpdateServiceTypeRequest.php
namespace App\Http\Requests\ServiceType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceTypeRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        $id = $this->route('service_type')->id;

        return [
            'code'        => ["sometimes","required","string","max:50","unique:service_types,code,{$id}"],
            'name'        => ['sometimes','required','string','max:255'],
            'description' => ['sometimes','nullable','string'],
            'type'        => ['sometimes','nullable','string'],
            'slug'        => ["sometimes","nullable","string","max:255","unique:service_types,slug,{$id}"],
            'thumbnail'   => ['sometimes','nullable','string','max:255'],
            'priority'    => ['sometimes','nullable','integer'],
            'is_internal' => ['sometimes','boolean'],
            'terms'       => ['sometimes','nullable','string'],
        ];
    }
}
