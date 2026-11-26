<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Corporate\Corporate;
use App\Services\CorporatePortalPermission;
use App\Services\CorporateRoleStarterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateRoleController extends Controller
{
    public function __construct(private CorporateRoleStarterService $roleService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

        $corporate = Corporate::findOrFail($request->corporate_id);

        return response()->json([
            'status' => 'success',
            'data'   => ['roles' => $this->roleService->listRoles($corporate)],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:170'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', CorporateRoleStarterService::CORPORATE_PERMISSIONS)],
        ]);

        $corporate = Corporate::findOrFail($request->corporate_id);
        $role = $this->roleService->createRole($corporate, $validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role created successfully',
            'data'    => ['role' => $role],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

        $validated = $request->validate([
            'name'          => ['sometimes', 'string', 'max:170'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', CorporateRoleStarterService::CORPORATE_PERMISSIONS)],
        ]);

        $corporate = Corporate::findOrFail($request->corporate_id);
        $role = $this->roleService->updateRole($corporate, $id, $validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role updated successfully',
            'data'    => ['role' => $role],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

        $corporate = Corporate::findOrFail($request->corporate_id);
        $this->roleService->deleteRole($corporate, $id);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role deleted successfully',
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

        return response()->json([
            'status' => 'success',
            'data'   => ['permissions' => $this->roleService->listPermissions()],
        ]);
    }

    private function canManageCorporateRoles(Request $request): bool
    {
        return CorporatePortalPermission::allows($request, 'manage-roles');
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'You do not have permission to manage corporate roles.',
        ], 403);
    }
}
