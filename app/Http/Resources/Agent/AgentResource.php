<?php
// app/Http/Resources/AgentResource.php
namespace App\Http\Resources\Agent;

use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'code'            => $this->code,
            'commission_rate' => $this->commission_rate,
            'branding_config' => $this->branding_config,
            'user'            => $this->whenLoaded('user'),
            'bookings_count'  => $this->whenCounted('bookings'),
            'commissions_sum_amount' => $this->whenAggregated('commissions', 'amount', 'sum'),
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
