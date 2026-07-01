<?php
// app/Http/Resources/AgentApiSessionResource.php
namespace App\Http\Resources\Agent;

use Illuminate\Http\Resources\Json\JsonResource;

class AgentApiSessionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'agent_id'     => $this->agent_id,
            'agent_api_id' => $this->agent_api_id,
            'last_access'  => $this->last_access,
            'method'       => $this->method,
            'path'         => $this->path,
            'status_code'  => $this->status_code,
            'duration_ms'  => $this->duration_ms,
            'ip_address'   => $this->ip_address,
            'user_agent'   => $this->user_agent,
            'metadata'     => $this->metadata,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
