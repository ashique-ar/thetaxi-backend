<?php
// app/Http/Resources/AgentCommissionResource.php
namespace App\Http\Resources\Agent;

use Illuminate\Http\Resources\Json\JsonResource;

class AgentCommissionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'agent_id'   => $this->agent_id,
            'booking_id' => $this->booking_id,
            'amount'     => $this->amount,
            'paid'       => $this->paid,
            'paid_at'    => $this->paid_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
