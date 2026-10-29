<?php
// app/Http/Controllers/Api/ServiceTypeController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceType;
use App\Models\Service\ServiceTypeWorkflowRevision;
use App\Http\Requests\ServiceType\CreateServiceTypeRequest;
use App\Http\Requests\ServiceType\UpdateServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Services\ServiceTypeCloneService;
use App\Services\BookingWorkflowSettingsResolver;
use App\Services\Pricing\PricingContextPolicyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceTypeController extends Controller
{
    public function __construct(
        private readonly ServiceTypeCloneService $cloneService,
        private readonly PricingContextPolicyService $pricingContextPolicy,
        private readonly BookingWorkflowSettingsResolver $workflowSettingsResolver,
    ) {
        $this->middleware('permission:service-types.view')->only(['index','show']);
        $this->middleware('permission:service-types.create')->only(['store', 'clone']);
        $this->middleware('permission:service-types.edit')->only(['update']);
        $this->middleware('permission:service-types.delete')->only(['destroy']);
        $this->middleware('permission:service-types.create|service-types.edit')->only(['previewWorkflow']);
        $this->middleware('permission:service-types.workflow.approve')->only(['reviewWorkflowRevision']);
    }

    public function previewWorkflow(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_type_id' => ['nullable', 'uuid'],
            'workflow_config' => ['required', 'array:return_stage_enabled,qc_stage_enabled,maintenance_stage_enabled,signed_agreement_required,driver_license_required,customer_identity_document_required,booking_approval_required,handover_checklist_required'],
            'workflow_config.return_stage_enabled' => ['nullable', 'boolean'],
            'workflow_config.qc_stage_enabled' => ['nullable', 'boolean'],
            'workflow_config.maintenance_stage_enabled' => ['nullable', 'boolean'],
            'workflow_config.signed_agreement_required' => ['nullable', 'boolean'],
            'workflow_config.driver_license_required' => ['nullable', 'boolean'],
            'workflow_config.customer_identity_document_required' => ['nullable', 'boolean'],
            'workflow_config.booking_approval_required' => ['nullable', 'boolean'],
            'workflow_config.handover_checklist_required' => ['nullable', 'boolean'],
        ]);
        $companyId = null;
        if (! empty($data['service_type_id'])) {
            $serviceType = ServiceType::query()->findOrFail($data['service_type_id']);
            $this->pricingContextPolicy->assertServiceTypeIsWritable($serviceType);
            $this->resolveScope($request, $serviceType);
            $companyId = $serviceType->owner_type === 'company' ? (string) $serviceType->owner_id : null;
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->workflowSettingsResolver->preview($data['workflow_config'], $companyId),
        ]);
    }

    public function workflowRevisions(ServiceType $serviceType): JsonResponse
    {
        $this->pricingContextPolicy->assertServiceTypeIsWritable($serviceType);
        $revisions = ServiceTypeWorkflowRevision::query()->where('service_type_id', $serviceType->id)
            ->with(['actor:id,first_name,last_name', 'reviewer:id,first_name,last_name'])->orderByDesc('revision')->limit(30)->get()
            ->map(fn (ServiceTypeWorkflowRevision $revision) => [
                'revision' => $revision->revision,
                'change_type' => $revision->change_type,
                'status' => $revision->status,
                'workflow_config' => $revision->workflow_config,
                'effective_settings' => $revision->effective_settings,
                'actor_name' => $revision->actor ? trim($revision->actor->first_name.' '.$revision->actor->last_name) : null,
                'reviewer_name' => $revision->reviewer ? trim($revision->reviewer->first_name.' '.$revision->reviewer->last_name) : null,
                'reviewed_at' => $revision->reviewed_at,
                'review_note' => $revision->review_note,
                'created_at' => $revision->created_at,
            ]);

        return response()->json(['status' => 'success', 'data' => $revisions]);
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
        $tripMode = $data['trip_mode'] ?? 'fixed_route';
        unset($data['trip_mode']);
        $data['form_config'] = array_merge($data['form_config'] ?? [], ['trip_mode' => $tripMode]);
        [$context, $ownerType, $ownerId] = $this->resolveScope($request);
        $data['context'] = $data['context'] ?? $context;
        $data['owner_type'] = $data['owner_type'] ?? $ownerType;
        $data['owner_id'] = $data['owner_id'] ?? $ownerId;
        $data['slug'] = $data['slug'] ?? Str::slug($data['code']);
        $data['created_user_id'] = $request->user()->id;
        $svc = DB::transaction(function () use ($data, $request): ServiceType {
            $serviceType = ServiceType::create($data);
            $this->recordWorkflowRevision($serviceType, $serviceType->workflow_config, (string) $request->user()->id, 'created');
            return $serviceType;
        });
        app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);
        Cache::forget('sitemap');

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
        $data['code'] = strtolower(str_replace(' ', '_', $data['code'] ?? $serviceType->code));
        $duplicateCodeExists = ServiceType::query()
            ->where('context', $context)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->whereRaw('LOWER(REPLACE(code, ?, ?)) = ?', [' ', '_', $data['code']])
            ->where('id', '!=', $serviceType->id)
            ->exists();
        if ($duplicateCodeExists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Service type code must be unique.',
            ], 422);
        }
        $data['context'] = $data['context'] ?? $context;
        $data['owner_type'] = $data['owner_type'] ?? $ownerType;
        $data['owner_id'] = $data['owner_id'] ?? $ownerId;
        $data['updated_user_id'] = $request->user()->id;
        $serviceType = DB::transaction(function () use ($serviceType, $data, $request): ServiceType {
            $locked = ServiceType::query()->whereKey($serviceType->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('workflow_config', $data) && is_array($data['workflow_config'])) {
                $data['workflow_config'] = array_replace((array) $locked->workflow_config, $data['workflow_config']);
            }
            $workflowConfig = $data['workflow_config'] ?? null;
            $workflowChanged = array_key_exists('workflow_config', $data)
                && $locked->workflow_config !== $data['workflow_config'];
            unset($data['workflow_config']);
            if (array_key_exists('trip_mode', $data)) {
                $formConfig = $locked->form_config ?? [];
                $formConfig['trip_mode'] = $data['trip_mode'];
                $data['form_config'] = $formConfig;
                unset($data['trip_mode']);
            }
            if ($workflowChanged) {
                $revisionCount = (int) ServiceTypeWorkflowRevision::query()->where('service_type_id', $locked->id)->max('revision');
                if ($revisionCount === 0) {
                    $this->recordWorkflowRevision($locked, $locked->workflow_config, null, 'legacy_baseline');
                }
            }
            $locked->update($data);
            if (! $locked->slug) $locked->slug = Str::slug($locked->code);
            $locked->save();
            if ($workflowChanged) {
                ServiceTypeWorkflowRevision::query()->where('service_type_id', $locked->id)->where('status', 'pending')->update(['status' => 'superseded']);
                $this->recordWorkflowRevision($locked, $workflowConfig, (string) $request->user()->id, 'changed', 'pending');
            }
            return $locked;
        });
        app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);
        Cache::forget('sitemap');
        return response()->json([
            'status'=>'success',
            'message'=>'Service type updated',
            'data'=>['service_type'=>new ServiceTypeResource($serviceType)]
        ]);
    }

    public function reviewWorkflowRevision(Request $request, ServiceType $serviceType, int $revision): JsonResponse
    {
        $this->pricingContextPolicy->assertServiceTypeIsWritable($serviceType);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = DB::transaction(function () use ($serviceType, $revision, $data, $request): ServiceTypeWorkflowRevision {
            $lockedService = ServiceType::query()->whereKey($serviceType->id)->lockForUpdate()->firstOrFail();
            $change = ServiceTypeWorkflowRevision::query()->where('service_type_id', $lockedService->id)->where('revision', $revision)->lockForUpdate()->firstOrFail();
            abort_unless($change->status === 'pending', 409, 'This workflow proposal is no longer awaiting review.');
            if ($data['decision'] === 'approve') {
                abort_unless((string) $change->actor_user_id !== (string) $request->user()->id, 403, 'Another authorized employee must approve this workflow proposal.');
                ServiceTypeWorkflowRevision::query()->where('service_type_id', $lockedService->id)->where('status', 'active')->update(['status' => 'superseded']);
                $lockedService->workflow_config = $change->workflow_config;
                $lockedService->updated_user_id = $request->user()->id;
                $lockedService->save();
                $change->status = 'active';
            } else {
                $change->status = 'rejected';
            }
            $change->reviewed_by_user_id = $request->user()->id;
            $change->reviewed_at = now();
            $change->review_note = $data['note'] ?? null;
            $change->save();
            return $change;
        });
        app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);

        return response()->json(['status' => 'success', 'message' => 'Workflow proposal '.$result->status.'.']);
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
        Cache::forget('sitemap');
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

    private function recordWorkflowRevision(ServiceType $serviceType, ?array $workflowConfig, ?string $actorUserId, string $changeType, string $status = 'active'): void
    {
        $revision = ((int) ServiceTypeWorkflowRevision::query()->where('service_type_id', $serviceType->id)->max('revision')) + 1;
        ServiceTypeWorkflowRevision::query()->create([
            'service_type_id' => $serviceType->id,
            'revision' => $revision,
            'change_type' => $changeType,
            'status' => $status,
            'workflow_config' => $workflowConfig,
            'effective_settings' => $this->workflowSettingsResolver->resolve(
                $workflowConfig,
                $serviceType->owner_type === 'company' ? (string) $serviceType->owner_id : null,
            ),
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
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
