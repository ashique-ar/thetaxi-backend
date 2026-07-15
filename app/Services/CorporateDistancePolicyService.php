<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Http\Resources\Corporate\CorporateServiceDistancePolicyResource;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Models\Corporate\CorporateServiceDistancePolicy;
use App\Models\Service\ServiceType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorporateDistancePolicyService
{
    public function __construct(private CorporateDistancePolicyResolver $resolver) {}

    public function defaultPolicy(Corporate $corporate): ?CorporateDistancePricingPolicy
    {
        return $corporate->distancePricingPolicies()
            ->where('is_default', true)
            ->orderByDesc('effective_from')
            ->latest('created_at')
            ->first();
    }

    public function saveDefaultPolicy(Corporate $corporate, array $data): CorporateDistancePricingPolicy
    {
        return DB::transaction(function () use ($corporate, $data) {
            $policy = $this->defaultPolicy($corporate) ?? new CorporateDistancePricingPolicy;
            $before = $policy->exists ? $policy->toArray() : null;

            $policy->fill($data + ['is_default' => true]);
            $policy->corporate_id = $corporate->id;
            $policy->save();

            $this->audit($policy->wasRecentlyCreated ? 'create' : 'update', $policy, $before);

            return $policy->refresh();
        });
    }

    public function servicePolicies(Corporate $corporate): Collection
    {
        return $corporate->allServiceTypes()
            ->orderBy('service_types.name')
            ->get()
            ->map(function (ServiceType $service) use ($corporate) {
                $resolved = $this->resolver->resolve($corporate->id, $service->id);
                $latestOverride = $corporate->serviceDistancePolicies()
                    ->where('service_type_id', $service->id)
                    ->orderByDesc('effective_from')
                    ->latest('created_at')
                    ->first();
                $overrideProjection = $latestOverride
                    ? (new CorporateServiceDistancePolicyResource($latestOverride))->resolve()
                    : null;
                $policyProjection = $resolved['policy']
                    ? (new \App\Http\Resources\Corporate\CorporateDistancePolicyResource($resolved['policy']))->resolve()
                    : null;

                return [
                    'service_type' => [
                        'id' => $service->id,
                        'code' => $service->code,
                        'name' => $service->name,
                        'is_assigned' => (bool) $service->pivot->is_active,
                    ],
                    'application_mode' => $latestOverride?->application_mode ?? 'inherit',
                    'resolved_source' => $resolved['source'],
                    'resolved_enabled' => $resolved['enabled'],
                    'policy_id' => $resolved['policy']?->id,
                    'error' => $resolved['error'],
                    'configuration_status' => $overrideProjection['configuration_status']
                        ?? $policyProjection['configuration_status']
                        ?? ($resolved['error'] ? 'incomplete' : 'normal'),
                    'warning' => $resolved['error']
                        ?? ($overrideProjection['warning'] ?? $policyProjection['warning'] ?? null),
                    'override' => $overrideProjection,
                ];
            });
    }

    public function saveServicePolicy(Corporate $corporate, ServiceType $serviceType, array $data): CorporateServiceDistancePolicy
    {
        $assigned = $corporate->allServiceTypes()
            ->where('service_types.id', $serviceType->id)
            ->wherePivot('is_active', true)
            ->exists();

        if (! $assigned) {
            throw ValidationException::withMessages([
                'service_type_id' => 'The selected service is not active for this corporate.',
            ]);
        }

        if (! empty($data['policy_id'])) {
            $ownedPolicy = $corporate->distancePricingPolicies()->whereKey($data['policy_id'])->exists();
            if (! $ownedPolicy) {
                throw ValidationException::withMessages([
                    'policy_id' => 'The selected distance policy does not belong to this corporate.',
                ]);
            }
        }

        return DB::transaction(function () use ($corporate, $serviceType, $data) {
            $override = $corporate->serviceDistancePolicies()
                ->where('service_type_id', $serviceType->id)
                ->orderByDesc('effective_from')
                ->latest('created_at')
                ->first() ?? new CorporateServiceDistancePolicy;
            $before = $override->exists ? $override->toArray() : null;

            $override->fill($data);
            $override->corporate_id = $corporate->id;
            $override->service_type_id = $serviceType->id;
            $override->save();

            $this->audit($override->wasRecentlyCreated ? 'create' : 'update', $override, $before);

            return $override->load('policy');
        });
    }

    private function audit(string $action, $model, ?array $before): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => "corporate_distance_policy_{$action}",
            'entity' => class_basename($model),
            'entity_id' => $model->id,
            'timestamp' => now(),
            'details' => [
                'corporate_id' => $model->corporate_id,
                'before' => $before,
                'after' => $model->fresh()->toArray(),
            ],
        ]);
    }
}
