<?php
// app/Http/Requests/Staff/CreateStaffRequest.php
namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class CreateStaffRequest extends FormRequest
{


    public function rules()
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'staff_type' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:100', 'unique:staff,code'],
            'dob' => ['nullable', 'date'],
            'license_no' => ['nullable', 'string', 'max:100'],
            'license_expiry' => ['nullable', 'date', 'after_or_equal:dob'],
            'address' => ['nullable', 'string'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city' => ['nullable', 'string'],
        ];
    }
}
