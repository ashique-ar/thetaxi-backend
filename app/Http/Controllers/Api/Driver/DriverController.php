<?php
// app/Http/Controllers/Api/DriverController.php
namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Driver\Driver;
use App\Http\Requests\Driver\Driver\CreateDriverRequest;
use App\Http\Requests\Driver\Driver\UpdateDriverRequest;
use App\Http\Resources\Driver\DriverResource;
use App\Models\User;
use App\Services\UserContextService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DriverController extends Controller
{
    private $contextService;

    public function __construct(UserContextService $contextService)
    {
        $this->contextService = $contextService;
        $this->middleware('permission:drivers.view')->only(['index', 'show']);
        $this->middleware('permission:drivers.create')->only(['store']);
        $this->middleware('permission:drivers.edit')->only(['update']);
        $this->middleware('permission:drivers.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Driver::with('user');
        if ($request->filled('search')) {
            $q->where('code', 'like', '%' . $request->search . '%');
        }
        return DriverResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateDriverRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $existingUser = User::where('email', $data['email'])->first();

            if ($existingUser) {
                $existingContext = \App\Models\UserContext::where('user_id', $existingUser->id)
                    ->where('context_type', 'driver')
                    ->where('is_active', true)
                    ->first();

                if ($existingContext) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This user is already registered as a driver.',
                        'errors' => [
                            'email' => ['This email is already registered as a driver.']
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

                $context = $this->contextService->switchContext($existingUser, 'driver', $contextData);
                $driver = Driver::find($context->getAttribute('context_id'));

                return response()->json([
                    'status' => 'success',
                    'message' => 'Driver context created for existing user',
                    'data' => ['driver' => new DriverResource($driver)]
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
                    'license_no' => $data['license_no'] ?? null,
                    'license_type' => $data['license_type_id'] ?? null,
                    'license_expiry' => $data['license_expiry'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                    'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                    'blood_group' => $data['blood_group'] ?? null,
                    'medical_conditions' => $data['medical_conditions'] ?? null,
                    'hire_date' => $data['hire_date'] ?? null,
                    'is_active' => $data['is_active'] ?? null,
                ];

                $context = $this->contextService->switchContext($user, 'driver', $contextData);
                $driver = Driver::find($context->getAttribute('context_id'));

                return response()->json([
                    'status' => 'success',
                    'message' => 'Driver created successfully',
                    'data' => ['driver' => new DriverResource($driver)]
                ], 201);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create driver',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Driver $driver): JsonResponse
    {
        $driver->load(['user', 'country', 'state', 'licenseType']);
        return response()->json([
            'status' => 'success',
            'data' => new DriverResource($driver)
        ]);
    }

    public function update(UpdateDriverRequest $request, Driver $driver): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['updated_user_id'] = $request->user()->id;

            // Separate user data from vehicle owner data
            $userData = array_intersect_key($data, array_flip([
                'first_name',
                'last_name',
                'email',
                'phone'
            ]));

            $driverData = array_diff_key($data, $userData);

            // Update user data if provided
            if (!empty($userData)) {
                $driver->user->update($userData);
            }

            // Update driver data
            $driver->update($driverData);

            // Reload the relationship to get updated data
            $driver->load('user');

            return response()->json([
                'status' => 'success',
                'message' => 'Driver updated successfully',
                'data' => ['driver' => new DriverResource($driver)]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update vehicle owner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Driver $driver): JsonResponse
    {
        $driver->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Driver deleted'
        ]);
    }
}
