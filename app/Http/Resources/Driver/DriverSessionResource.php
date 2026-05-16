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
            'device' => $this->whenLoaded('device', function () {
                return $this->device ? [
                    'id' => $this->device->id,
                    'device_uuid' => $this->device->device_uuid,
                    'device_name' => $this->device->device_name,
                    'device_model' => $this->device->device_model,
                    'device_manufacturer' => $this->device->device_manufacturer,
                    'platform' => $this->device->platform,
                    'platform_display' => $this->device->platform_display,
                    'os_version' => $this->device->os_version,
                    'app_version' => $this->device->app_version,
                    'app_build' => $this->device->app_build,
                    'is_active' => (bool) $this->device->is_active,
                    'last_active_at' => $this->device->last_active_at?->toIso8601String(),
                    'registered_at' => $this->device->registered_at?->toIso8601String(),
                    'locale' => $this->device->locale,
                    'timezone' => $this->device->timezone,
                ] : null;
            }),
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
