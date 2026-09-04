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
use App\Services\Sales\SalesAccessScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesBookingAttributionController extends Controller
{
    public function __construct(
        private readonly BookingAttributionService $attributions,
        private readonly BookingAttributionMutationService $mutations,
        private readonly BookingCommercialValueAdjustmentService $commercialValueAdjustments,
        private readonly SalesAccessScope $scope,
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

        $query = SalesBookingAttribution::query()->with([
            'booking:id,booking_number,payment_status,payment_collection_status',
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

        return response()->json([
            'status' => 'success',
            'data' => [
                'write_performed' => false,
                'rows' => $rows,
            ],
        ]);
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

    public function previewCommercialValueAdjustment(Request $request, string $attribution): JsonResponse
    {
        $data = $this->validateCommercialValueAdjustment($request);
        abort_unless($this->scope->hasPermission($request->user(), 'sales.attributions.adjust-value'), 403);
        $attribution = $this->scopedAttribution($request, $attribution);

        return response()->json([
            'status' => 'success',
            'data' => $this->commercialValueAdjustments->preview($attribution->booking, $data),
        ]);
    }

    public function applyCommercialValueAdjustment(Request $request, string $attribution): JsonResponse
    {
        $data = $this->validateCommercialValueAdjustment($request, true);
        abort_unless($this->scope->hasPermission($request->user(), 'sales.attributions.adjust-value'), 403);
        $attribution = $this->scopedAttribution($request, $attribution);

        $adjustment = $this->commercialValueAdjustments->apply($attribution->booking, $data, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $adjustment], 201);
    }

    public function commercialValueAdjustmentHistory(Request $request, string $attribution): JsonResponse
    {
        $attribution = $this->scopedAttribution($request, $attribution);
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
        $companyIds = $this->scope->companyIds($request->user(), 'sales.attributions.view-all');
        $profiles = SalesProfile::query()
            ->with('staff.user:id,first_name,last_name')
            ->configured()
            ->when($companyIds !== null, fn ($query) => $query->whereIn('company_id', $companyIds));
        $profiles = $this->scope->scopeProfiles(
            $profiles,
            $request->user(),
            'sales.attributions.view-all',
            'sales.attributions.view-team',
        )->orderBy('sales_code')->get()->map(fn (SalesProfile $profile) => [
            'id' => $profile->id,
            'company_id' => $profile->company_id,
            'sales_code' => $profile->sales_code,
            'name' => trim((string) ($profile->staff?->user?->first_name.' '.$profile->staff?->user?->last_name)),
            'acquisition_eligible' => (bool) $profile->acquisition_eligible,
            'collection_eligible' => (bool) $profile->collection_eligible,
            'status' => $profile->status,
        ])->values();

        return response()->json(['status' => 'success', 'data' => [
            'companies' => DB::table('companies')
                ->when($companyIds !== null, fn ($query) => $query->whereIn('id', $companyIds))
                ->orderBy('name')->get(['id', 'name']),
            'profiles' => $profiles,
            'can_correct' => $this->scope->hasPermission($request->user(), 'sales.attributions.correct'),
        ]]);
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
