<?php

// app/Http/Requests/Country/CreateCountryRequest.php
namespace App\Http\Requests\Country;

use Illuminate\Foundation\Http\FormRequest;

class CreateCountryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:5'],
            'code3' => ['nullable', 'string', 'max:3'],
            'callcode' => ['nullable', 'string', 'max:10'],
            'googlelode' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'url' => ['nullable', 'url'],
            'tagline' => ['nullable', 'string'],
        ];
    }
}

