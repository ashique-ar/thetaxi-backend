<?php

// app/Http/Requests/Staff/UpdateStaffRequest.php

namespace App\Http\Requests\Staff;

use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function rules()
    {
        $staff = $this->route('staff');
        $id = $staff instanceof Staff ? $staff->getKey() : $staff;

        return [
            'staff_type' => ['sometimes', 'required', 'string', 'max:100'],
            'company_id' => ['sometimes', 'nullable', 'uuid', 'exists:companies,id'],
            'collection_commission_enabled' => ['sometimes', 'boolean'],
            'collection_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'code' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('staff', 'code')->ignore($id)],
            'nic' => ['sometimes', 'nullable', 'string', 'max:20'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'license_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_expiry' => ['sometimes', 'nullable', 'date', 'after_or_equal:dob'],
            'address' => ['sometimes', 'nullable', 'string'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'exists:states,id'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
