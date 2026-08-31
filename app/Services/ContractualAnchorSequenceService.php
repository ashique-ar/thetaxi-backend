<?php

namespace App\Services;

use App\Models\Corporate\CorporateContractLocation;
use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Models\Corporate\CorporateServiceDistancePolicy;
use DomainException;

class ContractualAnchorSequenceService
{
    public const TEMPLATES = ['full_movement', 'pickup_to_pickup', 'customer_base_to_customer_base', 'operator_base_to_dropoff', 'operator_base_to_operator_base', 'passenger_journey_only', 'custom_sequence'];
    public const TYPES = ['operator_location', 'customer_location', 'booked_pickup', 'booked_stop', 'booked_dropoff', 'named_contract_location', 'return_to_start'];

    public function resolve(CorporateDistancePricingPolicy $policy, ?CorporateServiceDistancePolicy $override, array $journeyPoints): array
    {
        if (count($journeyPoints) < 2) throw new DomainException('Passenger pickup and drop-off coordinates are required.');
        $template = $override?->route_template_override ?: ($policy->route_template ?: 'full_movement');
        if (! in_array($template, self::TEMPLATES, true)) throw new DomainException('Unsupported contractual route template.');
        $definition = $override?->route_anchor_sequence_override ?: $policy->route_anchor_sequence ?: $this->preset($template, $policy, $override);
        if (! is_array($definition) || count($definition) < 2) throw new DomainException('A contractual route requires at least two anchors.');

        $resolved = [];
        foreach (array_values($definition) as $anchor) {
            $type = $anchor['type'] ?? null;
            if (! in_array($type, self::TYPES, true)) throw new DomainException('Unsupported contractual anchor type.');
            if ($type === 'booked_stop') {
                foreach (array_slice($journeyPoints, 1, -1) as $stop) $resolved[] = $this->booked($stop, 'booked_stop', $anchor);
                continue;
            }
            $resolved[] = match ($type) {
                'booked_pickup' => $this->booked($journeyPoints[0], $type, $anchor),
                'booked_dropoff' => $this->booked($journeyPoints[array_key_last($journeyPoints)], $type, $anchor),
                'return_to_start' => $this->returnToStart($resolved, $anchor),
                default => $this->stored($policy->corporate_id, $type, $anchor),
            };
        }
        if (count($resolved) < 2) throw new DomainException('The resolved contractual route requires at least two anchors.');
        foreach ($resolved as $index => &$anchor) $anchor['sequence'] = $index + 1;

        return ['version' => max(2, (int) $policy->route_contract_version), 'template' => $template, 'anchors' => $resolved];
    }

    private function preset(string $template, CorporateDistancePricingPolicy $policy, ?CorporateServiceDistancePolicy $override): array
    {
        $start = $this->legacyLocation($override?->origin_location_override ?: ['address' => $policy->origin_address, 'latitude' => $policy->origin_latitude, 'longitude' => $policy->origin_longitude], 'Defined origin');
        $end = $this->legacyLocation($override?->return_location_override ?: ($override?->origin_location_override ?: $policy->resolvedReturnLocation()), 'Defined return');
        $start['billable_to_next'] = (bool) ($override?->include_origin_to_pickup ?? $policy->include_origin_to_pickup);
        $start['cap_km_to_next'] = $override?->maximum_outbound_km ?? $policy->maximum_outbound_km;
        $dropoff = ['type' => 'booked_dropoff', 'billable_to_next' => (bool) ($override?->include_dropoff_to_return ?? $policy->include_dropoff_to_return), 'cap_km_to_next' => $override?->maximum_return_km ?? $policy->maximum_return_km];
        return match ($template) {
            'full_movement' => [$start, ['type' => 'booked_pickup'], ['type' => 'booked_stop'], $dropoff, $end],
            'pickup_to_pickup' => [['type' => 'booked_pickup'], ['type' => 'booked_stop'], ['type' => 'booked_dropoff'], ['type' => 'return_to_start']],
            'passenger_journey_only' => [['type' => 'booked_pickup'], ['type' => 'booked_stop'], ['type' => 'booked_dropoff']],
            'operator_base_to_dropoff' => [$start, ['type' => 'booked_pickup'], ['type' => 'booked_stop'], ['type' => 'booked_dropoff']],
            default => throw new DomainException('This template requires an explicit ordered anchor sequence.'),
        };
    }

    private function legacyLocation(array $location, string $label): array
    {
        return ['type' => 'named_contract_location', 'owner_type' => 'corporate', 'label' => $label, 'location_snapshot' => $location];
    }

    private function booked(array $point, string $type, array $definition): array
    {
        $this->coordinates($point, str_replace('_', ' ', $type));
        return array_merge(['type' => $type, 'owner_type' => 'booking', 'owner_id' => null, 'label' => $definition['label'] ?? str($type)->replace('_', ' ')->title()->toString(), 'address' => $point['address'] ?? null, 'latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude']], $this->legOptions($definition));
    }

    private function stored(string $corporateId, string $type, array $definition): array
    {
        if ($snapshot = ($definition['location_snapshot'] ?? null)) {
            $this->coordinates($snapshot, 'contract location');
            return array_merge(['type' => $type, 'owner_type' => $definition['owner_type'] ?? 'corporate', 'owner_id' => $corporateId, 'location_id' => null, 'label' => $definition['label'] ?? ($snapshot['address'] ?? 'Contract location'), 'address' => $snapshot['address'] ?? null, 'latitude' => (float) $snapshot['latitude'], 'longitude' => (float) $snapshot['longitude']], $this->legOptions($definition));
        }
        $location = CorporateContractLocation::query()->whereKey($definition['location_id'] ?? null)->where('is_active', true)->first();
        if (! $location) throw new DomainException('A required contractual location is missing or inactive.');
        $valid = $type === 'operator_location'
            ? $location->owner_type === 'operator' && $location->corporate_id === null
            : $location->corporate_id === $corporateId && in_array($location->owner_type, ['corporate', 'customer', 'named_contract'], true);
        if (! $valid) throw new DomainException('The contractual location does not belong to the required owner.');
        return array_merge(['type' => $type], $location->snapshot(), $this->legOptions($definition));
    }

    private function returnToStart(array $resolved, array $definition): array
    {
        if (! $resolved) throw new DomainException('return_to_start cannot be the first contractual anchor.');
        return array_merge($resolved[0], ['type' => 'return_to_start', 'label' => $definition['label'] ?? 'Return to start'], $this->legOptions($definition));
    }

    private function legOptions(array $definition): array
    {
        return ['billable_to_next' => $definition['billable_to_next'] ?? true, 'rate_treatment_to_next' => $definition['rate_treatment_to_next'] ?? null, 'cap_km_to_next' => isset($definition['cap_km_to_next']) ? (float) $definition['cap_km_to_next'] : null];
    }

    private function coordinates(array $point, string $label): void
    {
        if (! is_numeric($point['latitude'] ?? null) || ! is_numeric($point['longitude'] ?? null)) throw new DomainException("The {$label} must have valid coordinates.");
    }
}
