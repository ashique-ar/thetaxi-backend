<?php

namespace App\Http\Resources\Corporate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CorporateServiceDistancePolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$configurationStatus, $warning] = $this->configurationStatus();

        return [
            'id' => $this->id,
            'application_mode' => $this->application_mode,
            'policy_id' => $this->policy_id,
            'route_template_override' => $this->route_template_override,
            'route_anchor_sequence_override' => $this->route_anchor_sequence_override,
            'origin_location_override' => $this->origin_location_override,
            'return_location_override' => $this->return_location_override,
            'include_origin_to_pickup' => $this->include_origin_to_pickup,
            'include_dropoff_to_return' => $this->include_dropoff_to_return,
            'movement_rate_method' => $this->movement_rate_method,
            'outbound_rate' => $this->outbound_rate,
            'return_rate' => $this->return_rate,
            'maximum_outbound_km' => $this->maximum_outbound_km,
            'maximum_return_km' => $this->maximum_return_km,
            'effective_from' => $this->effective_from?->toISOString(),
            'effective_until' => $this->effective_until?->toISOString(),
            'is_active' => (bool) $this->is_active,
            'configuration_status' => $configurationStatus,
            'warning' => $warning,
        ];
    }

    private function configurationStatus(): array
    {
        if ($this->movement_rate_method === 'separate_rate'
            && ($this->outbound_rate === null || $this->return_rate === null)) {
            return ['incomplete', 'Separate-rate overrides require outbound and return rates.'];
        }
        if (! $this->is_active) {
            return ['inactive', 'This service override is inactive; effective fallback pricing applies.'];
        }
        if ($this->effective_from?->isFuture()) {
            return ['scheduled', 'This service override is not effective yet; fallback pricing applies.'];
        }
        if ($this->effective_until?->isPast()) {
            return ['expired', 'This service override has expired; fallback pricing applies.'];
        }

        return ['effective', null];
    }
}
