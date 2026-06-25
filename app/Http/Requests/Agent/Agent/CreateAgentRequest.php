<?php
// app/Http/Requests/Agent/CreateAgentRequest.php
namespace App\Http\Requests\Agent\Agent;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentRequest extends FormRequest
{
    public function rules()
    {
        return [
            'user_id'         => ['nullable','exists:users,id'],
            'first_name'      => ['required_without:user_id','string','max:100'],
            'last_name'       => ['nullable','string','max:100'],
            'email'           => ['required_without:user_id','email','max:255','unique:users,email'],
            'phone'           => ['nullable','string','max:30'],
            'password'        => ['nullable','string','min:8'],
            'is_active'       => ['sometimes','boolean'],
            'code'            => ['nullable','string','max:50','unique:agents,code'],
            'commission_rate' => ['required','numeric','min:0'],
            'branding_config' => ['nullable','json'],
        ];
    }
}
