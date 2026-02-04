<?php

namespace App\Http\Resources\Driver;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Driver Session Resource
 * 
 * Transforms DriverSession model data for API responses.
 * 
 * @see Requirement 4.1, 4.3 - Session data in API responses
 */
class DriverSessionResource extends JsonResource
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
            'driver_id' => $this->driver_id,
            'device_uuid' => $this->device_uuid,
            'status' => $this->status,
            'start_time' => $this->start_time?->toIso8601String(),
            'end_time' => $this->end_time?->toIso8601String(),
            'start_latitude' => $this->start_latitude,
            'start_longitude' => $this->start_longitude,
            'end_latitude' => $this->end_latitude,
            'end_longitude' => $this->end_longitude,
            'total_distance_km' => $this->total_distance_km,
            'assignment_id' => $this->assignment_id,
            'metadata' => $this->metadata,
            'duration_seconds' => $this->when(
                $this->start_time && $this->end_time,
                fn() => $this->end_time->diffInSeconds($this->start_time)
            ),
            'duration_minutes' => $this->when(
                $this->start_time && $this->end_time,
                fn() => round($this->end_time->diffInSeconds($this->start_time) / 60, 2)
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
