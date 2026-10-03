<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingCommercialValueAdjustment;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use App\Services\Sales\BookingAttributionMutationService;
use App\Services\Sales\BookingAttributionService;
use App\Services\Sales\BookingCommercialValueAdjustmentService;
use App\Services\Sales\CommissionPlanResolver;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesCollectionCompanyIntegrity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\Foundation\CanonicalJson;
use Closure;

class SalesBookingAttributionController extends Controller
{
    public function __construct(
        private readonly BookingAttributionService $attributions,
        private readonly BookingAttributionMutationService $mutations,
        private readonly BookingCommercialValueAdjustmentService $commercialValueAdjustments,
        private readonly CommissionPlanResolver $commissionPlans,
        private readonly SalesAccessScope $scope,
        private readonly SalesCollectionCompanyIntegrity $collectionCompanyIntegrity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'sales_profile_id' => ['nullable', 'uuid', 'exists:sales_profiles,id'],
            'status' => ['nullable', Rule::in(['active', 'held', 'ended'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $allowedProfileIds = $this->actorProfileIds($request, $data['company_id'] ?? null);
        $profile = fn ($query) => $query->with('staff.user:id,first_name,last_name')
            ->when($allowedProfileIds !== null, fn ($profiles) => $profiles->whereIn('id', $allowedProfileIds));
        $query = SalesBookingAttribution::query()->with([
            'booking:id,booking_number,payment_status,payment_collection_status',
            'acquisitionProfile' => $profile,
            'collectionProfile' => $profile,
        ]);
        $this->applyActorScope($query, $request, 'sales_booking_attributions', $data['company_id'] ?? null);

        $rows = $query
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['sales_profile_id'] ?? null, fn ($q, $id) => $q->where(fn ($owners) => $owners->where('acquisition_sales_profile_id', $id)->orWhere('collection_sales_profile_id', $id)))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('secured_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('secured_at', '<', $to))
            ->latest('secured_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function exceptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'status' => ['nullable', Rule::in(['open', 'resolved', 'waived'])],
            'exception_type' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('sales_attribution_exceptions as exception')
            ->join('bookings as booking', 'booking.id', '=', 'exception.booking_id')
            ->leftJoin('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->select(
                'exception.*',
                'booking.booking_number',
                'attribution.id as attribution_id',
                'attribution.company_id',
                'attribution.acquisition_sales_profile_id',
                'attribution.collection_sales_profile_id',
                'attribution.secured_at',
            );
        $this->applyActorScope($query, $request, 'attribution', $data['company_id'] ?? null);

        $rows = $query
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('attribution.company_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('exception.status', $status))
            ->when($data['exception_type'] ?? null, fn ($q, $type) => $q->where('exception.exception_type', $type))
            ->orderByDesc('exception.detected_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function dryRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'booking_ids' => ['nullable', 'array', 'max:500'],
            'booking_ids.*' => ['uuid', 'exists:bookings,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $profileIds = $this->actorProfileIds($request, $data['company_id'] ?? null);
        $bookings = Booking::query()
            ->where(fn ($q) => $q->where('confirmed', true)->orWhere('status', 'confirmed')->orWhereNotNull('confirmed_at'))
            ->whereDoesntHave('salesAttribution')
            ->when($data['booking_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->limit(500)
            ->get();
        $rows = $bookings
            ->map(fn (Booking $booking) => $this->attributions->preview($booking))
            ->filter(function (array $row) use ($profileIds, $data): bool {
                if (! empty($data['company_id']) && $row['company_id'] !== $data['company_id']) {
                    return false;
                }
                if ($profileIds === null) {
                    return true;
                }

                return in_array($row['acquisition_sales_profile_id'], $profileIds, true)
                    || in_array($row['collection_sales_profile_id'], $profileIds, true);
            })
            ->take($data['limit'] ?? 100)
            ->values();

        $checksum=hash('sha256',CanonicalJson::encode(['company_id'=>$data['company_id']??null,'rows'=>$rows->all()]));
        return response()->json([
            'status' => 'success',
            'data' => [
                'write_performed' => false,
                'rows' => $rows,
                'preview_checksum'=>$checksum,
            ],
        ]);
    }

    public function applyHistoricalBatch(Request $request): JsonResponse
    {
        $data=$request->validate(['company_id'=>['required','uuid','exists:companies,id'],'booking_ids'=>['required','array','min:1','max:500'],'booking_ids.*'=>['uuid','distinct','exists:bookings,id'],'preview_checksum'=>['required','string','size:64'],'idempotency_key'=>['required','string','max:160']]);
        abort_unless($this->scope->hasPermission($request->user(),'sales.attributions.correct'),403);
        return DB::transaction(function()use($request,$data){
            DB::table('companies')->where('id',$data['company_id'])->lockForUpdate()->firstOrFail();
            $scopeChecksum=hash('sha256',CanonicalJson::encode(collect($data['booking_ids'])->sort()->values()->all()));
            $prior=DB::table('sales_attribution_migration_batches')->where('company_id',$data['company_id'])->where('idempotency_key',$data['idempotency_key'])->lockForUpdate()->first();
            if($prior){abort_unless(hash_equals($prior->preview_checksum,$data['preview_checksum'])&&hash_equals($prior->booking_scope_checksum,$scopeChecksum),409,'Historical attribution batch key was reused with different evidence.');return response()->json(['status'=>'success','data'=>json_decode($prior->result_snapshot,true,512,JSON_THROW_ON_ERROR),'idempotent_replay'=>true]);}
            $profileIds=$this->actorProfileIds($request,$data['company_id']);
            $bookings=Booking::query()->whereIn('id',$data['booking_ids'])->where(fn($q)=>$q->where('confirmed',true)->orWhere('status','confirmed')->orWhereNotNull('confirmed_at'))->whereDoesntHave('salesAttribution')->orderBy('id')->lockForUpdate()->get();
            abort_unless($bookings->count()===count($data['booking_ids']),409,'The historical booking set changed; create a new preview.');
            $rows=$bookings->map(fn(Booking $booking)=>$this->attributions->preview($booking))->filter(fn(array$row)=>$row['company_id']===$data['company_id']&&($profileIds===null||in_array($row['acquisition_sales_profile_id'],$profileIds,true)||in_array($row['collection_sales_profile_id'],$profileIds,true)))->values();
            abort_unless($rows->count()===$bookings->count(),404,'A historical booking is outside the authorized legal-entity scope.');
            $checksum=hash('sha256',CanonicalJson::encode(['company_id'=>$data['company_id'],'rows'=>$rows->all()]));abort_unless(hash_equals($checksum,$data['preview_checksum']),409,'Historical attribution facts changed; create a new preview.');
            $created=0;foreach($bookings as$booking){if(!$booking->salesAttribution()->exists()){$this->attributions->captureConfirmation($booking);$created++;}}
            $result=['requested'=>count($data['booking_ids']),'created'=>$created,'preview_checksum'=>$checksum,'reconciliation'=>['remaining_confirmed_without_attribution'=>Booking::query()->where(fn($q)=>$q->where('confirmed',true)->orWhere('status','confirmed')->orWhereNotNull('confirmed_at'))->whereDoesntHave('salesAttribution')->count()]];
            DB::table('sales_attribution_migration_batches')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'company_id'=>$data['company_id'],'idempotency_key'=>$data['idempotency_key'],'preview_checksum'=>$checksum,'booking_scope_checksum'=>$scopeChecksum,'requested_count'=>count($data['booking_ids']),'created_count'=>$created,'result_snapshot'=>json_encode($result,JSON_THROW_ON_ERROR),'applied_by'=>$request->user()->id,'applied_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            return response()->json(['status'=>'success','data'=>$result]);
        },3);
    }

    public function transferHandler(Request $request, string $attribution): JsonResponse
    {
        $data = $request->validate([
            'to_sales_profile_id' => ['nullable', 'uuid'],
            'effective_at' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $attribution = $this->scopedAttribution($request, $attribution);
        $target = ! empty($data['to_sales_profile_id'])
            ? $this->scopedProfile($request, $data['to_sales_profile_id'], $attribution->company_id)
            : null;

        $updated = $this->mutations->transferCollectionHandler(
            $attribution,
            $target,
            Carbon::parse($data['effective_at']),
            $data['reason'],
            $data['idempotency_key'],
            $request->user()->id,
        );

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function correctOwner(Request $request, string $attribution): JsonResponse
    {
        $data = $request->validate([
            'to_sales_profile_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $attribution = $this->scopedAttribution($request, $attribution);
        $target = $this->scopedProfile($request, $data['to_sales_profile_id'], $attribution->company_id);

        $updated = $this->mutations->correctAcquisitionOwner(
            $attribution,
            $target,
            $data['reason'],
            $data['idempotency_key'],
            $request->user()->id,
        );

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function establishLegalEntity(Request $request, string $attribution): JsonResponse
    {
        $data = $request->validate([
            'to_sales_profile_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $attribution = $this->scopedAttribution($request, $attribution);
        $target = $this->scopedProfile($request, $data['to_sales_profile_id'], $attribution->company_id);

        $updated = $this->mutations->establishLegalEntity(
            $attribution,
            $target,
            $data['reason'],
            $data['idempotency_key'],
            $request->user()->id,
        );

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function correctCollectionHandler(Request $request, string $attribution): JsonResponse
    {
        $data = $request->validate([
            'to_sales_profile_id' => ['required', 'uuid'],
            'effective_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $attribution = $this->scopedAttribution($request, $attribution);
        $target = $this->scopedProfile($request, $data['to_sales_profile_id'], $attribution->company_id);

        $updated = $this->mutations->correctCollectionHandler(
            $attribution,
            $target,
            Carbon::parse($data['effective_at']),
            $data['reason'],
            $data['idempotency_key'],
            $request->user()->id,
        );

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function previewPlanFamilyCorrection(Request $request, string $attribution): JsonResponse
    {
        $attribution = $this->scopedAttribution($request, $attribution);
        $preview = $this->commissionPlans->previewMissingFamily($attribution);
        unset($preview['frozen_correction_snapshot'], $preview['prohibited_approver_ids']);

        return response()->json(['status' => 'success', 'data' => $preview]);
    }

    public function correctPlanFamily(Request $request, string $attribution): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'preview_checksum' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $attribution = $this->scopedAttribution($request, $attribution);
        $updated = $this->commissionPlans->correctMissingFamily(
            $attribution->id, $data['expected_version'], $data['preview_checksum'],
            $data['reason'], $data['idempotency_key'], $request->user()->id,
        );

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function previewCommercialValueAdjustment(Request $request, string $attribution): JsonResponse
    {
        $data = $this->validateCommercialValueAdjustment($request);
        abort_unless($this->scope->hasPermission($request->user(), 'sales.attributions.adjust-value'), 403);
        return $this->withinCommercialAdjustmentScope($request, $attribution, fn (SalesBookingAttribution $scopedAttribution) => response()->json([
            'status' => 'success',
            'data' => $this->commercialValueAdjustments->preview($scopedAttribution->booking, $data),
        ]));
    }

    public function applyCommercialValueAdjustment(Request $request, string $attribution): JsonResponse
    {
        $data = $this->validateCommercialValueAdjustment($request, true);
        abort_unless($this->scope->hasPermission($request->user(), 'sales.attributions.adjust-value'), 403);
        return $this->withinCommercialAdjustmentScope($request, $attribution, fn (SalesBookingAttribution $scopedAttribution) => response()->json([
            'status' => 'success',
            'data' => $this->commercialValueAdjustments->apply($scopedAttribution->booking, $data, (string) $request->user()->id),
        ], 201));
    }

    public function commercialValueAdjustmentHistory(Request $request, string $attribution): JsonResponse
    {
        $attribution = $this->scopedAttribution($request, $attribution);
        $this->collectionCompanyIntegrity->assertConsistent($attribution->booking, $attribution->company_id);
        $rows = BookingCommercialValueAdjustment::query()
            ->where('booking_id', $attribution->booking_id)
            ->orderByDesc('effective_at')->orderByDesc('created_at')
            ->get();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    private function validateCommercialValueAdjustment(Request $request, bool $forApply = false): array
    {
        return $request->validate([
            'adjustment_type' => ['required', Rule::in(['increase', 'decrease', 'extension', 'cancellation_fee', 'cancellation'])],
            'source_amount' => ['required_unless:adjustment_type,cancellation', 'nullable', 'numeric', 'min:0'],
            'source_currency' => ['required', 'string', 'size:3'],
            'lkr_amount' => ['nullable', 'numeric', 'gt:0'],
            'fx_rate_to_lkr' => ['nullable', 'numeric', 'gt:0'],
            'fx_rate_at' => ['nullable', 'date'],
            'effective_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'reconciliation_rule' => ['nullable', Rule::in(['exact', 'opening', 'balloon', 'residual'])],
            'items' => ['array', 'max:120'],
            'items.*.label' => ['nullable', 'string', 'max:120'],
            'items.*.period_start' => ['nullable', 'date'],
            'items.*.period_end' => ['nullable', 'date', 'after_or_equal:items.*.period_start'],
            'items.*.due_date' => ['required_with:items.*.source_amount', 'date'],
            'items.*.source_amount' => ['required_with:items.*.due_date', 'numeric', 'gt:0'],
            'items.*.source_currency' => ['required_with:items.*.due_date', 'string', 'size:3'],
            'items.*.lkr_amount' => ['nullable', 'numeric', 'gt:0'],
            'items.*.schedule_kind' => ['required_with:items.*.due_date', Rule::in(['initial', 'monthly', 'custom'])],
            'items.*.reconciliation_role' => ['nullable', Rule::in(['opening', 'balloon', 'residual'])],
            'items.*.is_collection_target_eligible' => ['required_with:items.*.due_date', 'boolean'],
            'items.*.reminder_offset_days' => ['required_with:items.*.due_date', 'integer', 'min:0', 'max:90'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'preview_checksum' => [$forApply ? 'required' : 'nullable', 'string', 'size:64'],
            'idempotency_key' => [$forApply ? 'required' : 'nullable', 'string', 'max:160'],
        ]);
    }

    public function closeLeaver(Request $request, string $profile): JsonResponse
    {
        $data = $request->validate([
            'replacement_sales_profile_id' => ['nullable', 'uuid'],
            'effective_at' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        abort_unless($this->scope->hasPermission($request->user(), 'sales.attributions.correct'), 403);
        $profile = $this->scopedProfile($request, $profile);

        $replacement = ! empty($data['replacement_sales_profile_id'])
            ? $this->scopedProfile($request, $data['replacement_sales_profile_id'], $profile->company_id)
            : null;
        abort_if($replacement?->id === $profile->id, 422, 'A leaver cannot replace their own portfolio.');
        $count = $this->mutations->closeLeaverPortfolio(
            $profile,
            $replacement,
            Carbon::parse($data['effective_at']),
            $data['reason'],
            $data['idempotency_key'],
            $request->user()->id,
        );

        return response()->json([
            'status' => 'success',
            'data' => ['sales_profile_id' => $profile->id, 'portfolio_rows_changed' => $count, 'unassigned' => $replacement === null],
        ]);
    }

    public function administrationContext(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid', 'exists:companies,id']]);
        if (! empty($data['company_id'])) {
            abort_unless(DB::table('companies')->where('id', $data['company_id'])->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
            $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.attributions.view-all');
        }
        $companyIds = $this->scope->companyIds($request->user(), 'sales.attributions.view-all');
        $defaultCompanyId = DB::table('companies')->whereNull('deleted_at')->where('is_active', true)->where('is_default', true)
            ->when($companyIds !== null, fn ($query) => $query->whereIn('id', $companyIds))->value('id');
        return response()->json(['status' => 'success', 'data' => [
            'can_correct' => $this->scope->hasPermission($request->user(), 'sales.attributions.correct'),
            'default_company_id' => $defaultCompanyId,
        ]]);
    }

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyIds = $this->scope->companyIds($request->user(), 'sales.attributions.view-all');
        $query = DB::table('companies')->whereNull('deleted_at')
            ->when($companyIds !== null, fn ($company) => $company->whereIn('id', $companyIds));
        if (! empty($data['selected_id'])) $query->where('id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($company) => $company->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $query->select(['id', 'name', 'city', 'is_active'])->orderByDesc('is_default')->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($company) => ['value' => (string) $company->id, 'label' => $company->name,
            'metadata' => array_filter(['city' => $company->city, 'availability' => $company->is_active ? null : 'Inactive']),
            'status' => $company->is_active ? 'active' : 'inactive']);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function profileOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(['acquisition', 'collection'])],
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'cross_company' => ['sometimes', 'boolean'],
            'effective_at' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyId = $data['company_id'] ?? null;
        abort_if(! $companyId && ! ($data['cross_company'] ?? false), 422, 'Select a legal entity.');
        if ($companyId) {
            abort_unless(DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
            $this->scope->assertCompany($request->user(), $companyId, 'sales.attributions.view-all');
        }

        $at = isset($data['effective_at']) ? Carbon::parse($data['effective_at']) : now();
        $eligibility = $data['purpose'].'_eligible';
        $companyIds = $this->scope->companyIds($request->user(), 'sales.attributions.view-all');
        $query = SalesProfile::query()
            ->select('sales_profiles.*', 'companies.name as company_name')
            ->join('companies', 'companies.id', '=', 'sales_profiles.company_id')
            ->with('staff.user:id,first_name,last_name')
            ->whereNull('companies.deleted_at')
            ->where('sales_profiles.status', 'active')
            ->where("sales_profiles.{$eligibility}", true)
            ->where('sales_profiles.effective_from', '<=', $at)
            ->where(fn ($effective) => $effective->whereNull('sales_profiles.effective_until')->orWhere('sales_profiles.effective_until', '>', $at))
            ->whereNotNull('sales_profiles.reporting_currency')
            ->whereNotNull('sales_profiles.staff_category_snapshot')
            ->whereHas('staff', fn ($staff) => $staff->whereNull('deleted_at')
                ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', $at)))
            ->when($companyId, fn ($profiles) => $profiles->where('sales_profiles.company_id', $companyId))
            ->when($companyIds !== null, fn ($profiles) => $profiles->whereIn('sales_profiles.company_id', $companyIds));

        if ($companyId) {
            $this->scope->scopeProfiles($query, $request->user(), 'sales.attributions.view-all', 'sales.attributions.view-team', $companyId);
        } elseif ($companyIds !== null) {
            $profileIds = collect($companyIds)->flatMap(fn ($id) => $this->scope->profileIds(
                $request->user(), 'sales.attributions.view-all', 'sales.attributions.view-team', $id,
            ) ?? [])->unique()->values()->all();
            $query->whereIn('sales_profiles.id', $profileIds);
        }

        if (! empty($data['selected_id'])) $query->where('sales_profiles.id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($profiles) => $profiles->where('sales_profiles.sales_code', 'like', $term)
                ->orWhereHas('staff.user', fn ($user) => $user->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term))
                ->orWhere('companies.name', 'like', $term));
        }

        $rows = $query->orderBy('sales_profiles.sales_code')->orderBy('sales_profiles.id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn (SalesProfile $profile) => [
            'value' => (string) $profile->id,
            'label' => trim($profile->sales_code.' — '.($profile->staff?->user?->first_name.' '.$profile->staff?->user?->last_name)),
            'metadata' => ['company' => $profile->company_name],
            'status' => $profile->status,
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    private function applyActorScope(
        $query,
        Request $request,
        string $alias = 'sales_booking_attributions',
        ?string $companyId = null,
    ): void
    {
        $profileIds = $this->actorProfileIds($request, $companyId);
        if ($profileIds === null) {
            return;
        }

        $query->where(function ($owners) use ($alias, $profileIds) {
            $owners->whereIn("{$alias}.acquisition_sales_profile_id", $profileIds)
                ->orWhereIn("{$alias}.collection_sales_profile_id", $profileIds);
        });
    }

    private function scopedAttribution(Request $request, string $attributionId): SalesBookingAttribution
    {
        $attribution = SalesBookingAttribution::query()->find($attributionId);
        if (! $attribution) {
            abort(404, 'Attribution was not found in the current scope.');
        }
        try {
            $profileIds = $this->actorProfileIds($request, $attribution->company_id);
        } catch (HttpResponseException) {
            abort(404, 'Attribution was not found in the current scope.');
        }
        if ($profileIds !== null
            && ! in_array($attribution->acquisition_sales_profile_id, $profileIds, true)
            && ! in_array($attribution->collection_sales_profile_id, $profileIds, true)) {
            abort(404, 'Attribution was not found in the current scope.');
        }

        return $attribution;
    }

    private function withinCommercialAdjustmentScope(Request $request, string $attributionId, Closure $operation): JsonResponse
    {
        $candidate = SalesBookingAttribution::query()->findOrFail($attributionId);

        return DB::transaction(function () use ($request, $candidate, $operation): JsonResponse {
            Booking::query()->whereKey($candidate->booking_id)->lockForUpdate()->firstOrFail();
            $locked = SalesBookingAttribution::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless((string) $locked->booking_id === (string) $candidate->booking_id, 409, 'The attribution booking changed; reload before adjusting its value.');
            $scoped = $this->scopedAttribution($request, (string) $locked->id);
            $this->collectionCompanyIntegrity->assertConsistent($scoped->booking, $scoped->company_id);

            return $operation($scoped);
        });
    }

    private function scopedProfile(Request $request, string $profileId, ?string $companyId = null): SalesProfile
    {
        $profileIds = $this->actorProfileIds($request, $companyId);
        $profile = SalesProfile::query()
            ->whereKey($profileId)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($profileIds !== null, fn ($query) => $query->whereIn('id', $profileIds))
            ->first();

        abort_unless($profile, 404, 'Sales Profile was not found in the current scope.');

        return $profile;
    }

    private function actorProfileIds(Request $request, ?string $companyId): ?array
    {
        return $this->scope->profileIds(
            $request->user(),
            'sales.attributions.view-all',
            'sales.attributions.view-team',
            $companyId,
        );
    }
}
