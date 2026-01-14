<?php
// app/Http/Requests/Agent/UpdateAgentRequest.php
namespace App\Http\Requests\Agent\Agent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentRequest extends FormRequest
{

    public function rules()
    {
        $id = $this->route('agent')->id;
        return [
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'code' => ["sometimes", "nullable", "string", "max:50", "unique:agents,code,{$id}"],
            'commission_rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'branding_config' => ['sometimes', 'nullable', 'json'],
        ];
    }
}
