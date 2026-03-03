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
            ->with(['user', 'department', 'division']);

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

        if ($request->filled('is_active')) {
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
            'data'    => ['employee' => $employee->load(['user', 'department', 'division'])],
        ], 201);
    }

    public function show(Corporate $corporate, string $id): JsonResponse
    {
        $employee = CorporateEmployee::where('corporate_id', $corporate->id)
            ->with(['user', 'department', 'division', 'userContext'])
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
        ]);

        $employee = CorporateEmployee::where('corporate_id', $corporate->id)
            ->findOrFail($id);

        $employee = $this->corporateService->updateEmployee($employee, $request->only([
            'department_id', 'division_id', 'employee_code',
        ]));

        return response()->json([
            'status'  => 'success',
            'message' => 'Employee updated successfully',
            'data'    => ['employee' => $employee->load(['user', 'department', 'division'])],
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
            'data'    => ['employee' => $employee->load(['user', 'department', 'division'])],
        ]);
    }
}
