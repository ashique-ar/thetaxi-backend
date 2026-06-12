<?php
// app/Http/Controllers/Api/Vehicle/VehicleOwnerController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Http\Resources\Driver\DriverResource;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Vehicle\VehicleOwner;
use App\Http\Requests\Vehicle\VehicleOwner\CreateVehicleOwnerRequest;
use App\Http\Requests\Vehicle\VehicleOwner\UpdateVehicleOwnerRequest;
use App\Http\Resources\Vehicle\VehicleOwnerResource;
use App\Services\UserContextService;
use App\Services\PaymentMethodSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VehicleOwnerController extends Controller
{
    protected $contextService;

    public function __construct(UserContextService $contextService)
    {
        $this->contextService = $contextService;
        $this->middleware('permission:vehicle-owners.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-owners.create')->only(['store']);
        $this->middleware(middleware: 'permission:vehicle-owners.edit')->only(['update']);
        $this->middleware('permission:vehicle-owners.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleOwner::with(['user', 'type', 'paymentMethods']);
        if ($request->filled('search')) {
            $q->whereHas('user', function($query) use ($request) {
                $query->whereLikeInsensitive('first_name', $request->get('search'))
                      ->orWhereLikeInsensitive('last_name', $request->get('search'))
                      ->orWhereLikeInsensitive('email', $request->get('search'));
            });
        }
        return VehicleOwnerResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleOwnerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;

        try {
            $payload = DB::transaction(function () use ($data, $request) {
                $user = User::where('email', $data['email'])->first();
                $isExistingUser = (bool) $user;

                if (!$user) {
                    $user = User::create([
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'] ?? null,
                        'email' => $data['email'],
                        'password' => bcrypt($data['password'] ?? Str::random(12)),
                        'phone' => $data['phone'] ?? null,
                        'email_verified_at' => now(),
                        'is_active' => true,
                    ]);
                }

                $ownerContext = $this->contextService->switchContext(
                    $user,
                    'vehicle_owner',
                    $this->buildOwnerContextData($data, $request->user()->id)
                );

                $vehicleOwner = VehicleOwner::with(['user', 'paymentMethods'])->findOrFail(
                    $ownerContext->getAttribute('context_id')
                );

                if (array_key_exists('payment_methods', $data)) {
                    app(PaymentMethodSyncService::class)->syncMany($vehicleOwner, $data['payment_methods'] ?? [], $request->user()->id);
                    $vehicleOwner->load('paymentMethods');
                }

                $driver = null;
                if ($request->boolean('create_driver_profile')) {
                    $driver = $this->upsertDriverContext($user, $data, $request->user()->id);
                    $vehicleOwner->update(['driver_id' => $driver->id]);
                    $vehicleOwner->setRelation('driver', $driver);
                }

                return [
                    'message' => $isExistingUser
                        ? 'Vehicle owner context created for existing user'
                        : 'Vehicle owner created successfully',
                    'owner' => $vehicleOwner,
                    'driver' => $driver,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => $payload['message'],
                'data' => [
                    'owner' => new VehicleOwnerResource($payload['owner']),
                    'driver' => $payload['driver']
                        ? new DriverResource($payload['driver'])
                        : null,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create vehicle owner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(VehicleOwner $vehicleOwner): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => ['owner' => new VehicleOwnerResource($vehicleOwner)]]);
    }

    public function update(UpdateVehicleOwnerRequest $request, VehicleOwner $vehicleOwner): JsonResponse
    {
        try {
            $data = $request->validated();
            $updatedPayload = DB::transaction(function () use ($data, $request, $vehicleOwner) {
                $data['updated_user_id'] = $request->user()->id;

                $userData = array_intersect_key($data, array_flip([
                    'first_name', 'last_name', 'email', 'phone'
                ]));

                $vehicleOwnerData = array_diff_key($this->buildOwnerContextData($data, $request->user()->id), [
                    'roles' => true,
                    'payment_methods' => true,
                ]);

                if (!empty($userData)) {
                    $vehicleOwner->user->update($userData);
                }

                if (!empty($vehicleOwnerData)) {
                    $vehicleOwner->update($vehicleOwnerData);
                }

                if (array_key_exists('payment_methods', $data)) {
                    app(PaymentMethodSyncService::class)->syncMany($vehicleOwner, $data['payment_methods'] ?? [], $request->user()->id);
                }

                $driver = null;
                if ($request->boolean('create_driver_profile')) {
                    $driver = $this->upsertDriverContext(
                        $vehicleOwner->user,
                        $data,
                        $request->user()->id
                    );
                    $vehicleOwner->update(['driver_id' => $driver->id]);
                }

                $vehicleOwner->load(['user', 'driver.user', 'paymentMethods']);

                return [
                    'owner' => $vehicleOwner,
                    'driver' => $driver,
                ];
            });

            return response()->json([
                'status' => 'success', 
                'message' => 'Vehicle owner updated successfully', 
                'data' => [
                    'owner' => new VehicleOwnerResource($updatedPayload['owner']),
                    'driver' => $updatedPayload['driver']
                        ? new DriverResource($updatedPayload['driver'])
                        : null,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update vehicle owner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function buildOwnerContextData(array $data, string $actorUserId): array
    {
        return [
            'owner_type_id' => $data['owner_type_id'] ?? null,
            'address' => $data['address'] ?? null,
            'country_id' => $data['country_id'] ?? null,
            'state_id' => $data['state_id'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'dob' => $data['dob'] ?? null,
            'license_number' => $data['license_number'] ?? null,
            'license_expiry' => $data['license_expiry'] ?? null,
            'notes' => $data['notes'] ?? null,
            'updated_user_id' => $actorUserId,
        ];
    }

    private function buildDriverContextData(array $data, string $actorUserId): array
    {
        return [
            'code' => $data['driver_code'] ?? null,
            'nic' => $data['driver_nic'] ?? null,
            'license_no' => $data['license_number'] ?? null,
            'license_type' => $data['driver_license_type'] ?? null,
            'license_expiry' => $data['license_expiry'] ?? null,
            'dob' => $data['dob'] ?? null,
            'address' => $data['address'] ?? null,
            'country_id' => $data['country_id'] ?? null,
            'state_id' => $data['state_id'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'remarks' => $data['notes'] ?? null,
            'is_active' => $data['driver_is_active'] ?? true,
            'updated_user_id' => $actorUserId,
        ];
    }

    private function upsertDriverContext(User $user, array $data, string $actorUserId): Driver
    {
        $contextData = $this->buildDriverContextData($data, $actorUserId);
        $driverContext = $this->contextService->switchContext($user, 'driver', $contextData);
        $driver = Driver::findOrFail($driverContext->getAttribute('context_id'));
        $driver->fill($contextData);
        $driver->save();

        return $driver->load(['user', 'country', 'state', 'licenseType']);
    }

    public function destroy(VehicleOwner $vehicleOwner): JsonResponse
    {
        $vehicleOwner->delete();
        return response()->json(['status' => 'success', 'message' => 'Owner deleted']);
    }

    /**
     * Allow a vehicle owner to rent a car (switch to customer context)
     */
    public function switchToCustomer(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Switch to customer context
            $context = $this->contextService->switchContext($user, 'customer');
            
            return response()->json([
                'status' => 'success',
                'message' => 'Successfully switched to customer context',
                'data' => [
                    'context' => $context,
                    'available_contexts' => $this->contextService->getAvailableContexts($user)
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to switch context',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
