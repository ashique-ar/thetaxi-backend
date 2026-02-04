<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Driver\DriverAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver Mobile Assignment Controller (Placeholder)
 * 
 * Placeholder controller for future assignment functionality.
 * Currently returns empty arrays/null to support the API contract
 * while the assignment feature is being developed.
 * 
 * @see Requirement 10.3 - Placeholder endpoints for /api/driver/assignments
 */
class AssignmentController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param DriverAuthService $authService
     */
    public function __construct(
        private DriverAuthService $authService
    ) {}

    /**
     * List all assignments for the authenticated driver.
     * 
     * Placeholder: Returns an empty array until assignment feature is implemented.
     *
     * @param Request $request
     * @return JsonResponse
     * 
     * @see Requirement 10.3 - Return empty arrays for placeholder endpoints
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'ASSIGNMENT_NOT_DRIVER'
                ], 403);
            }

            // Placeholder: Return empty array
            // TODO: Implement actual assignment listing when feature is ready
            return response()->json([
                'status' => 'success',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 15,
                    'total' => 0,
                ],
                'message' => 'Assignment feature coming soon'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve assignments',
                'error_code' => 'ASSIGNMENT_LIST_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the current active assignment for the authenticated driver.
     * 
     * Placeholder: Returns null until assignment feature is implemented.
     *
     * @param Request $request
     * @return JsonResponse
     * 
     * @see Requirement 10.3 - Return null for current assignment placeholder
     */
    public function current(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'ASSIGNMENT_NOT_DRIVER'
                ], 403);
            }

            // Placeholder: Return null for current assignment
            // TODO: Implement actual current assignment retrieval when feature is ready
            return response()->json([
                'status' => 'success',
                'data' => null,
                'message' => 'No active assignment'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve current assignment',
                'error_code' => 'ASSIGNMENT_CURRENT_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
