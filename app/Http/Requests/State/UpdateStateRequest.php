<?php

// app/Http/Requests/State/CreateStateRequest.php
namespace App\Http\Requests\State;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStateRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'country_id'  => ['sometimes','nullable','exists:countries,id'],
            'name'        => ['sometimes','required','string','max:255'],
            'description' => ['sometimes','nullable','string'],
            'url'         => ['sometimes','nullable','url'],
            'lng'         => ['sometimes','nullable','string'],
            'lat'         => ['sometimes','nullable','string'],
        ];
    }
}