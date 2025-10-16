<?php
// app/Http/Requests/AgentApiSession/CreateAgentApiSessionRequest.php
namespace App\Http\Requests\Agent\AgentApiSession;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentApiSessionRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'agent_id'     => ['required','exists:agents,id'],
            'agent_api_id' => ['required','exists:agent_apis,id'],
            'last_access'  => ['nullable','date'],
        ];
    }
}
