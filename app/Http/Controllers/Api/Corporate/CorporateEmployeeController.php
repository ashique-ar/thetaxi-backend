<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreEmployeeRequest;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\User;
use App\Services\CorporateService;
use App\Services\CorporateAccessLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

class CorporateEmployeeController extends Controller
{
    protected CorporateService $corporateService;

    public function __construct(CorporateService $corporateService)
    {
        $this->corporateService = $corporateService;
        $this->middleware('permission:manage_employees');
    }

    public function index(Request $request): JsonResponse
    {
        $query = CorporateEmployee::where('corporate_id', $request->corporate_id)
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

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $corporate = Corporate::findOrFail($request->corporate_id);

        $employee = $this->corporateService->addEmployee($corporate, $request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Employee added successfully',
            'data'    => ['employee' => $employee->load(['user', 'department', 'division', 'userContext.roles', 'locations'])],
        ], 201);
    }

    public function importTemplate(Request $request)
    {
        $department = CorporateDepartment::where('corporate_id', $request->corporate_id)->where('is_active', true)->with('divisions')->first();
        $rows = [
            ['email', 'first_name', 'last_name', 'phone', 'employee_code', 'department', 'division', 'role'],
            ['jane@example.com', 'Jane', 'Perera', '0771234567', 'EMP-001', $department?->name ?? 'Operations', $department?->divisions->first()?->name ?? '', 'Corporate Employee'],
        ];

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            foreach ($rows as $row) fputcsv($output, $row);
            fclose($output);
        }, 'corporate-employees-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $corporate = Corporate::findOrFail($request->corporate_id);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headers = array_map(fn($value) => Str::lower(trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B")), fgetcsv($handle) ?: []);
        $required = ['email', 'first_name', 'last_name', 'department', 'role'];
        if (array_diff($required, $headers)) {
            fclose($handle);
            return response()->json(['status' => 'error', 'message' => 'CSV headers must include: '.implode(', ', $required)], 422);
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $seenEmails = [];
        $rowNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($rowNumber > 2001) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Import is limited to 2,000 employees per file.'];
                break;
            }
            $values = array_pad($values, count($headers), '');
            $row = array_map('trim', array_combine($headers, array_slice($values, 0, count($headers))));
            if (!array_filter($row)) continue;
            $email = Str::lower($row['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'message' => 'Email is invalid.'];
                continue;
            }
            if (isset($seenEmails[$email]) || User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $seenEmails[$email] = true;
                $skipped++;
                continue;
            }

            $department = CorporateDepartment::where('corporate_id', $corporate->id)->where('is_active', true)->whereRaw('LOWER(name) = ?', [Str::lower($row['department'] ?? '')])->first();
            if (!$department) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'message' => 'Department was not found in this corporate account.'];
                continue;
            }
            $division = null;
            if (!empty($row['division'])) {
                $division = CorporateDivision::where('department_id', $department->id)->where('is_active', true)->whereRaw('LOWER(name) = ?', [Str::lower($row['division'])])->first();
                if (!$division) {
                    $errors[] = ['row' => $rowNumber, 'email' => $email, 'message' => 'Division was not found under the selected department.'];
                    continue;
                }
            }
            if (empty($row['first_name']) || empty($row['last_name']) || empty($row['role'])) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'message' => 'First name, last name, and role are required.'];
                continue;
            }
            $roleName = Str::of($row['role'])->trim()->replace([' ', '-'], '_')->toString();
            $role = app(\App\Services\CorporateRoleStarterService::class)->resolve($corporate, $roleName);
            if (!$role) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'message' => 'Role was not found in the corporate role list.'];
                continue;
            }

            try {
                $this->corporateService->addEmployee($corporate, [
                    'email' => $email,
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'phone' => ($row['phone'] ?? '') ?: null,
                    'employee_code' => ($row['employee_code'] ?? '') ?: null,
                    'department_id' => $department->id,
                    'division_id' => $division?->id,
                    'role' => $role->name,
                ]);
                $seenEmails[$email] = true;
                $created++;
            } catch (Throwable $error) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'message' => $error->getMessage()];
            }
        }
        fclose($handle);

        return response()->json(['status' => 'success', 'message' => "Imported {$created} employees; skipped {$skipped} existing email accounts.", 'data' => ['created' => $created, 'skipped' => $skipped, 'failed' => count($errors), 'errors' => $errors]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $employee = CorporateEmployee::where('corporate_id', $request->corporate_id)
            ->with(['user', 'department', 'division', 'userContext.roles', 'locations'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => ['employee' => $employee],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'department_id'  => ['sometimes', 'uuid', 'exists:corporate_departments,id'],
            'division_id'    => ['nullable', 'uuid', 'exists:corporate_divisions,id'],
            'employee_code'  => ['nullable', 'string', 'max:50'],
            'first_name'     => ['sometimes', 'string', 'max:255'],
            'last_name'      => ['sometimes', 'nullable', 'string', 'max:255'],
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

        $employee = CorporateEmployee::where('corporate_id', $request->corporate_id)
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

    public function activate(Request $request, string $id): JsonResponse
    {
        $employee = CorporateEmployee::where('corporate_id', $request->corporate_id)
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

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $employee = CorporateEmployee::where('corporate_id', $request->corporate_id)
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

    public function assignRole(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'role' => ['required', 'string'],
        ]);

        $employee = CorporateEmployee::where('corporate_id', $request->corporate_id)
            ->findOrFail($id);

        $this->corporateService->assignEmployeeRole($employee, $request->role);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role assigned successfully',
            'data'    => ['employee' => $employee->load(['user', 'department', 'division', 'userContext.roles', 'locations'])],
        ]);
    }

    public function sendAccessLink(Request $request, string $id, CorporateAccessLinkService $access): JsonResponse
    {
        $employee = CorporateEmployee::where('corporate_id', $request->corporate_id)->findOrFail($id);
        $access->send($employee);

        return response()->json(['status' => 'success', 'message' => 'Password setup link queued for delivery.']);
    }
}
