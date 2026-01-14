<?php
// app/Http/Requests/AgentApi/UpdateAgentApiRequest.php
namespace App\Http\Requests\Agent\AgentApi;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentApiRequest extends FormRequest
{

    public function rules()
    {
        $id = $this->route('agent_api')->id;
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'api_key' => ["sometimes", "required", "string", "max:255", "unique:agent_apis,api_key,{$id}"],
        ];
    }
}
