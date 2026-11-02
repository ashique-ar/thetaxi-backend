<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceType;
use App\Models\Service\ServiceVehicleClassSetting;
use App\Models\Vehicle\VehicleClass;
use App\Services\Pricing\PricingContextPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceVehicleClassController extends Controller
{
    public function __construct(private readonly PricingContextPolicyService $pricingPolicy)
    {
        $this->middleware('permission:service-types.view|vehicle-group-pricing.view|service-packages.view|service-packages.create|service-packages.edit')->only('index');
        $this->middleware('permission:service-types.edit')->only('update');
    }

    public function index(ServiceType $serviceType): JsonResponse
    {
        $serviceType = $this->pricingPolicy->effectiveServiceType($serviceType);
        $this->assertClassPriced($serviceType);
        $settings = ServiceVehicleClassSetting::withInactive()->where('service_type_id', $serviceType->id)->get()->keyBy('vehicle_class_id');

        return response()->json(['status' => 'success', 'data' => [
            'service_type_id' => $serviceType->id,
            'classes' => VehicleClass::query()->orderBy('name')->get(['id', 'name', 'description'])
                ->map(fn (VehicleClass $class) => [
                    'id' => $class->id, 'name' => $class->name, 'description' => $class->description,
                    'settings' => $settings->get($class->id),
                ]),
        ]]);
    }

    public function update(Request $request, ServiceType $serviceType, VehicleClass $vehicleClass): JsonResponse
    {
        $this->pricingPolicy->assertServiceTypeIsWritable($serviceType);
        $data = $request->validate([
            'max_passengers' => ['required', 'integer', 'min:1', 'max:65535'],
            'max_luggage' => ['required', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'is_hidden' => ['sometimes', 'boolean'],
            'is_inquiry_only' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'allowed_upgrade_class_ids' => ['nullable', 'array'],
            'allowed_upgrade_class_ids.*' => ['uuid', 'distinct', 'exists:vehicle_classes,id'],
            'allowed_substitute_class_ids' => ['nullable', 'array'],
            'allowed_substitute_class_ids.*' => ['uuid', 'distinct', 'exists:vehicle_classes,id'],
        ]);
        $setting = DB::transaction(function () use ($serviceType, $vehicleClass, $data): ServiceVehicleClassSetting {
            $service = ServiceType::query()->whereKey($serviceType->id)->lockForUpdate()->firstOrFail();
            $this->assertClassPriced($service);

            return ServiceVehicleClassSetting::withInactive()->updateOrCreate([
                'service_type_id' => $service->id, 'vehicle_class_id' => $vehicleClass->id,
            ], $data);
        });

        return response()->json(['status' => 'success', 'data' => $setting]);
    }

    private function assertClassPriced(ServiceType $service): void
    {
        if ($service->pricing_basis !== 'vehicle_class') {
            throw ValidationException::withMessages(['service_type_id' => 'Choose a class-priced service to configure vehicle classes.']);
        }
    }
}
