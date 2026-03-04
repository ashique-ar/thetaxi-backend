<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreDivisionRequest;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Services\CorporateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateDivisionController extends Controller
{
    protected CorporateService $corporateService;

    public function __construct(CorporateService $corporateService)
    {
        $this->corporateService = $corporateService;
        $this->middleware('permission:manage_divisions');
    }

    public function index(Request $request, string $departmentId): JsonResponse
    {
        $department = CorporateDepartment::where('corporate_id', $request->corporate_id)
            ->findOrFail($departmentId);

        $query = $department->divisions();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Only apply is_active filter if explicitly set (not 'all')
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOL));
        }

        $perPage = (int) $request->get('per_page', 15);
        $divisions = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => ['divisions' => $divisions],
        ]);
    }

    public function store(StoreDivisionRequest $request, string $departmentId): JsonResponse
    {
        $department = CorporateDepartment::where('corporate_id', $request->corporate_id)
            ->findOrFail($departmentId);

        $division = $this->corporateService->createDivision($department, [
            'name'            => $request->name,
            'description'     => $request->description,
            'created_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Division created successfully',
            'data'    => ['division' => $division],
        ], 201);
    }

    public function update(StoreDivisionRequest $request, string $id): JsonResponse
    {
        $division = CorporateDivision::whereHas('department', function ($q) use ($request) {
            $q->where('corporate_id', $request->corporate_id);
        })->findOrFail($id);

        $division = $this->corporateService->updateDivision($division, [
            'name'            => $request->name,
            'description'     => $request->description,
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Division updated successfully',
            'data'    => ['division' => $division],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $division = CorporateDivision::whereHas('department', function ($q) use ($request) {
            $q->where('corporate_id', $request->corporate_id);
        })->findOrFail($id);

        try {
            $this->corporateService->deleteDivision($division);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Division deleted successfully',
        ]);
    }
}
