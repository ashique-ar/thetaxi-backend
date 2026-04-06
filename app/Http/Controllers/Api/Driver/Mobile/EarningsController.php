<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\MobileAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarningsController extends Controller
{
    public function __construct(
        private DriverAuthService $authService,
        private MobileAssignmentService $assignmentService
    ) {}

    public function summary(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());
            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'EARNINGS_NOT_DRIVER'
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->assignmentService->getEarningsSummary($driver),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve earnings summary',
                'error_code' => 'EARNINGS_SUMMARY_FAILED',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function daily(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date',
        ]);

        try {
            $driver = $this->authService->getDriver($request->user());
            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'EARNINGS_NOT_DRIVER'
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->assignmentService->getDailyEarnings($driver, (string) $request->query('date')),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve daily earnings',
                'error_code' => 'EARNINGS_DAILY_FAILED',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function range(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        try {
            $driver = $this->authService->getDriver($request->user());
            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'EARNINGS_NOT_DRIVER'
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->assignmentService->getRangeEarnings(
                    $driver,
                    (string) $request->query('from'),
                    (string) $request->query('to')
                ),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve earnings by range',
                'error_code' => 'EARNINGS_RANGE_FAILED',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
