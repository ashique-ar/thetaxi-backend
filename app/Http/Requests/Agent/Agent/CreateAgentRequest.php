<?php
// app/Http/Requests/Agent/CreateAgentRequest.php
namespace App\Http\Requests\Agent\Agent;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentRequest extends FormRequest
{
    public function rules()
    {
        return [
            'user_id'         => ['required','exists:users,id'],
            'code'            => ['nullable','string','max:50','unique:agents,code'],
            'commission_rate' => ['required','numeric','min:0'],
            'branding_config' => ['nullable','json'],
        ];
    }
}
