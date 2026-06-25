<?php
// app/Http/Requests/AgentApi/UpdateAgentApiRequest.php
namespace App\Http\Requests\Agent\AgentApi;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentApiRequest extends FormRequest
{

    public function rules()
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'rate_limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'access_level' => ['sometimes', 'required', 'in:read,write,admin'],
            'allowed_ips' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
