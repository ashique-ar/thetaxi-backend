<?php
// app/Http/Requests/Staff/UpdateStaffRequest.php
namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('staff')->id;

        return [
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'staff_type' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => ["sometimes", "nullable", "string", "max:100", "unique:staff,code,{$id}"],
            'dob' => ['sometimes', 'nullable', 'date'],
            'license_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_expiry' => ['sometimes', 'nullable', 'date', 'after_or_equal:dob'],
            'address' => ['sometimes', 'nullable', 'string'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'exists:states,id'],
            'city' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
