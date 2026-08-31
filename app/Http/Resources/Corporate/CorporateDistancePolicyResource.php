<?php

namespace App\Http\Resources\Corporate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CorporateDistancePolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$configurationStatus, $warning] = $this->configurationStatus();

        return [
            'id' => $this->id,
            'corporate_id' => $this->corporate_id,
            'name' => $this->name,
            'is_default' => (bool) $this->is_default,
            'default_service_mode' => $this->default_service_mode,
            'route_contract_version' => max(2, (int) ($this->route_contract_version ?: 1)),
            'route_template' => $this->route_template ?: 'full_movement',
            'route_anchor_sequence' => $this->route_anchor_sequence,
            'defined_origin' => [
                'address' => $this->origin_address,
                'latitude' => $this->origin_latitude,
                'longitude' => $this->origin_longitude,
            ],
            'defined_return' => $this->resolvedReturnLocation(),
            'returns_to_defined_origin' => $this->return_latitude === null && $this->return_longitude === null,
            'include_origin_to_pickup' => (bool) $this->include_origin_to_pickup,
            'include_dropoff_to_return' => (bool) $this->include_dropoff_to_return,
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
            return ['incomplete', 'Separate-rate policies require outbound and return rates.'];
        }
        if (! $this->is_active) {
            return ['inactive', 'This company policy is inactive and will not be used.'];
        }
        if ($this->effective_from?->isFuture()) {
            return ['scheduled', 'This company policy is not effective yet.'];
        }
        if ($this->effective_until?->isPast()) {
            return ['expired', 'This company policy has expired.'];
        }

        return ['effective', null];
    }
}
