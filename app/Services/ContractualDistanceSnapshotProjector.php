<?php

namespace App\Services;

class ContractualDistanceSnapshotProjector
{
    /**
     * Project only customer-safe contractual fields saved in an immutable pricing snapshot.
     */
    public function project(array $snapshot): ?array
    {
        $pricing = is_array($snapshot['base_pricing'] ?? null) ? $snapshot['base_pricing'] : $snapshot;
        $policy = is_array($pricing['distance_policy'] ?? null) ? $pricing['distance_policy'] : null;
        $distances = is_array($pricing['distance_details'] ?? null) ? $pricing['distance_details'] : [];

        if (($policy['coordinate_source'] ?? null) !== 'corporate_distance_policy') {
            return null;
        }

        return [
            'source' => $policy['source'] ?? null,
            'policy_name' => $policy['policy_name'] ?? null,
            'mode' => $policy['mode'] ?? null,
            'rate_method' => $policy['rate_method'] ?? null,
            'defined_origin' => $this->location($policy['defined_origin'] ?? null),
            'defined_return' => $this->location($policy['defined_return'] ?? null),
            'origin_to_pickup_distance' => $this->nullableFloat($distances['origin_to_pickup_distance'] ?? null),
            'journey_distance' => $this->nullableFloat($distances['journey_distance'] ?? null),
            'dropoff_to_return_distance' => $this->nullableFloat($distances['dropoff_to_return_distance'] ?? null),
            'total_billable_distance' => $this->nullableFloat($distances['total_billable_distance'] ?? null),
            'movement_charge' => $this->nullableFloat($pricing['contractual_movement_charge'] ?? null),
            'calculated_at' => $policy['calculated_at'] ?? null,
        ];
    }

    /**
     * Internal pricing review adds stable policy audit fields, never formulas or operational data.
     */
    public function projectForInternal(array $snapshot): ?array
    {
        $projected = $this->project($snapshot);
        if (!$projected) {
            return null;
        }

        $pricing = is_array($snapshot['base_pricing'] ?? null) ? $snapshot['base_pricing'] : $snapshot;
        $policy = is_array($pricing['distance_policy'] ?? null) ? $pricing['distance_policy'] : [];

        return $projected + [
            'policy_id' => $policy['policy_id'] ?? null,
            'coordinate_source' => 'corporate_distance_policy',
            'include_origin_to_pickup' => (bool) ($policy['include_origin_to_pickup'] ?? false),
            'include_dropoff_to_return' => (bool) ($policy['include_dropoff_to_return'] ?? false),
            'maximum_outbound_km' => $this->nullableFloat($policy['maximum_outbound_km'] ?? null),
            'maximum_return_km' => $this->nullableFloat($policy['maximum_return_km'] ?? null),
        ];
    }

    private function location(mixed $location): ?array
    {
        if (!is_array($location)) {
            return null;
        }

        return [
            'address' => $location['address'] ?? null,
            'latitude' => $this->nullableFloat($location['latitude'] ?? null),
            'longitude' => $this->nullableFloat($location['longitude'] ?? null),
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
