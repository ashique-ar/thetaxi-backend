<?php
// app/Http/Requests/Driver/CreateDriverRequest.php
namespace App\Http\Requests\Driver\Driver;

use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateDriverRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:50', 'unique:drivers,code'],
            'nic' => ['required', 'string', 'max:20'],
            'license_no' => ['required', 'string', 'max:100'],
            'license_expiry' => ['required', 'date'],
            'license_type' => ['required', 'string', 'max:50'],
            'dob' => ['required', 'date'],
            'address' => ['required', 'string'],
            'country_id' => ['required', 'uuid'],
            'state_id' => ['required', 'uuid'],
            'city' => ['required', 'string'],
            'postal_code' => ['required', 'string', 'max:20'],
            'remarks' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean']
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $email = $this->input('email');

            if ($email) {
                $user = User::where('email', $email)->first();

                if ($user) {
                    // Check if user already has driver context
                    $existingContext = UserContext::where('user_id', $user->id)
                        ->where('context_type', 'driver')
                        ->where('is_active', true)
                        ->first();

                    if ($existingContext) {
                        $validator->errors()->add(
                            'email',
                            'This email is already registered as a driver. Please use a different email or contact the administrator.'
                        );
                    }

                    // Also check direct Driver model for backwards compatibility
                    $existingDriver = Driver::where('user_id', $user->id)->first();
                    if ($existingDriver) {
                        $validator->errors()->add(
                            'email',
                            'This email is already registered as a driver. Please use a different email or contact the administrator.'
                        );
                    }
                }
            }
        });
    }
}
