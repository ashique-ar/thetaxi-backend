<?php
// app/Http/Requests/VipType/UpdateVipTypeRequest.php
namespace App\Http\Requests\VipType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVipTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
