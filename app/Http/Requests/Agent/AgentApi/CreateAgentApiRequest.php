<?php
// app/Http/Requests/AgentApi/CreateAgentApiRequest.php
namespace App\Http\Requests\Agent\AgentApi;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentApiRequest extends FormRequest
{

    public function rules()
    {
        return [
            'agent_id' => ['required', 'exists:agents,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'rate_limit' => ['required', 'integer', 'min:1', 'max:100000'],
            'access_level' => ['required', 'in:read,write,admin'],
            'allowed_ips' => ['nullable', 'string'],
        ];
    }
}
