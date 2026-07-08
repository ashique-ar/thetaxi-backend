<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreCorporateRequest;
use App\Http\Requests\Corporate\UpdateCorporateRequest;
use App\Models\Corporate\Corporate;
use App\Models\Service\ServiceType;
use App\Services\CorporateService;
use App\Http\Resources\ServiceTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CorporateController extends Controller
{
    protected CorporateService $corporateService;

    public function __construct(CorporateService $corporateService)
    {
        $this->corporateService = $corporateService;

        $this->middleware('permission:corporates.view')->only(['index', 'show', 'serviceTypes']);
        $this->middleware('permission:corporates.create|corporates.manage')->only(['store']);
        $this->middleware('permission:corporates.edit|corporates.manage')->only(['update']);
        $this->middleware('permission:corporates.manage')->only([
            'activate',
            'deactivate',
            'assignVehicleGroups',
            'removeVehicleGroup',
            'assignServiceTypes',
            'createInitialAdmin',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Corporate::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Only apply is_active filter if explicitly set (not 'all')
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOL));
        }

        $perPage = (int) $request->get('per_page', 15);
        $corporates = $query->paginate($perPage);

        return response()->json(
            $corporates
        );
    }

    public function store(StoreCorporateRequest $request): JsonResponse
    {
        $corporate = $this->corporateService->createCorporate(
            $request->validated() + ['created_user_id' => $request->user()->id]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Corporate created successfully',
            'data'    => ['corporate' => $corporate],
        ], 201);
    }

    public function show(Corporate $corporate): JsonResponse
    {
        $corporate->load(['departments', 'vehicleGroups']);

        return response()->json([
            'status' => 'success',
            'data'   => ['corporate' => $corporate],
        ]);
    }

    public function update(UpdateCorporateRequest $request, Corporate $corporate): JsonResponse
    {
        $corporate = $this->corporateService->updateCorporate(
            $corporate,
            $request->validated() + ['updated_user_id' => $request->user()->id]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Corporate updated successfully',
            'data'    => ['corporate' => $corporate],
        ]);
    }

    public function activate(Corporate $corporate): JsonResponse
    {
        if ($corporate->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Corporate is already active',
            ], 422);
        }

        $corporate = $this->corporateService->toggleCorporateStatus($corporate);

        return response()->json([
            'status'  => 'success',
            'message' => 'Corporate activated successfully',
            'data'    => ['corporate' => $corporate],
        ]);
    }

    public function deactivate(Corporate $corporate): JsonResponse
    {
        if (!$corporate->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Corporate is already inactive',
            ], 422);
        }

        $corporate = $this->corporateService->toggleCorporateStatus($corporate);

        return response()->json([
            'status'  => 'success',
            'message' => 'Corporate deactivated successfully',
            'data'    => ['corporate' => $corporate],
        ]);
    }

    public function assignVehicleGroups(Request $request, Corporate $corporate): JsonResponse
    {
        $request->validate([
            'vehicle_group_ids'   => ['required', 'array', 'size:3'],
            'vehicle_group_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('vehicle_groups', 'id')
                    ->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')),
            ],
        ], [
            'vehicle_group_ids.size' => 'Exactly 3 active vehicle groups must be assigned.',
            'vehicle_group_ids.*.distinct' => 'Each vehicle group may only be selected once.',
        ]);

        $this->corporateService->assignVehicleGroups($corporate, $request->vehicle_group_ids);

        return response()->json([
            'status'  => 'success',
            'message' => 'Vehicle groups assigned successfully',
            'data'    => ['vehicle_groups' => $corporate->vehicleGroups()->get()],
        ]);
    }

    public function removeVehicleGroup(Corporate $corporate, string $vehicleGroupId): JsonResponse
    {
        if ($corporate->vehicleGroups()->count() <= 3) {
            return response()->json([
                'status' => 'error',
                'message' => 'A corporate must have exactly 3 assigned vehicle groups. Assign a replacement before removing this group.',
            ], 422);
        }

        $this->corporateService->removeVehicleGroup($corporate, $vehicleGroupId);

        return response()->json([
            'status'  => 'success',
            'message' => 'Vehicle group removed successfully',
        ]);
    }

    public function serviceTypes(Corporate $corporate)
    {
        $assigned = $corporate->allServiceTypes()
            ->get()
            ->mapWithKeys(fn (ServiceType $serviceType) => [
                $serviceType->id => (bool) $serviceType->pivot->is_active,
            ]);

        $serviceTypes = ServiceType::withInactive()
            ->forContext('corporate')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->each(function (ServiceType $serviceType) use ($assigned): void {
                $serviceType->assigned_to_corporate = $assigned->has($serviceType->id);
                $serviceType->is_assigned_active = (bool) ($assigned[$serviceType->id] ?? false);
            });

        return ServiceTypeResource::collection($serviceTypes);
    }

    public function assignServiceTypes(Request $request, Corporate $corporate): JsonResponse
    {
        $validated = $request->validate([
            'service_type_ids' => ['required', 'array', 'min:1'],
            'service_type_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('service_types', 'id')->where(
                    fn ($query) => $query
                        ->where('context', 'corporate')
                        ->where('owner_type', '')
                        ->where('owner_id', '')
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                ),
            ],
        ]);

        $this->corporateService->assignServiceTypes(
            $corporate,
            $validated['service_type_ids'],
            $request->user()?->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Corporate services updated successfully',
            'data' => ['service_types' => $corporate->serviceTypes()->get()],
        ]);
    }

    public function createInitialAdmin(Request $request, Corporate $corporate): JsonResponse
    {
        $request->validate([
            'email'         => ['required', 'email', 'max:255'],
            'first_name'    => ['required', 'string', 'max:255'],
            'last_name'     => ['nullable', 'string', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'password'      => ['nullable', 'string', 'min:8'],
            'department_id' => ['required', 'uuid', 'exists:corporate_departments,id'],
        ]);

        $employee = $this->corporateService->addEmployee($corporate, [
            'email'         => $request->email,
            'first_name'    => $request->first_name,
            'last_name'     => $request->last_name,
            'phone'         => $request->phone,
            'password'      => $request->password,
            'department_id' => $request->department_id,
            'role'          => 'Corporate_Master_Admin',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Initial admin created successfully',
            'data'    => ['employee' => $employee->load(['user', 'department'])],
        ], 201);
    }
}
