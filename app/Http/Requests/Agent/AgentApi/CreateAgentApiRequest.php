<?php
// app/Http/Requests/AgentApi/CreateAgentApiRequest.php
namespace App\Http\Requests\Agent\AgentApi;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentApiRequest extends FormRequest
{

    public function rules()
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'api_key' => ['required', 'string', 'max:255', 'unique:agent_apis,api_key'],
        ];
    }
}
