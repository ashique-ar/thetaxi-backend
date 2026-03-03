<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreCorporateRateChartRequest;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateRateChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateRateChartController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:corporates.manage');
    }

    public function index(Request $request, Corporate $corporate): JsonResponse
    {
        $query = $corporate->rateCharts();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOL));
        }

        $rateCharts = $query->get();

        return response()->json([
            'status' => 'success',
            'data'   => ['rate_charts' => $rateCharts],
        ]);
    }

    public function store(StoreCorporateRateChartRequest $request, Corporate $corporate): JsonResponse
    {
        $rateChart = $corporate->rateCharts()->create(
            $request->validated() + [
                'created_user_id' => $request->user()->id,
            ]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Rate chart created successfully',
            'data'    => ['rate_chart' => $rateChart],
        ], 201);
    }

    public function update(StoreCorporateRateChartRequest $request, Corporate $corporate, CorporateRateChart $rateChart): JsonResponse
    {
        if ($rateChart->corporate_id !== $corporate->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Rate chart does not belong to this corporate',
            ], 404);
        }

        $rateChart->update(
            $request->validated() + [
                'updated_user_id' => $request->user()->id,
            ]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Rate chart updated successfully',
            'data'    => ['rate_chart' => $rateChart],
        ]);
    }

    public function destroy(Corporate $corporate, CorporateRateChart $rateChart): JsonResponse
    {
        if ($rateChart->corporate_id !== $corporate->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Rate chart does not belong to this corporate',
            ], 404);
        }

        $rateChart->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Rate chart deleted successfully',
        ]);
    }
}
