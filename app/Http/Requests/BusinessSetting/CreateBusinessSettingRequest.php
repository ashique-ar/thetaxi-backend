<?php
// app/Http/Requests/BusinessSetting/CreateBusinessSettingRequest.php
namespace App\Http\Requests\BusinessSetting;

use Illuminate\Foundation\Http\FormRequest;

class CreateBusinessSettingRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'type'    => ['required','string','max:255'],
            'value'   => ['nullable','string'],
        ];
    }
}