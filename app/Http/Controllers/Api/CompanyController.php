<?php
// app/Http/Controllers/Api/Company/CompanyController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Http\Requests\Company\CreateCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\Company\CompanyResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompanyController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:companies.view')->only(['index', 'show']);
        $this->middleware('permission:companies.create')->only(['store']);
        $this->middleware('permission:companies.edit')->only(['update']);
        $this->middleware('permission:companies.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Company::with(['region', 'country', 'state']);
        if ($request->filled('search')) {
            $q->whereLikeInsensitive('name', $request->search);
        }
        return CompanyResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function stats(): JsonResponse
    {
        $totalCompanies = Company::count();
        $activeCompanies = Company::where('is_active', true)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_companies' => $totalCompanies,
                'active_companies' => $activeCompanies,
                'inactive_companies' => $totalCompanies - $activeCompanies,
            ],
        ]);
    }

    public function store(CreateCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $company = Company::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Company created',
            'data' => new CompanyResource($company)
        ], 201);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new CompanyResource($company->load(['region', 'country', 'state']))
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $company->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Company updated',
            'data' => new CompanyResource($company->load(['region', 'country', 'state']))
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Company deleted'
        ]);
    }
}
