<?php

// app/Http/Requests/Region/UpdateRegionRequest.php
namespace App\Http\Requests\Region;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
           'name'        => ['sometimes','required','string','max:255'],
            'description' => ['sometimes','nullable','string'],
        ];
    }
}
