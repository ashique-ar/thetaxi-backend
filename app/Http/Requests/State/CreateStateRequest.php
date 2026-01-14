<?php

// app/Http/Requests/State/CreateStateRequest.php
namespace App\Http\Requests\State;

use Illuminate\Foundation\Http\FormRequest;

class CreateStateRequest extends FormRequest
{

    public function rules()
    {
        return [
            'country_id' => ['nullable', 'exists:countries,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'url' => ['nullable', 'url'],
            'lng' => ['nullable', 'string'],
            'lat' => ['nullable', 'string'],
        ];
    }
}
