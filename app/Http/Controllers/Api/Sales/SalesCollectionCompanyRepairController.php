<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Sales\SalesBookingAttribution;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesCollectionCompanyIntegrity;
use App\Services\Sales\SalesCollectionCompanyRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesCollectionCompanyRepairController extends Controller
{
    public function __construct(
        private readonly SalesAccessScope $scope,
        private readonly SalesCollectionCompanyIntegrity $integrity,
        private readonly SalesCollectionCompanyRepairService $repairs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id']]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.collections.view-all');

        $items = $this->integrity->mismatchReport(companyId: $data['company_id']);

        return response()->json([
            'status' => 'success', 'data' => $items, 'summary' => $this->integrity->mismatchSummary($items),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id']]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.collections.view-all');

        return response()->json(['status' => 'success', 'data' => $this->repairs->history($data['company_id'])]);
    }

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyIds = $this->scope->companyIds($request->user(), 'sales.collections.view-all');
        $query = DB::table('companies')->whereNull('deleted_at')
            ->when($companyIds !== null, fn ($scope) => $scope->whereIn('id', $companyIds));
        if (! empty($data['selected_id'])) $query->where('id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($scope) => $scope->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $query->select(['id', 'name', 'city', 'is_active', 'is_default'])
            ->orderByDesc('is_default')->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($company) => [
            'value' => (string) $company->id, 'label' => $company->name,
            'metadata' => array_filter(['city' => $company->city, 'is_default' => (bool) $company->is_default,
                'availability' => $company->is_active ? null : 'Inactive']),
            'status' => $company->is_active ? 'active' : 'inactive',
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['booking_number' => ['required', 'string', 'max:80']]);
        $companyId = $this->authorizeBooking($request, $data['booking_number']);

        return response()->json(['status' => 'success', 'data' => $this->repairs->preview($data['booking_number'], $companyId)]);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_number' => ['required', 'string', 'max:80'],
            'preview_checksum' => ['required', 'string', 'size:64'],
            'evidence_file_id' => ['required', 'uuid', 'exists:domain_evidence_files,id'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $companyId = $this->authorizeBooking($request, $data['booking_number']);
        $result = $this->repairs->apply($data['booking_number'], $data, (string) $request->user()->id, $companyId);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function rollbackPreview(Request $request): JsonResponse
    {
        $data = $request->validate(['booking_number' => ['required', 'string', 'max:80']]);
        $companyId = $this->authorizeBooking($request, $data['booking_number']);

        return response()->json(['status' => 'success', 'data' => $this->repairs->rollbackPreview($data['booking_number'], $companyId)]);
    }

    public function rollback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_number' => ['required', 'string', 'max:80'],
            'preview_checksum' => ['required', 'string', 'size:64'],
            'evidence_file_id' => ['required', 'uuid', 'exists:domain_evidence_files,id'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $companyId = $this->authorizeBooking($request, $data['booking_number']);
        $result = $this->repairs->rollback($data['booking_number'], $data, (string) $request->user()->id, $companyId);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    private function authorizeBooking(Request $request, string $bookingNumber): string
    {
        $booking = Booking::query()->where('booking_number', $bookingNumber)->firstOrFail();
        $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
        abort_unless($attribution?->company_id, 409, 'The booking has no established legal entity and is outside this repair workflow.');
        $this->scope->assertCompany($request->user(), $attribution->company_id, 'sales.collections.view-all');

        return (string) $attribution->company_id;
    }
}
