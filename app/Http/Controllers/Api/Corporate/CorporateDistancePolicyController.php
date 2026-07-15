<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\PreviewCorporateDistancePolicyRequest;
use App\Http\Requests\Corporate\UpsertCorporateDistancePolicyRequest;
use App\Http\Requests\Corporate\UpsertCorporateServiceDistancePolicyRequest;
use App\Http\Resources\Corporate\CorporateDistancePolicyResource;
use App\Http\Resources\Corporate\CorporateServiceDistancePolicyResource;
use App\Models\Corporate\Corporate;
use App\Models\Service\ServiceType;
use App\Services\BookingFlowService;
use App\Services\CorporateDistancePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CorporateDistancePolicyController extends Controller
{
    public function __construct(
        private CorporateDistancePolicyService $service,
        private BookingFlowService $bookingFlow,
    ) {
        $this->middleware('permission:corporates.view')->only(['show', 'services', 'preview']);
        $this->middleware('permission:corporates.edit|corporates.manage')->only(['update', 'updateService']);
    }

    public function show(Corporate $corporate): JsonResponse
    {
        $policy = $this->service->defaultPolicy($corporate);

        return response()->json([
            'status' => 'success',
            'data' => ['policy' => $policy ? new CorporateDistancePolicyResource($policy) : null],
        ]);
    }

    public function update(UpsertCorporateDistancePolicyRequest $request, Corporate $corporate): JsonResponse
    {
        $policy = $this->service->saveDefaultPolicy($corporate, $request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Defined-location distance policy saved successfully.',
            'data' => ['policy' => new CorporateDistancePolicyResource($policy)],
        ]);
    }

    public function services(Corporate $corporate): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['services' => $this->service->servicePolicies($corporate)],
        ]);
    }

    public function preview(PreviewCorporateDistancePolicyRequest $request, Corporate $corporate): JsonResponse
    {
        $data = $request->validated();
        $serviceAssigned = $corporate->allServiceTypes()
            ->where('service_types.id', $data['service_type_id'])
            ->wherePivot('is_active', true)
            ->exists();
        $vehicleGroupAssigned = $corporate->vehicleGroups()
            ->where('vehicle_groups.id', $data['vehicle_group_id'])
            ->exists();

        if (! $serviceAssigned || ! $vehicleGroupAssigned) {
            throw ValidationException::withMessages([
                'pricing_scope' => 'The selected service and vehicle group must be active for this corporate.',
            ]);
        }

        $pricing = $this->bookingFlow->calculateDynamicPricing([
            'service_type_id' => $data['service_type_id'],
            'vehicle_group_id' => $data['vehicle_group_id'],
            'corporate_account_id' => $corporate->id,
            'pickup_location' => $data['pickup'],
            'dropoff_location' => $data['dropoff'],
            'duration_days' => 1,
            'duration_hours' => 24,
            'mode' => 'preview',
            'is_preview_calculation' => true,
        ]);

        if (empty($pricing['distance_policy'])) {
            throw ValidationException::withMessages([
                'distance_policy' => 'Defined-location distance pricing is not enabled for the selected service.',
            ]);
        }

        $distance = $pricing['distance_details'] ?? [];

        return response()->json([
            'status' => 'success',
            'data' => [
                'origin_to_pickup_distance' => $distance['origin_to_pickup_distance'] ?? null,
                'journey_distance' => $distance['journey_distance'] ?? null,
                'dropoff_to_return_distance' => $distance['dropoff_to_return_distance'] ?? null,
                'total_billable_distance' => $distance['total_billable_distance'] ?? null,
                'contractual_movement_charge' => $pricing['contractual_movement_charge'] ?? null,
                'estimated_charge' => $pricing['total_amount'] ?? null,
                'currency' => $pricing['currency'] ?? 'LKR',
                'distance_policy' => $pricing['distance_policy'],
            ],
        ]);
    }

    public function updateService(
        UpsertCorporateServiceDistancePolicyRequest $request,
        Corporate $corporate,
        ServiceType $serviceType,
    ): JsonResponse {
        $override = $this->service->saveServicePolicy($corporate, $serviceType, $request->validated());
        $resolved = app(\App\Services\CorporateDistancePolicyResolver::class)
            ->resolve($corporate->id, $serviceType->id);
        $overrideProjection = (new CorporateServiceDistancePolicyResource($override))->resolve();

        return response()->json([
            'status' => 'success',
            'message' => 'Service distance policy saved successfully.',
            'data' => [
                'service_type_id' => $serviceType->id,
                'application_mode' => $override->application_mode,
                'resolved_source' => $resolved['source'],
                'resolved_enabled' => $resolved['enabled'],
                'policy_id' => $resolved['policy']?->id,
                'error' => $resolved['error'],
                'configuration_status' => $overrideProjection['configuration_status'],
                'warning' => $resolved['error'] ?? $overrideProjection['warning'],
                'override' => $overrideProjection,
            ],
        ]);
    }
}
