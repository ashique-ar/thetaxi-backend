<?php
// app/Http/Controllers/Api/ServiceTypeController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceType;
use App\Http\Requests\ServiceType\CreateServiceTypeRequest;
use App\Http\Requests\ServiceType\UpdateServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Services\ServiceTypeCloneService;
use App\Services\Pricing\PricingContextPolicyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ServiceTypeController extends Controller
{
    public function __construct(
        private readonly ServiceTypeCloneService $cloneService,
        private readonly PricingContextPolicyService $pricingContextPolicy
    ) {
        $this->middleware('permission:service-types.view')->only(['index','show']);
        $this->middleware('permission:service-types.create')->only(['store', 'clone']);
        $this->middleware('permission:service-types.edit')->only(['update']);
        $this->middleware('permission:service-types.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        [$context, $ownerType, $ownerId] = $this->resolveScope($request);
        $hasSearch = $request->filled('search');
        $perPage = (int) ($request->per_page ?? 15);
        $page = (int) ($request->get('page', 1));

        $q = $this->buildScopedIndexQuery($request, $context, $ownerType, $ownerId);

        $fallbackContext = (string) $request->input('fallback_context', '');
        if ($fallbackContext !== '' && !(clone $q)->exists()) {
            [$fallbackContext, $fallbackOwnerType, $fallbackOwnerId] = $this->resolveFallbackScope($request);
            $q = $this->buildScopedIndexQuery($request, $fallbackContext, $fallbackOwnerType, $fallbackOwnerId);
        }

        if (!$hasSearch) {
            $v = (int) Cache::get('ref.service-types.v', 0);
            $cacheKey = "ref.service-types.v{$v}.ctx{$context}.ot{$ownerType}.oi{$ownerId}.p{$perPage}.pg{$page}";
            $results = Cache::remember($cacheKey, 3600, fn () => $q->paginate($perPage));
            return ServiceTypeResource::collection($results);
        }

        return ServiceTypeResource::collection($q->paginate($perPage));
    }

    public function store(CreateServiceTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$context, $ownerType, $ownerId] = $this->resolveScope($request);
        $data['context'] = $data['context'] ?? $context;
        $data['owner_type'] = $data['owner_type'] ?? $ownerType;
        $data['owner_id'] = $data['owner_id'] ?? $ownerId;
        $data['slug'] = $data['slug'] ?? Str::slug($data['code']);
        $data['created_user_id'] = $request->user()->id;
        $svc = ServiceType::create($data);
        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);

        return response()->json([
            'status'=>'success',
            'message'=>'Service type created',
            'data'=>['service_type'=>new ServiceTypeResource($svc)]
        ],201);
    }

    public function show(ServiceType $serviceType): JsonResponse
    {
        $serviceType = $this->pricingContextPolicy->effectiveServiceType($serviceType);

        return response()->json([
            'status'=>'success',
            'data'=>['service_type'=>new ServiceTypeResource($serviceType)]
        ]);
    }

    public function update(UpdateServiceTypeRequest $request, ServiceType $serviceType): JsonResponse
    {
        $this->pricingContextPolicy->assertServiceTypeIsWritable($serviceType);
        $data = $request->validated();
        [$context, $ownerType, $ownerId] = $this->resolveScope($request, $serviceType);
        $data['context'] = $data['context'] ?? $context;
        $data['owner_type'] = $data['owner_type'] ?? $ownerType;
        $data['owner_id'] = $data['owner_id'] ?? $ownerId;
        $data['updated_user_id'] = $request->user()->id;
        $serviceType->update($data);

        if (
            ServiceType::where('code', $serviceType->code)
                ->where('context', $serviceType->context)
                ->where('owner_type', $serviceType->owner_type)
                ->where('owner_id', $serviceType->owner_id)
                ->where('id', '!=', $serviceType->id)
                ->exists()
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Service type code must be unique.',
            ], 422);
        }
        $serviceType->code = strtolower(str_replace(' ', '_', $serviceType->code));
        if (!$serviceType->slug) {
            $serviceType->slug = Str::slug($serviceType->code);
        }
        $serviceType->save();
        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);
        return response()->json([
            'status'=>'success',
            'message'=>'Service type updated',
            'data'=>['service_type'=>new ServiceTypeResource($serviceType)]
        ]);
    }

    // public function toggleStatus(Request $request, string $id): JsonResponse
    // {
    //     $serviceType = ServiceType::findOrFail($id);
    //     $serviceType->is_active = !$serviceType->is_active;
    //     $serviceType->save();

    //     return response()->json([
    //         'status'=>'success',
    //         'message'=>'Service type status updated',
    //         'data'=>['service_type'=>new ServiceTypeResource($serviceType)]
    //     ]);
    // }

    public function destroy(ServiceType $serviceType): JsonResponse
    {
        $this->pricingContextPolicy->assertServiceTypeIsWritable($serviceType);
        $serviceType->delete();
        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);
        return response()->json([
            'status'=>'success',
            'message'=>'Service type deleted'
        ]);
    }

    public function clone(Request $request, ServiceType $serviceType): JsonResponse
    {
        $serviceType = $this->pricingContextPolicy->effectiveServiceType($serviceType);
        $payload = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', 'string', 'max:50'],
            'owner_type' => ['nullable', 'string', 'max:100'],
            'owner_id' => ['nullable', 'string', 'max:100'],
        ]);

        [$context, $ownerType, $ownerId] = $this->resolveScope($request, $serviceType);
        $payload['context'] = $payload['context'] ?? $context;
        $payload['owner_type'] = $payload['owner_type'] ?? $ownerType;
        $payload['owner_id'] = $payload['owner_id'] ?? $ownerId;

        $cloned = $this->cloneService->clone($serviceType, $payload, $request->user()?->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Service type cloned successfully',
            'data' => ['service_type' => new ServiceTypeResource($cloned)],
        ], 201);
    }

    private function resolveScope(Request $request, ?ServiceType $serviceType = null): array
    {
        $context = (string) $request->input('context', $serviceType?->context ?? 'portal');
        $ownerType = (string) $request->input('owner_type', $serviceType?->owner_type ?? '');
        $ownerId = (string) $request->input('owner_id', $serviceType?->owner_id ?? '');

        if ($context === 'corporate') {
            return [$context, '', ''];
        }

        if ($context !== 'all') {
            $context = $this->pricingContextPolicy->effectiveContext($context);
        }

        return [$context, $ownerType, $ownerId];
    }

    private function resolveFallbackScope(Request $request): array
    {
        $context = (string) $request->input('fallback_context', 'portal');
        $ownerType = (string) $request->input('fallback_owner_type', '');
        $ownerId = (string) $request->input('fallback_owner_id', '');

        return [
            $context === 'all' ? 'all' : $this->pricingContextPolicy->effectiveContext($context),
            $ownerType,
            $ownerId,
        ];
    }

    private function buildScopedIndexQuery(Request $request, string $context, string $ownerType, string $ownerId)
    {
        $query = ServiceType::withInactive();

        if ($context !== 'all') {
            $query->forContext($context, $ownerType, $ownerId);
        }

        if ($request->filled('search')) {
            $query->where(function ($builder) use ($request) {
                $builder->whereLikeInsensitive('name', $request->search)
                    ->orWhereLikeInsensitive('code', $request->search);
            });
        }

        if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $query;
    }
}
