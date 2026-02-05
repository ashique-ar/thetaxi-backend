<?php

namespace App\Http\Resources\Driver;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Driver Device Resource
 * 
 * Transforms DriverDevice model data for API responses.
 */
class DriverDeviceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'device_uuid' => $this->device_uuid,
            'device_name' => $this->device_name,
            'device_model' => $this->device_model,
            'device_manufacturer' => $this->device_manufacturer,
            'platform' => $this->platform,
            'platform_display' => $this->platform_display,
            'os_version' => $this->os_version,
            'app_version' => $this->app_version,
            'app_build' => $this->app_build,
            'has_push_token' => $this->hasPushToken(),
            'push_provider' => $this->push_provider,
            'is_active' => $this->is_active,
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'registered_at' => $this->registered_at?->toIso8601String(),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'display_name' => $this->display_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
