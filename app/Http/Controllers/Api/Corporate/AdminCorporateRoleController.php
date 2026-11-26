<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Corporate\Corporate;
use App\Services\CorporateRoleStarterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCorporateRoleController extends Controller
{
    public function __construct(private CorporateRoleStarterService $roleService)
    {
        $this->middleware('permission:corporates.manage');
    }

    public function index(Corporate $corporate): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => ['roles' => $this->roleService->listRoles($corporate)],
        ]);
    }

    public function store(Request $request, Corporate $corporate): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:170'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', CorporateRoleStarterService::CORPORATE_PERMISSIONS)],
        ]);

        $role = $this->roleService->createRole($corporate, $validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role created successfully',
            'data'    => ['role' => $role],
        ], 201);
    }

    public function update(Request $request, Corporate $corporate, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['sometimes', 'string', 'max:170'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', CorporateRoleStarterService::CORPORATE_PERMISSIONS)],
        ]);

        $role = $this->roleService->updateRole($corporate, $id, $validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role updated successfully',
            'data'    => ['role' => $role],
        ]);
    }

    public function destroy(Corporate $corporate, string $id): JsonResponse
    {
        $this->roleService->deleteRole($corporate, $id);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role deleted successfully',
        ]);
    }

    public function permissions(Corporate $corporate): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => ['permissions' => $this->roleService->listPermissions()],
        ]);
    }
}
