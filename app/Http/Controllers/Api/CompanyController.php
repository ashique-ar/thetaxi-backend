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
use Illuminate\Support\Facades\DB;

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
            $search = $request->search;
            $q->where(function ($query) use ($search) {
                $query->whereLikeInsensitive('name', $search)
                    ->orWhereLikeInsensitive('email', $search)
                    ->orWhereLikeInsensitive('phone', $search)
                    ->orWhereLikeInsensitive('domain', $search);
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
        $company = DB::transaction(function () use ($data) {
            DB::table('companies')->whereNull('deleted_at')->lockForUpdate()->get(['id']);

            $makeDefault = ($data['is_default'] ?? false)
                || ! Company::query()->where('is_default', true)->exists();

            abort_if($makeDefault && ! ($data['is_active'] ?? true), 422, 'The default Staff company must remain active.');

            if ($makeDefault) {
                DB::table('companies')->whereNull('deleted_at')->update(['is_default' => false]);
            }

            return Company::create(array_merge($data, ['is_default' => $makeDefault]));
        });

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
        $company = DB::transaction(function () use ($company, $data) {
            DB::table('companies')->whereNull('deleted_at')->lockForUpdate()->get(['id']);

            $makeDefault = (bool) ($data['is_default'] ?? $company->is_default);

            abort_if(
                $company->is_default && ! $makeDefault,
                422,
                'Select another active company as default before removing this default.'
            );
            abort_if(
                $makeDefault && array_key_exists('is_active', $data) && ! $data['is_active'],
                422,
                'The default Staff company must remain active.'
            );

            if ($makeDefault) {
                DB::table('companies')
                    ->whereNull('deleted_at')
                    ->where('id', '!=', $company->id)
                    ->update(['is_default' => false]);
            }

            $company->update(array_merge($data, ['is_default' => $makeDefault]));

            return $company->fresh();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Company updated',
            'data' => new CompanyResource($company->load(['region', 'country', 'state']))
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        abort_if($company->is_default, 422, 'Select another active company as default before deleting this company.');
        $company->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Company deleted'
        ]);
    }
}
