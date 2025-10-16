<?php
// app/Http/Requests/AgentApiSession/UpdateAgentApiSessionRequest.php
namespace App\Http\Requests\Agent\AgentApiSession;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentApiSessionRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'agent_id'     => ['sometimes','required','exists:agents,id'],
            'agent_api_id' => ['sometimes','required','exists:agent_apis,id'],
            'last_access'  => ['sometimes','nullable','date'],
        ];
    }
}
