<?php
// app/Http/Requests/VipType/CreateVipTypeRequest.php
namespace App\Http\Requests\VipType;

use Illuminate\Foundation\Http\FormRequest;

class CreateVipTypeRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'name'        => ['nullable','string','max:255'],
            'description' => ['nullable','string'],
        ];
    }
}
