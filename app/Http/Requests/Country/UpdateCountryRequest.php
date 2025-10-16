<?php

// app/Http/Requests/Country/UpdateCountryRequest.php
namespace App\Http\Requests\Country;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCountryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:5'],
            'code3' => ['sometimes', 'nullable', 'string', 'max:3'],
            'callcode' => ['sometimes', 'nullable', 'string', 'max:10'],
            'googlelode' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'url' => ['sometimes', 'nullable', 'url'],
            'tagline' => ['sometimes', 'nullable', 'string'],
        ];
    }
}