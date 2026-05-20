<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreEmployeeRequest;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Services\CorporateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCorporateEmployeeController extends Controller
{
    protected CorporateService $corporateService;

    public function __construct(CorporateService $corporateService)
    {
        $this->corporateService = $corporateService;
        $this->middleware('permission:corporates.manage');
    }

    public function index(Request $request, Corporate $corporate): JsonResponse
    {
        $query = CorporateEmployee::where('corporate_id', $corporate->id)
            ->with(['user', 'department', 'division', 'userContext.roles', 'locations']);

        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('first_name', 'like', '%' . $request->search . '%')
                  ->orWhere('last_name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('division_id')) {
            $query->where('division_id', $request->division_id);
        }

        if ($request->filled('role')) {
            $query->whereHas('userContext.roles', function ($roleQuery) use ($request) {
                $roleQuery->where('roles.name', $request->role);
            });
        }

        // Only apply is_active filter if explicitly set (not 'all')
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOL));
        }

        $perPage = (int) $request->get('per_page', 15);
        $employees = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => ['employees' => $employees],
        ]);
    }

    public function store(StoreEmployeeRequest $request, Corporate $corporate): JsonResponse
    {
        $employee = $this->corporateService->addEmployee($corporate, $request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Employee added successfully',
            'data'    => ['employee' => $employee->load(['user', 'department', 'division', 'userContext.roles', 'locations'])],
        ], 201);
    }

    public function show(Corporate $corporate, string $id): JsonResponse
    {
        $employee = CorporateEmployee::where('corporate_id', $corporate->id)
            ->with(['user', 'department', 'division', 'userContext.roles', 'locations'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => ['employee' => $employee],
        ]);
    }

    public function update(Request $request, Corporate $corporate, string $id): JsonResponse
    {
        $request->validate([
            'department_id'  => ['sometimes', 'uuid', 'exists:corporate_departments,id'],
            'division_id'    => ['nullable', 'uuid', 'exists:corporate_divisions,id'],
            'employee_code'  => ['nullable', 'string', 'max:50'],
            'first_name'     => ['sometimes', 'string', 'max:255'],
            'last_name'      => ['sometimes', 'string', 'max:255'],
            'phone'          => ['nullable', 'string', 'max:50'],
            'role'           => ['nullable', 'string', 'max:255'],
            'locations' => ['nullable', 'array'],
            'locations.*.id' => ['nullable', 'uuid', 'exists:corporate_employee_locations,id'],
            'locations.*.label' => ['nullable', 'string', 'max:100'],
            'locations.*.address' => ['nullable', 'string'],
            'locations.*.latitude' => ['nullable', 'numeric'],
            'locations.*.longitude' => ['nullable', 'numeric'],
            'locations.*.city' => ['nullable', 'string', 'max:100'],
            'locations.*.country' => ['nullable', 'string', 'max:100'],
            'locations.*.place_id' => ['nullable', 'string', 'max:255'],
            'locations.*.placeId' => ['nullable', 'string', 'max:255'],
            'locations.*.is_default_pickup' => ['nullable', 'boolean'],
            'locations.*.is_default_dropoff' => ['nullable', 'boolean'],
            'locations.*.is_active' => ['nullable', 'boolean'],
        ]);

        $employee = CorporateEmployee::where('corporate_id', $corporate->id)
            ->findOrFail($id);

        $employee = $this->corporateService->updateEmployee($employee, $request->only([
            'department_id', 'division_id', 'employee_code', 'first_name', 'last_name', 'phone', 'role', 'locations',
        ]));

        return response()->json([
            'status'  => 'success',
            'message' => 'Employee updated successfully',
            'data'    => ['employee' => $employee->load(['user', 'department', 'division', 'userContext.roles', 'locations'])],
        ]);
    }

    public function activate(Corporate $corporate, string $id): JsonResponse
    {
        $employee = CorporateEmployee::where('corporate_id', $corporate->id)
            ->findOrFail($id);

        if ($employee->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Employee is already active',
            ], 422);
        }

        $employee = $this->corporateService->toggleEmployeeStatus($employee);

        return response()->json([
            'status'  => 'success',
            'message' => 'Employee activated successfully',
            'data'    => ['employee' => $employee],
        ]);
    }

    public function deactivate(Corporate $corporate, string $id): JsonResponse
    {
        $employee = CorporateEmployee::where('corporate_id', $corporate->id)
            ->findOrFail($id);

        if (!$employee->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Employee is already inactive',
            ], 422);
        }

        $employee = $this->corporateService->toggleEmployeeStatus($employee);

        return response()->json([
            'status'  => 'success',
            'message' => 'Employee deactivated successfully',
            'data'    => ['employee' => $employee],
        ]);
    }

    public function assignRole(Request $request, Corporate $corporate, string $id): JsonResponse
    {
        $request->validate([
            'role' => ['required', 'string'],
        ]);

        $employee = CorporateEmployee::where('corporate_id', $corporate->id)
            ->findOrFail($id);

        $this->corporateService->assignEmployeeRole($employee, $request->role);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role assigned successfully',
            'data'    => ['employee' => $employee->load(['user', 'department', 'division', 'userContext.roles', 'locations'])],
        ]);
    }
}
