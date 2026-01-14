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
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
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
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:drivers,code'],
            'nic' => ['sometimes', 'required', 'string', 'max:20'],
            'license_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_expiry' => ['sometimes', 'nullable', 'date'],
            'license_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'address' => ['sometimes', 'nullable', 'string'],
            'country_id' => ['sometimes', 'nullable', 'uuid'],
            'state_id' => ['sometimes', 'nullable', 'uuid'],
            'city' => ['sometimes', 'nullable', 'string'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'remarks' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'nullable', 'boolean']
        ];
    }
}
