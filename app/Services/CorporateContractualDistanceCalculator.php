<?php

namespace App\Services;

use DomainException;

class CorporateContractualDistanceCalculator
{
    private ContractualAnchorSequenceService $sequences;

    public function __construct(private CorporateDistancePolicyResolver $resolver, private GoogleMapsService $maps, ?ContractualAnchorSequenceService $sequences = null)
    {
        $this->sequences = $sequences ?? new ContractualAnchorSequenceService;
    }

    public function calculate(string $corporateId, string $serviceTypeId, array $journeyPoints): ?array
    {
        $resolved = $this->resolver->resolve($corporateId, $serviceTypeId);
        if (! $resolved['enabled']) return null;
        if ($resolved['error'] || ! $resolved['policy']) throw new DomainException($resolved['error'] ?: 'No effective contractual distance policy is available.');
        $policy = $resolved['policy'];
        $override = $resolved['override'];
        $routeContract = $this->sequences->resolve($policy, $override, $journeyPoints);
        $anchors = $routeContract['anchors'];
        $legs = [];
        foreach ($anchors as $index => $from) {
            if (! isset($anchors[$index + 1])) break;
            $to = $anchors[$index + 1];
            $routed = $this->route($from, $to, $this->legLabel($from, $to, $index));
            $passenger = in_array($from['type'], ['booked_pickup', 'booked_stop'], true) && in_array($to['type'], ['booked_stop', 'booked_dropoff'], true);
            $cap = $from['cap_km_to_next'] ?? null;
            $billable = (bool) ($from['billable_to_next'] ?? true);
            $legs[] = ['sequence' => $index + 1, 'from_anchor' => $this->snapshotAnchor($from), 'to_anchor' => $this->snapshotAnchor($to), 'distance_km' => round($routed['distance_km'], 3), 'duration_seconds' => $routed['duration_seconds'], 'billable' => $billable, 'billable_distance_km' => $billable ? round($this->cap($routed['distance_km'], $cap), 3) : 0.0, 'rate_treatment' => $from['rate_treatment_to_next'] ?? ($passenger ? 'passenger' : 'movement'), 'cap_km' => $cap, 'routing_reference' => $routed['reference'] ?? null];
        }

        $rateMethod = $override?->movement_rate_method ?? $policy->movement_rate_method;
        $passengerLegs = collect($legs)->where('rate_treatment', 'passenger');
        $movementLegs = collect($legs)->where('rate_treatment', 'movement');
        $journeyDistance = (float) $passengerLegs->sum('distance_km');
        $movementBillable = (float) $movementLegs->sum('billable_distance_km');
        $movementCharge = null;
        $totalDistance = $journeyDistance;
        if ($rateMethod === 'normal_rate') $totalDistance += $movementBillable;
        elseif ($rateMethod === 'separate_rate') {
            $outboundRate = $override?->outbound_rate ?? $policy->outbound_rate;
            $returnRate = $override?->return_rate ?? $policy->return_rate;
            if ($outboundRate === null || $returnRate === null) throw new DomainException('Separate-rate policies require outbound and return movement rates.');
            $values = $movementLegs->values();
            $movementCharge = round($values->sum(fn ($leg, $index) => $leg['billable_distance_km'] * (float) ($index === $values->count() - 1 && $values->count() > 1 ? $returnRate : $outboundRate)), 2);
        } elseif ($rateMethod !== 'included') throw new DomainException('Unsupported contractual movement rate method.');

        $firstMovement = $movementLegs->first();
        $lastMovement = $movementLegs->last();
        $legacyFull = $routeContract['template'] === 'full_movement';
        $outboundActual = $legacyFull ? (float) ($firstMovement['distance_km'] ?? 0) : 0.0;
        $returnActual = $legacyFull ? (float) ($lastMovement['distance_km'] ?? 0) : 0.0;
        $outboundBillable = $legacyFull ? (float) ($firstMovement['billable_distance_km'] ?? 0) : 0.0;
        $returnBillable = $legacyFull ? (float) ($lastMovement['billable_distance_km'] ?? 0) : 0.0;

        return [
            'pickup_distance' => round($outboundBillable, 2), 'origin_to_pickup_distance' => round($outboundActual, 2),
            'journey_distance' => round($journeyDistance, 2), 'delivery_distance' => round($returnBillable, 2),
            'dropoff_to_return_distance' => round($returnActual, 2), 'total_distance' => round($totalDistance, 2),
            'total_billable_distance' => round($totalDistance, 2), 'journey_duration_seconds' => (int) $passengerLegs->sum('duration_seconds'),
            'total_duration_seconds' => (int) collect($legs)->sum('duration_seconds'), 'include_garage_distance' => $rateMethod === 'normal_rate',
            'calculation_possible' => true, 'contractual_movement_charge' => $movementCharge,
            'contractual_route' => ['version' => $routeContract['version'], 'template' => $routeContract['template'], 'anchors' => array_map(fn ($anchor) => $this->snapshotAnchor($anchor), $anchors), 'legs' => $legs],
            'distance_policy' => [
                'source' => $resolved['source'], 'policy_id' => $policy->id, 'policy_name' => $policy->name,
                'mode' => $override?->application_mode ?? $policy->default_service_mode, 'rate_method' => $rateMethod,
                'route_contract_version' => $routeContract['version'], 'route_template' => $routeContract['template'],
                'resolved_contractual_anchors' => array_map(fn ($anchor) => $this->snapshotAnchor($anchor), $anchors),
                'resolved_contractual_legs' => $legs,
                'defined_origin' => $this->snapshotAnchor($anchors[0]), 'defined_return' => $this->snapshotAnchor($anchors[array_key_last($anchors)]),
                'include_origin_to_pickup' => (bool) $policy->include_origin_to_pickup, 'include_dropoff_to_return' => (bool) $policy->include_dropoff_to_return,
                'maximum_outbound_km' => $policy->maximum_outbound_km !== null ? (float) $policy->maximum_outbound_km : null,
                'maximum_return_km' => $policy->maximum_return_km !== null ? (float) $policy->maximum_return_km : null,
                'calculated_at' => now()->toISOString(), 'coordinate_source' => 'corporate_distance_policy',
            ],
        ];
    }

    private function route(array $from, array $to, string $leg): array
    {
        if ($this->sameCoordinates($from, $to)) return ['distance_km' => 0.0, 'duration_seconds' => 0, 'reference' => null];
        $route = $this->maps->distanceAndDuration($from, $to);
        if (! isset($route['distance_km'], $route['duration_seconds']) || ((float) $route['distance_km'] <= 0 && (int) $route['duration_seconds'] <= 0)) throw new DomainException("Unable to route {$leg}.");
        return ['distance_km' => (float) $route['distance_km'], 'duration_seconds' => (int) $route['duration_seconds'], 'reference' => $route['reference'] ?? null];
    }

    private function snapshotAnchor(array $anchor): array { return collect($anchor)->only(['sequence', 'type', 'owner_type', 'owner_id', 'location_id', 'label', 'address', 'latitude', 'longitude'])->all(); }
    private function legLabel(array $from, array $to, int $index): string
    {
        if ($index === 0 && $to['type'] === 'booked_pickup') return 'defined origin to passenger pickup';
        if ($from['type'] === 'booked_dropoff' && ! in_array($to['type'], ['booked_stop', 'booked_dropoff'], true)) return 'passenger drop-off to defined return';
        if (in_array($from['type'], ['booked_pickup', 'booked_stop'], true) && in_array($to['type'], ['booked_stop', 'booked_dropoff'], true)) return 'passenger journey';
        return 'contractual leg '.($index + 1);
    }
    private function cap(float $distance, mixed $maximum): float { return $maximum !== null ? min($distance, (float) $maximum) : $distance; }
    private function sameCoordinates(array $from, array $to): bool { return abs((float) $from['latitude'] - (float) $to['latitude']) < 0.0000001 && abs((float) $from['longitude'] - (float) $to['longitude']) < 0.0000001; }
}
