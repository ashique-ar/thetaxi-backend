<?php

namespace App\Http\Controllers\Api\Customer\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Mobile\UpdateDeviceRequest;
use App\Http\Resources\Customer\CustomerDeviceResource;
use App\Models\Customer;
use App\Services\Customer\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device Controller
 *
 * Handles device management endpoints for the rider (customer) mobile app.
 * Allows riders to register/update their device and push token so booking
 * events (e.g. driver assigned) can be delivered via FCM.
 */
class DeviceController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        $activeOnly = $request->boolean('active_only', false);

        $devices = $this->deviceService->getCustomerDevices($customer, $activeOnly);

        return response()->json([
            'status' => 'success',
            'data' => CustomerDeviceResource::collection($devices),
        ]);
    }

    public function update(UpdateDeviceRequest $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        $data = $request->validated();
        $data['ip_address'] = $request->ip();

        $device = $this->deviceService->registerDevice($customer, $data);

        return response()->json([
            'status' => 'success',
            'message' => 'Device information updated',
            'data' => new CustomerDeviceResource($device),
        ]);
    }

    public function updatePushToken(Request $request): JsonResponse
    {
        $request->validate([
            'device_uuid' => ['required', 'string', 'max:255'],
            'push_token' => ['required', 'string', 'max:500'],
            'push_provider' => ['nullable', 'string', 'in:fcm,apns'],
        ]);

        $customer = $this->getAuthenticatedCustomer($request);

        $device = $this->deviceService->updatePushToken(
            $customer,
            $request->input('device_uuid'),
            $request->input('push_token'),
            $request->input('push_provider')
        );

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found. Please register the device first.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Push token updated',
            'data' => new CustomerDeviceResource($device),
        ]);
    }

    public function deactivate(Request $request, string $deviceUuid): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);

        $success = $this->deviceService->deactivateDevice($customer, $deviceUuid);

        if (!$success) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Device deactivated',
        ]);
    }

    public function destroy(Request $request, string $deviceUuid): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);

        $success = $this->deviceService->removeDevice($customer, $deviceUuid);

        if (!$success) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Device removed',
        ]);
    }

    /**
     * Resolve the authenticated rider's Customer record, creating the
     * customer context if the user hasn't switched into it yet.
     */
    protected function getAuthenticatedCustomer(Request $request): Customer
    {
        $user = $request->user('api') ?: $request->user();
        $context = $user->switchToCustomerContext();

        return $context->context;
    }
}
