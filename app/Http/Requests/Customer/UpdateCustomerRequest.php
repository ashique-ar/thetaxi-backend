<?php
// app/Http/Requests/Customer/UpdateCustomerRequest.php
namespace App\Http\Requests\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function rules()
    {
        $customerId = $this->route('customer')->id;

        return [
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                function ($attribute, $value, $fail) use ($customerId) {
                    $exists = Customer::where('id', '!=', $customerId)
                        ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [strtolower(trim($value))]))
                        ->exists();

                    if ($exists) {
                        $fail('This email is already registered as a customer.');
                    }
                },
            ],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                function ($attribute, $value, $fail) use ($customerId) {
                    $exists = Customer::where('id', '!=', $customerId)
                        ->whereHas('user', fn ($query) => $query->where('phone', $value))
                        ->exists();

                    if ($exists) {
                        $fail('This phone number is already registered as a customer.');
                    }
                },
            ],
            'wedding_date' => 'sometimes|nullable|date',
            'type' => 'sometimes|required|string|max:50',
            'sub_type' => 'sometimes|nullable|string|max:50',
            'category' => 'sometimes|nullable|string|max:50',
            'code' => ['sometimes', 'nullable', 'string', 'max:100', "unique:customers,code,{$customerId}"],
            'nic' => ['sometimes', 'nullable', 'string', 'max:100'],
            'passport_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_expiry' => ['sometimes', 'nullable', 'date'],
            'license_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'address' => ['sometimes', 'nullable', 'string'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'exists:states,id'],
            'city' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
