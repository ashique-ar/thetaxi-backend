<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreDepartmentRequest;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Services\CorporateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateDepartmentController extends Controller
{
    protected CorporateService $corporateService;

    public function __construct(CorporateService $corporateService)
    {
        $this->corporateService = $corporateService;
        $this->middleware('permission:manage_departments');
    }

    public function index(Request $request): JsonResponse
    {
        $corporateId = $request->corporate_id;

        $query = CorporateDepartment::where('corporate_id', $corporateId);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOL));
        }

        $perPage = (int) $request->get('per_page', 15);
        $departments = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => ['departments' => $departments],
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $corporate = Corporate::findOrFail($request->corporate_id);

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

    public function update(StoreDepartmentRequest $request, string $id): JsonResponse
    {
        $department = CorporateDepartment::where('corporate_id', $request->corporate_id)
            ->findOrFail($id);

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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $department = CorporateDepartment::where('corporate_id', $request->corporate_id)
            ->findOrFail($id);

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
