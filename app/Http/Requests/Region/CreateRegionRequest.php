<?php

// app/Http/Requests/Region/CreateRegionRequest.php
namespace App\Http\Requests\Region;

use Illuminate\Foundation\Http\FormRequest;

class CreateRegionRequest extends FormRequest
{

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}

