<?php
// app/Http/Controllers/Api/Vehicle/VehicleOwnerController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle\VehicleOwner;
use App\Http\Requests\Vehicle\VehicleOwner\CreateVehicleOwnerRequest;
use App\Http\Requests\Vehicle\VehicleOwner\UpdateVehicleOwnerRequest;
use App\Http\Resources\Vehicle\VehicleOwnerResource;
use App\Services\UserContextService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
        $q = VehicleOwner::with('user');
        if ($request->filled('search')) {
            $q->whereHas('user', function($query) use ($request) {
                $query->where('first_name', 'like', '%' . $request->get('search') . '%')
                      ->orWhere('last_name', 'like', '%' . $request->get('search') . '%')
                      ->orWhere('email', 'like', '%' . $request->get('search') . '%');
            });
        }
        return VehicleOwnerResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleOwnerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;

        try {
            $existingUser = User::where('email', $data['email'])->first();
            
            if ($existingUser) {
                $existingContext = \App\Models\UserContext::where('user_id', $existingUser->id)
                    ->where('context_type', 'vehicle_owner')
                    ->where('is_active', true)
                    ->first();
                
                if ($existingContext) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This user is already registered as a vehicle owner.',
                        'errors' => [
                            'email' => ['This email is already registered as a vehicle owner.']
                        ]
                    ], 422);
                }

                $contextData = [
                    'owner_type_id' => $data['owner_type_id'] ?? null,
                    'address' => $data['address'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'city' => $data['city'] ?? null,
                    'license_number' => $data['license_number'] ?? null,
                    'license_expiry' => $data['license_expiry'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ];

                $context = $this->contextService->switchContext($existingUser, 'vehicle_owner', $contextData);
                $vehicleOwner = VehicleOwner::find($context->getAttribute('context_id'));

                return response()->json([
                    'status' => 'success', 
                    'message' => 'Vehicle owner context created for existing user', 
                    'data' => ['owner' => new VehicleOwnerResource($vehicleOwner)]
                ], 201);

            } else {
                $user = User::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'password' => bcrypt($data['password'] ?? Str::random(12)),
                    'phone' => $data['phone'],
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]);

                $contextData = [
                    'owner_type_id' => $data['owner_type_id'] ?? null,
                    'address' => $data['address'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'city' => $data['city'] ?? null,
                    'license_number' => $data['license_number'] ?? null,
                    'license_expiry' => $data['license_expiry'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ];

                $context = $this->contextService->switchContext($user, 'vehicle_owner', $contextData);
                $vehicleOwner = VehicleOwner::find($context->getAttribute('context_id'));

                return response()->json([
                    'status' => 'success', 
                    'message' => 'Vehicle owner created successfully', 
                    'data' => ['owner' => new VehicleOwnerResource($vehicleOwner)]
                ], 201);
            }

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
            $data['updated_user_id'] = $request->user()->id;

            // Separate user data from vehicle owner data
            $userData = array_intersect_key($data, array_flip([
                'first_name', 'last_name', 'email', 'phone'
            ]));

            $vehicleOwnerData = array_diff_key($data, $userData);

            // Update user data if provided
            if (!empty($userData)) {
                $vehicleOwner->user->update($userData);
            }

            // Update vehicle owner data
            $vehicleOwner->update($vehicleOwnerData);

            // Reload the relationship to get updated data
            $vehicleOwner->load('user');

            return response()->json([
                'status' => 'success', 
                'message' => 'Vehicle owner updated successfully', 
                'data' => ['owner' => new VehicleOwnerResource($vehicleOwner)]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update vehicle owner',
                'error' => $e->getMessage()
            ], 500);
        }
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
