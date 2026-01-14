<?php

// app/Http/Requests/Currency/CreateCurrencyRequest.php
namespace App\Http\Requests\Currency;

use Illuminate\Foundation\Http\FormRequest;

class CreateCurrencyRequest extends FormRequest
{


    public function rules()
    {
        return [
            'code' => ['required', 'string', 'size:3', 'unique:currencies,code'],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:10'],
            'exrate' => ['nullable', 'numeric'],
            'country_id' => ['nullable', 'exists:countries,id'],
        ];
    }
}