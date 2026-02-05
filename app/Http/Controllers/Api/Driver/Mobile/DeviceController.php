<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\UpdateDeviceRequest;
use App\Http\Resources\Driver\DriverDeviceResource;
use App\Services\Driver\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device Controller
 * 
 * Handles device management endpoints for the driver mobile app.
 * Allows drivers to update device info, push tokens, and view registered devices.
 */
class DeviceController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService
    ) {}

    /**
     * Get all devices registered for the authenticated driver.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $this->getAuthenticatedDriver($request);
        $activeOnly = $request->boolean('active_only', false);
        
        $devices = $this->deviceService->getDriverDevices($driver, $activeOnly);
        
        return response()->json([
            'status' => 'success',
            'data' => DriverDeviceResource::collection($devices),
        ]);
    }

    /**
     * Get the current device information.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function current(Request $request): JsonResponse
    {
        $driver = $this->getAuthenticatedDriver($request);
        $deviceUuid = $driver->current_device_uuid;
        
        if (!$deviceUuid) {
            return response()->json([
                'status' => 'error',
                'message' => 'No current device registered',
            ], 404);
        }
        
        $device = $this->deviceService->getDevice($driver, $deviceUuid);
        
        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found',
            ], 404);
        }
        
        return response()->json([
            'status' => 'success',
            'data' => new DriverDeviceResource($device),
        ]);
    }

    /**
     * Update device information.
     *
     * @param UpdateDeviceRequest $request
     * @return JsonResponse
     */
    public function update(UpdateDeviceRequest $request): JsonResponse
    {
        $driver = $this->getAuthenticatedDriver($request);
        $data = $request->validated();
        $data['ip_address'] = $request->ip();
        
        $device = $this->deviceService->registerDevice($driver, $data);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Device information updated',
            'data' => new DriverDeviceResource($device),
        ]);
    }

    /**
     * Update push notification token.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updatePushToken(Request $request): JsonResponse
    {
        $request->validate([
            'device_uuid' => ['required', 'string', 'max:255'],
            'push_token' => ['required', 'string', 'max:500'],
            'push_provider' => ['nullable', 'string', 'in:fcm,apns'],
        ]);
        
        $driver = $this->getAuthenticatedDriver($request);
        
        $device = $this->deviceService->updatePushToken(
            $driver,
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
            'data' => new DriverDeviceResource($device),
        ]);
    }

    /**
     * Deactivate a device.
     *
     * @param Request $request
     * @param string $deviceUuid
     * @return JsonResponse
     */
    public function deactivate(Request $request, string $deviceUuid): JsonResponse
    {
        $driver = $this->getAuthenticatedDriver($request);
        
        $success = $this->deviceService->deactivateDevice($driver, $deviceUuid);
        
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

    /**
     * Remove a device (soft delete).
     *
     * @param Request $request
     * @param string $deviceUuid
     * @return JsonResponse
     */
    public function destroy(Request $request, string $deviceUuid): JsonResponse
    {
        $driver = $this->getAuthenticatedDriver($request);
        
        // Prevent removing the current device
        if ($driver->current_device_uuid === $deviceUuid) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot remove the currently active device',
            ], 400);
        }
        
        $success = $this->deviceService->removeDevice($driver, $deviceUuid);
        
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
     * Get the authenticated driver from the request.
     *
     * @param Request $request
     * @return \App\Models\Driver\Driver
     */
    protected function getAuthenticatedDriver(Request $request)
    {
        $user = $request->user();
        return $user->driverContext()->context;
    }
}
