<?php
// app/Http/Requests/Driver/UpdateDriverRequest.php
namespace App\Http\Requests\Driver\Driver;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverRequest extends FormRequest
{


    public function rules()
    {
        $driverId = $this->route('driver')->id;
        return [
            'user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                function ($attribute, $value, $fail) use ($driverId) {
                    $exists = \App\Models\User::where('email', $value)
                        ->whereHas('contexts', function ($q) use ($driverId) {
                            $q->where('context_type', 'driver')
                                ->where('is_active', true)
                                // ← just compare the foreign key directly
                                ->where('context_id', '!=', $driverId);
                        })
                        ->exists();

                    if ($exists) {
                        $fail('This email is already registered as a driver.');
                    }
                },
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', "unique:drivers,code,{$driverId}"],
            'nic' => ['sometimes', 'nullable', 'string', 'max:20'],
            'license_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_expiry' => ['sometimes', 'nullable', 'date'],
            'license_issued_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:license_expiry'],
            'license_reminder_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'license_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'addresses' => ['sometimes', 'array', 'max:50'],
            'addresses.*.id' => ['nullable', 'uuid'], 'addresses.*.label' => ['nullable', 'string', 'max:100'],
            'addresses.*.type' => ['required', 'in:home,office,billing,other'], 'addresses.*.address' => ['required', 'string', 'max:2000'],
            'addresses.*.city' => ['nullable', 'string', 'max:255'], 'addresses.*.state_id' => ['nullable', 'exists:states,id'],
            'addresses.*.country_id' => ['nullable', 'exists:countries,id'], 'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.state_name' => ['nullable', 'string', 'max:255'], 'addresses.*.country_name' => ['nullable', 'string', 'max:255'],
            'addresses.*.latitude' => ['nullable', 'numeric', 'between:-90,90'], 'addresses.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'addresses.*.is_default' => ['nullable', 'boolean'],
            'default_vehicle_id' => ['sometimes', 'nullable', 'exists:vehicles,id'],
            'remarks' => ['sometimes', 'nullable', 'string'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'blood_group' => ['sometimes', 'nullable', 'string', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'medical_conditions' => ['sometimes', 'nullable', 'string'],
            'hire_date' => ['sometimes', 'nullable', 'date'],
            'termination_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:hire_date'],
            'availability_status' => ['sometimes', 'nullable', 'string', 'in:available,busy,on_break,offline'],
            'status' => ['sometimes', 'nullable', 'string', 'in:available,busy,on_break,offline'],
            'payment_method' => ['sometimes', 'nullable', 'array'],
            'payment_method.method_type' => ['required_with:payment_method', 'string', 'in:cash,bank_transfer,cheque,card,wallet,online,other'],
            'payment_method.label' => ['nullable', 'string', 'max:120'],
            'payment_method.account_holder_name' => ['nullable', 'string', 'max:255'],
            'payment_method.bank_name' => ['nullable', 'string', 'max:255'],
            'payment_method.bank_branch' => ['nullable', 'string', 'max:255'],
            'payment_method.account_number' => ['nullable', 'string', 'max:100'],
            'payment_method.routing_number' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'nullable', 'boolean']
        ];
    }
}
