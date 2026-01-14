<?php

// app/Http/Requests/BusinessSetting/UpdateBusinessSettingRequest.php
namespace App\Http\Requests\BusinessSetting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingRequest extends FormRequest
{


    public function rules()
    {
        return [
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'value' => ['sometimes', 'nullable', 'string'],
        ];
    }
}