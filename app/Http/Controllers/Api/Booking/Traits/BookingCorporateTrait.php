<?php

namespace App\Http\Controllers\Api\Booking\Traits;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

trait BookingCorporateTrait
{
    public function getCorporates(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 100), 200);

        $query = Corporate::query()->where('is_active', true)->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $corporates = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'corporates' => $corporates->getCollection()->map(fn (Corporate $corporate) => [
                    'id' => (string) $corporate->id,
                    'name' => $corporate->name,
                    'contact_email' => $corporate->contact_email,
                    'contact_phone' => $corporate->contact_phone,
                    'approval_required' => (bool) $corporate->approval_required,
                ])->values(),
                'pagination' => [
                    'current_page' => $corporates->currentPage(),
                    'last_page' => $corporates->lastPage(),
                    'per_page' => $corporates->perPage(),
                    'total' => $corporates->total(),
                ],
            ],
        ]);
    }

    public function getCorporateDepartments(Request $request, string $corporateId): JsonResponse
    {
        $departments = CorporateDepartment::query()
            ->where('corporate_id', $corporateId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CorporateDepartment $department) => [
                'id' => (string) $department->id,
                'name' => $department->name,
                'description' => $department->description,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => ['departments' => $departments],
        ]);
    }

    public function createCorporateDepartment(Request $request, string $corporateId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $corporate = Corporate::findOrFail($corporateId);
        $department = $this->corporateService->createDepartment($corporate, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Department created successfully',
            'data' => [
                'department' => [
                    'id' => (string) $department->id,
                    'name' => $department->name,
                    'description' => $department->description,
                ],
            ],
        ], 201);
    }

    public function getCorporateEmployees(Request $request, string $corporateId): JsonResponse
    {
        $user = $request->user();
        $ownCorporateEmployee = CorporateEmployee::query()
            ->where('corporate_id', $corporateId)
            ->where('user_id', $user?->id)
            ->where('is_active', true)
            ->first();

        $query = CorporateEmployee::query()
            ->where('corporate_id', $corporateId)
            ->where('is_active', true)
            ->with(['user', 'department', 'division', 'activeLocations'])
            ->orderBy('created_at');

        if (
            $ownCorporateEmployee
            && !$user->can('create_bookings_for_others')
            && !$user->can('corporates.manage')
        ) {
            $query->where('id', $ownCorporateEmployee->id);
        }

        if ($request->filled('search')) {
            $query->whereHas('user', function ($userQuery) use ($request) {
                $userQuery->where('first_name', 'like', '%' . $request->search . '%')
                    ->orWhere('last_name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%')
                    ->orWhere('phone', 'like', '%' . $request->search . '%');
            });
        }

        $employees = $query->get();
        $customerIdsByUserId = Customer::query()
            ->whereIn('user_id', $employees->pluck('user_id')->filter()->values())
            ->pluck('id', 'user_id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'employees' => $employees->map(function (CorporateEmployee $employee) use ($customerIdsByUserId) {
                    $user = $employee->user;

                    return [
                        'id' => (string) $employee->id,
                        'user_id' => (string) $employee->user_id,
                        'customer_id' => $customerIdsByUserId[$employee->user_id] ?? null,
                        'name' => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
                        'email' => $user?->email,
                        'phone' => $user?->phone,
                        'employee_code' => $employee->employee_code,
                        'department_id' => $employee->department_id ? (string) $employee->department_id : null,
                        'department' => $employee->department?->name,
                        'locations' => $employee->activeLocations->map(fn ($location) => [
                            'id' => (string) $location->id,
                            'label' => $location->label,
                            'address' => $location->address,
                            'latitude' => $location->latitude,
                            'longitude' => $location->longitude,
                            'city' => $location->city,
                            'country' => $location->country,
                            'place_id' => $location->place_id,
                            'is_default_pickup' => (bool) $location->is_default_pickup,
                            'is_default_dropoff' => (bool) $location->is_default_dropoff,
                            'is_active' => (bool) $location->is_active,
                        ])->values(),
                        'division_id' => $employee->division_id ? (string) $employee->division_id : null,
                        'division' => $employee->division?->name,
                        'user' => $user ? [
                            'id' => (string) $user->id,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'email' => $user->email,
                            'phone' => $user->phone,
                        ] : null,
                    ];
                })->values(),
            ],
        ]);
    }

    public function getCorporateDivisions(Request $request, string $corporateId, string $departmentId): JsonResponse
    {
        $department = CorporateDepartment::query()
            ->where('corporate_id', $corporateId)
            ->findOrFail($departmentId);

        $divisions = CorporateDivision::query()
            ->where('department_id', $department->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CorporateDivision $division) => [
                'id' => (string) $division->id,
                'name' => $division->name,
                'description' => $division->description,
                'department_id' => (string) $division->department_id,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => ['divisions' => $divisions],
        ]);
    }

    public function createCorporateDivision(Request $request, string $corporateId, string $departmentId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $department = CorporateDepartment::query()
            ->where('corporate_id', $corporateId)
            ->findOrFail($departmentId);

        $division = $this->corporateService->createDivision($department, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Division created successfully',
            'data' => [
                'division' => [
                    'id' => (string) $division->id,
                    'name' => $division->name,
                    'description' => $division->description,
                    'department_id' => (string) $division->department_id,
                ],
            ],
        ], 201);
    }

    public function createCorporateEmployee(Request $request, string $corporateId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'department_id' => [
                'required',
                'uuid',
                Rule::exists('corporate_departments', 'id')->where(function ($query) use ($corporateId) {
                    $query->where('corporate_id', $corporateId)->where('is_active', true);
                }),
            ],
            'division_id' => [
                'nullable',
                'uuid',
                Rule::exists('corporate_divisions', 'id')->where(function ($query) use ($request) {
                    $query->where('department_id', $request->input('department_id'))->where('is_active', true);
                }),
            ],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', 'string', 'max:255'],
        ]);

        $corporate = Corporate::findOrFail($corporateId);

        $employee = $this->corporateService->addEmployee($corporate, [
            ...$validated,
            'role' => $validated['role'] ?? 'Corporate_Employee',
        ]);

        $employee->load(['user', 'department', 'division']);
        $customer = Customer::query()->where('user_id', $employee->user_id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Employee created successfully',
            'data' => [
                'employee' => [
                    'id' => (string) $employee->id,
                    'user_id' => (string) $employee->user_id,
                    'customer_id' => $customer?->id ? (string) $customer->id : null,
                    'name' => trim(($employee->user?->first_name ?? '') . ' ' . ($employee->user?->last_name ?? '')),
                    'email' => $employee->user?->email,
                    'phone' => $employee->user?->phone,
                    'employee_code' => $employee->employee_code,
                    'department_id' => $employee->department_id ? (string) $employee->department_id : null,
                    'department' => $employee->department?->name,
                    'division_id' => $employee->division_id ? (string) $employee->division_id : null,
                    'division' => $employee->division?->name,
                    'user' => $employee->user ? [
                        'id' => (string) $employee->user->id,
                        'first_name' => $employee->user->first_name,
                        'last_name' => $employee->user->last_name,
                        'email' => $employee->user->email,
                        'phone' => $employee->user->phone,
                    ] : null,
                ],
            ],
        ], 201);
    }

    public function getCompanyLocations(): JsonResponse
    {
        try {
            $locations = $this->bookingFlowService->getCompanyLocations();

            return response()->json([
                'status' => 'success',
                'data' => $locations
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting company locations: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get company locations',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
