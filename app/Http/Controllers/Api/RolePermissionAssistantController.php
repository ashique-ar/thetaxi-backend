<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RolePermissionAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolePermissionAssistantController extends Controller
{
    public function suggest(Request $request, RolePermissionAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'requirements' => ['required', 'string', 'min:10', 'max:2000'],
            'current_permissions' => ['sometimes', 'array', 'max:2000'],
            'current_permissions.*' => ['string'],
        ]);

        return response()->json(['status' => 'success', 'data' => $assistant->suggest($data['requirements'], $data['current_permissions'] ?? [])]);
    }
}
