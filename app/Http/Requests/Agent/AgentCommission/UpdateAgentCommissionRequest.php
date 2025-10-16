<?php
// app/Http/Requests/Agent/AgentCommission/UpdateAgentCommissionRequest.php
namespace App\Http\Requests\Agent\AgentCommission;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentCommissionRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'agent_id'   => ['sometimes','required','exists:agents,id'],
            'booking_id' => ['sometimes','required','exists:bookings,id'],
            'amount'     => ['sometimes','required','numeric','min:0'],
            'paid'       => ['sometimes','required','boolean'],
            'paid_at'    => ['sometimes','nullable','date'],
        ];
    }
}
