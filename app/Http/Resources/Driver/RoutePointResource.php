<?php

namespace App\Http\Resources\Driver;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Route Point Resource
 * 
 * Transforms RoutePoint model data for API responses.
 * 
 * @see Requirement 6.5 - Route point data in API responses
 * @see Requirement 7.1 - Route point querying
 */
class RoutePointResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'assignment_id' => $this->assignment_id,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'altitude' => $this->altitude !== null ? (float) $this->altitude : null,
            'speed' => $this->speed !== null ? (float) $this->speed : null,
            'heading' => $this->heading !== null ? (float) $this->heading : null,
            'accuracy' => $this->accuracy !== null ? (float) $this->accuracy : null,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
