<?php
// app/Http/Resources/AgentApiResource.php
namespace App\Http\Resources\Agent;

use Illuminate\Http\Resources\Json\JsonResource;

class AgentApiResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'api_key' => $this->api_key,
            'agent_id' => $this->agent_id,
            'agent' => $this->whenLoaded('agent'),
            'rate_limit' => $this->rate_limit,
            'access_level' => $this->access_level,
            'allowed_ips' => $this->allowed_ips,
            'status' => $this->status,
            'total_requests' => $this->total_requests,
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
