<?php
// app/Http/Requests/Agent/UpdateAgentRequest.php
namespace App\Http\Requests\Agent\Agent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentRequest extends FormRequest
{

    public function rules()
    {
        $id = $this->route('agent')->id;
        $userId = $this->route('agent')->user_id;
        return [
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email', 'max:255', "unique:users,email,{$userId}"],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'code' => ["sometimes", "nullable", "string", "max:50", "unique:agents,code,{$id}"],
            'commission_rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'branding_config' => ['sometimes', 'nullable', 'json'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
