<?php

namespace App\Services;

use DomainException;

class CorporateContractualDistanceCalculator
{
    public function __construct(
        private CorporateDistancePolicyResolver $resolver,
        private GoogleMapsService $maps,
    ) {}

    public function calculate(string $corporateId, string $serviceTypeId, array $journeyPoints): ?array
    {
        $resolved = $this->resolver->resolve($corporateId, $serviceTypeId);
        if (! $resolved['enabled']) {
            return null;
        }
        if ($resolved['error'] || ! $resolved['policy']) {
            throw new DomainException($resolved['error'] ?: 'No effective contractual distance policy is available.');
        }
        if (count($journeyPoints) < 2) {
            throw new DomainException('Passenger pickup and drop-off coordinates are required.');
        }

        $policy = $resolved['policy'];
        $override = $resolved['override'];
        $origin = $override?->origin_location_override ?: [
            'address' => $policy->origin_address,
            'latitude' => $policy->origin_latitude,
            'longitude' => $policy->origin_longitude,
        ];
        $return = $override?->return_location_override
            ?: ($override?->origin_location_override ?: $policy->resolvedReturnLocation());

        $this->requireCoordinates($origin, 'defined origin');
        $this->requireCoordinates($return, 'defined return location');
        foreach ($journeyPoints as $point) {
            $this->requireCoordinates($point, 'passenger route point');
        }

        $outbound = $this->route($origin, $journeyPoints[0], 'defined origin to passenger pickup');
        $journey = $this->routeThrough($journeyPoints);
        $returnLeg = $this->route($journeyPoints[array_key_last($journeyPoints)], $return, 'passenger drop-off to defined return');

        $includeOutbound = $override?->include_origin_to_pickup ?? $policy->include_origin_to_pickup;
        $includeReturn = $override?->include_dropoff_to_return ?? $policy->include_dropoff_to_return;
        $outboundCap = $override?->maximum_outbound_km ?? $policy->maximum_outbound_km;
        $returnCap = $override?->maximum_return_km ?? $policy->maximum_return_km;
        $billableOutbound = $includeOutbound ? $this->cap($outbound['distance_km'], $outboundCap) : 0.0;
        $billableReturn = $includeReturn ? $this->cap($returnLeg['distance_km'], $returnCap) : 0.0;
        $rateMethod = $override?->movement_rate_method ?? $policy->movement_rate_method;
        $movementCharge = null;
        $totalDistance = $journey['distance_km'];

        if ($rateMethod === 'normal_rate') {
            $totalDistance += $billableOutbound + $billableReturn;
        } elseif ($rateMethod === 'separate_rate') {
            $outboundRate = $override?->outbound_rate ?? $policy->outbound_rate;
            $returnRate = $override?->return_rate ?? $policy->return_rate;
            if ($outboundRate === null || $returnRate === null) {
                throw new DomainException('Separate-rate policies require outbound and return movement rates.');
            }
            $movementCharge = round(($billableOutbound * (float) $outboundRate) + ($billableReturn * (float) $returnRate), 2);
        } elseif ($rateMethod !== 'included') {
            throw new DomainException('Unsupported contractual movement rate method.');
        }

        return [
            'pickup_distance' => round($billableOutbound, 2),
            'origin_to_pickup_distance' => round($outbound['distance_km'], 2),
            'journey_distance' => round($journey['distance_km'], 2),
            'delivery_distance' => round($billableReturn, 2),
            'dropoff_to_return_distance' => round($returnLeg['distance_km'], 2),
            'total_distance' => round($totalDistance, 2),
            'total_billable_distance' => round($totalDistance, 2),
            'journey_duration_seconds' => $journey['duration_seconds'],
            'total_duration_seconds' => $outbound['duration_seconds'] + $journey['duration_seconds'] + $returnLeg['duration_seconds'],
            'include_garage_distance' => $rateMethod === 'normal_rate',
            'calculation_possible' => true,
            'contractual_movement_charge' => $movementCharge,
            'distance_policy' => [
                'source' => $resolved['source'],
                'policy_id' => $policy->id,
                'policy_name' => $policy->name,
                'mode' => $override?->application_mode ?? $policy->default_service_mode,
                'rate_method' => $rateMethod,
                'defined_origin' => $origin,
                'defined_return' => $return,
                'include_origin_to_pickup' => (bool) $includeOutbound,
                'include_dropoff_to_return' => (bool) $includeReturn,
                'maximum_outbound_km' => $outboundCap !== null ? (float) $outboundCap : null,
                'maximum_return_km' => $returnCap !== null ? (float) $returnCap : null,
                'calculated_at' => now()->toISOString(),
                'coordinate_source' => 'corporate_distance_policy',
            ],
        ];
    }

    private function routeThrough(array $points): array
    {
        $distance = 0.0;
        $duration = 0;
        for ($index = 0; $index < count($points) - 1; $index++) {
            $segment = $this->route($points[$index], $points[$index + 1], 'passenger journey');
            $distance += $segment['distance_km'];
            $duration += $segment['duration_seconds'];
        }

        return ['distance_km' => round($distance, 3), 'duration_seconds' => $duration];
    }

    private function route(array $from, array $to, string $leg): array
    {
        if ($this->sameCoordinates($from, $to)) {
            return ['distance_km' => 0.0, 'duration_seconds' => 0];
        }

        $route = $this->maps->distanceAndDuration($from, $to);
        if (! isset($route['distance_km'], $route['duration_seconds'])
            || ((float) $route['distance_km'] <= 0 && (int) $route['duration_seconds'] <= 0)) {
            throw new DomainException("Unable to route {$leg}.");
        }

        return ['distance_km' => (float) $route['distance_km'], 'duration_seconds' => (int) $route['duration_seconds']];
    }

    private function requireCoordinates(array $location, string $label): void
    {
        if (! is_numeric($location['latitude'] ?? null) || ! is_numeric($location['longitude'] ?? null)) {
            throw new DomainException("The {$label} must have valid coordinates.");
        }
    }

    private function cap(float $distance, mixed $maximum): float
    {
        return $maximum !== null ? min($distance, (float) $maximum) : $distance;
    }

    private function sameCoordinates(array $from, array $to): bool
    {
        return abs((float) $from['latitude'] - (float) $to['latitude']) < 0.0000001
            && abs((float) $from['longitude'] - (float) $to['longitude']) < 0.0000001;
    }
}
