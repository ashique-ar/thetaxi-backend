<?php

// app/Http/Requests/Staff/CreateStaffRequest.php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStaffRequest extends FormRequest
{
    public function rules()
    {
        return [
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)),
                Rule::unique('staff', 'user_id'),
            ],
            'staff_type' => ['required', 'string', 'max:100'],
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'collection_commission_enabled' => ['sometimes', 'boolean'],
            'collection_commission_rate' => ['required_if:collection_commission_enabled,true', 'numeric', 'min:0', 'max:100'],
            'code' => ['nullable', 'string', 'max:100', 'unique:staff,code'],
            'employee_number_override_reason' => ['nullable', 'required_with:code', 'string', 'max:2000'],
            'nic' => ['nullable', 'string', 'max:20'],
            'dob' => ['nullable', 'date'],
            'license_no' => ['nullable', 'string', 'max:100'],
            'license_expiry' => ['nullable', 'date', 'after_or_equal:dob'],
            'address' => ['nullable', 'string'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city' => ['nullable', 'string', 'max:255'],
            'joined_at' => ['nullable', 'date'],
            'service_date' => ['nullable', 'date'],
            'confirmation_date' => ['nullable', 'date', 'after_or_equal:joined_at'],
            'employment_type_id' => ['nullable', 'uuid', 'exists:hr_employment_types,id'],
            'position_id' => ['nullable', 'uuid', 'exists:hr_positions,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:hr_organization_units,id'],
            'manager_staff_id' => ['nullable', 'uuid', 'exists:staff,id'],
            'recruitment_application_id' => ['nullable', 'uuid', 'exists:hr_candidate_applications,id'],
        ];
    }
}
