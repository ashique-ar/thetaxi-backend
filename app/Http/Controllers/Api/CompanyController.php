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
        $this->middleware('permission:companies.view')->only(['index', 'show', 'options', 'option']);
        $this->middleware('permission:companies.create')->only(['store']);
        $this->middleware('permission:companies.edit')->only(['update']);
        $this->middleware('permission:companies.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Company::with(['region', 'country', 'state']);
        if ($request->filled('search')) {
            $search = $request->search;
            $q->where(function ($query) use ($search) {
                $query->whereLikeInsensitive('name', $search)
                    ->orWhereLikeInsensitive('email', $search)
                    ->orWhereLikeInsensitive('phone', $search);
            });
        }
        if ($request->filled('region_id')) {
            $q->where('region_id', $request->region_id);
        }
        if ($request->filled('country_id')) {
            $q->where('country_id', $request->country_id);
        }
        if ($request->filled('state_id')) {
            $q->where('state_id', $request->state_id);
        }
        if ($request->has('is_active') && $request->is_active !== '') {
            $q->where('is_active', $request->boolean('is_active'));
        }
        $q->latest();

        return CompanyResource::collection(
            $q->paginate(min(max((int) $request->integer('per_page', 15), 1), 100))
        );
    }

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = Company::query()
            ->where('is_active', true)
            ->select(['id', 'name', 'is_active']);

        if (!empty($data['search'])) {
            $query->whereLikeInsensitive('name', trim($data['search']));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Location options loaded',
            'data' => $query->orderBy('name')->limit(25)->get(),
        ]);
    }

    public function option(Company $company): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Location loaded',
            'data' => $company->only(['id', 'name', 'is_active']),
        ]);
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
            'message' => 'Location created',
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
        $company = $company->fresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Location updated',
            'data' => new CompanyResource($company->load(['region', 'country', 'state']))
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Location deleted'
        ]);
    }
}
