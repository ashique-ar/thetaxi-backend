<?php
// app/Http/Requests/Agent/AgentCommission/CreateAgentCommissionRequest.php
namespace App\Http\Requests\Agent\AgentCommission;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentCommissionRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'agent_id'   => ['required','exists:agents,id'],
            'booking_id' => ['required','exists:bookings,id'],
            'amount'     => ['required','numeric','min:0'],
            'paid'       => ['required','boolean'],
            'paid_at'    => ['nullable','date'],
        ];
    }
}
