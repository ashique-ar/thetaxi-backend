<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreDepartmentRequest;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Services\CorporateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCorporateDepartmentController extends Controller
{
    protected CorporateService $corporateService;

    public function __construct(CorporateService $corporateService)
    {
        $this->corporateService = $corporateService;
        $this->middleware('permission:corporates.manage');
    }

    public function index(Request $request, Corporate $corporate): JsonResponse
    {
        $query = CorporateDepartment::where('corporate_id', $corporate->id);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Only apply is_active filter if explicitly set (not 'all')
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOL));
        }

        $perPage = (int) $request->get('per_page', 15);
        $departments = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => ['departments' => $departments],
        ]);
    }

    public function store(StoreDepartmentRequest $request, Corporate $corporate): JsonResponse
    {
        $department = $this->corporateService->createDepartment($corporate, [
            'name'            => $request->name,
            'description'     => $request->description,
            'created_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Department created successfully',
            'data'    => ['department' => $department],
        ], 201);
    }

    public function show(Corporate $corporate, CorporateDepartment $department): JsonResponse
    {
        if ($department->corporate_id !== $corporate->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Department does not belong to this corporate',
            ], 404);
        }

        $department->load('divisions');

        return response()->json([
            'status' => 'success',
            'data'   => ['department' => $department],
        ]);
    }

    public function update(StoreDepartmentRequest $request, Corporate $corporate, CorporateDepartment $department): JsonResponse
    {
        if ($department->corporate_id !== $corporate->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Department does not belong to this corporate',
            ], 404);
        }

        $department = $this->corporateService->updateDepartment($department, [
            'name'            => $request->name,
            'description'     => $request->description,
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Department updated successfully',
            'data'    => ['department' => $department],
        ]);
    }

    public function destroy(Corporate $corporate, CorporateDepartment $department): JsonResponse
    {
        if ($department->corporate_id !== $corporate->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Department does not belong to this corporate',
            ], 404);
        }

        try {
            $this->corporateService->deleteDepartment($department);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Department deleted successfully',
        ]);
    }
}
