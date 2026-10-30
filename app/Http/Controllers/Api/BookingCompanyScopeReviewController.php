<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingActivity;
use App\Models\Company;
use App\Models\Corporate\Corporate;
use App\Services\CorporateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingCompanyScopeReviewController extends Controller
{
    public function __construct()
    {
        $this->middleware(['ensure.internal', 'permission:companies.view'])->only('index');
        $this->middleware(['ensure.internal', 'permission:companies.edit'])->only('assign');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Booking::query()->with(['customer.user', 'corporateAccount'])->whereNull('company_id')->orderByDesc('created_at');
        if (! empty($data['search'])) {
            $term = '%' . addcslashes(trim($data['search']), '%_\\') . '%';
            $query->where(fn ($search) => $search->where('booking_number', 'like', $term)
                ->orWhere('log_code', 'like', $term)
                ->orWhereHas('customer.user', fn ($user) => $user->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)));
        }

        $bookings = $query->paginate((int) ($data['per_page'] ?? 25));
        $evidence = $this->evidenceForBookings($bookings->getCollection()->pluck('id')->all());
        $bookings->getCollection()->transform(function (Booking $booking) use ($evidence): array {
            $customer = $booking->customer;
            $name = trim(($customer?->user?->first_name ?? '') . ' ' . ($customer?->user?->last_name ?? ''));

            return [
                'id' => (string) $booking->id,
                'booking_number' => $booking->booking_number,
                'status' => (string) $booking->status,
                'created_at' => $booking->created_at,
                'customer_name' => $name ?: null,
                'customer_phone' => $customer?->user?->phone,
                'corporate_name' => $booking->corporateAccount?->name,
                'evidence' => $evidence->get((string) $booking->id, collect())->values(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'bookings' => $bookings,
                'companies' => Company::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            ],
        ]);
    }

    public function assign(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'acknowledge_business_evidence' => ['sometimes', 'accepted'],
        ]);

        abort_unless(Company::query()->whereKey($data['company_id'])->where('is_active', true)->exists(), 422, 'Choose an active business.');

        [$booking, $assignedBookingCount] = DB::transaction(function () use ($request, $bookingId, $data): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            abort_if($booking->company_id, 409, 'This booking already has a business. Refresh the review list.');

            $evidence = $this->evidenceForBookings([(string) $booking->id])->get((string) $booking->id, collect());
            $evidenceConflicts = $evidence->count() !== 1
                || ! \App\Services\CompanyIdScope::sameCompanyId((string) ($evidence->first()['company_id'] ?? null), (string) $data['company_id']);
            $requiresAcknowledgement = $evidenceConflicts || (bool) $booking->corporate_account_id;
            abort_if($requiresAcknowledgement && ! $request->boolean('acknowledge_business_evidence'), 422,
                'Review the business clues and confirm your choice before assigning this booking.');

            $bookingsToAssign = collect([$booking]);
            $corporateToAssign = null;
            if ($booking->corporate_account_id) {
                $corporate = Corporate::query()->lockForUpdate()->findOrFail($booking->corporate_account_id);
                abort_if(! \App\Services\CompanyIdScope::sameCompanyId((string) ($corporate->company_id), (string) ($data['company_id'])), 409,
                    'This corporate account belongs to another business. Choose its business or review the corporate account first.');
                abort_if(Booking::query()->where('corporate_account_id', $corporate->id)
                    ->whereNot(fn ($scope) => \App\Services\CompanyIdScope::constrain($scope, 'company_id', $data['company_id']))->exists(), 409,
                    'Other bookings for this corporate account are assigned to another business. Review those records first.');
                $corporateToAssign = $corporate;
                $bookingsToAssign = Booking::query()->where('corporate_account_id', $corporate->id)
                    ->whereNull('company_id')->lockForUpdate()->get();
            }

            $bookingEvidence = $this->evidenceForBookings($bookingsToAssign->pluck('id')->map(fn ($id) => (string) $id)->all());
            if ($corporateToAssign && ! $corporateToAssign->company_id) {
                app(CorporateService::class)->updateCorporate($corporateToAssign, [
                    'company_id' => $data['company_id'],
                    'updated_user_id' => $request->user()->id,
                ]);
            }
            foreach ($bookingsToAssign as $linkedBooking) {
                $linkedBooking->forceFill([
                    'company_id' => $data['company_id'],
                    'updated_user_id' => $request->user()->id,
                ])->save();

                BookingActivity::create([
                    'company_id' => $data['company_id'],
                    'booking_id' => $linkedBooking->id,
                    'event_key' => 'booking_business_scope_assigned',
                    'channel' => 'system',
                    'result_status' => 'completed',
                    'source' => 'internal_admin',
                    'title' => 'Booking business confirmed',
                    'detail' => trim($data['reason']),
                    'idempotency_key' => 'booking-business-scope-' . Str::uuid(),
                    'meta' => [
                        'from_company_id' => null,
                        'to_company_id' => (string) $data['company_id'],
                        'corporate_account_id' => $linkedBooking->corporate_account_id,
                        'evidence' => $bookingEvidence->get((string) $linkedBooking->id, collect())->all(),
                        'actor_user_id' => (string) $request->user()->id,
                    ],
                    'event_at' => now(),
                ]);
            }

            return [$booking->fresh(), $bookingsToAssign->count()];
        });

        return response()->json(['status' => 'success', 'data' => [
            'booking_id' => (string) $booking->id,
            'company_id' => (string) $booking->company_id,
            'assigned_booking_count' => $assignedBookingCount,
        ]]);
    }

    private function evidenceForBookings(array $bookingIds): Collection
    {
        if ($bookingIds === []) {
            return collect();
        }

        $sources = [
            [DB::table('sales_booking_attributions as attribution')->join('companies as company', 'company.id', '=', 'attribution.company_id')
                ->whereIn('attribution.booking_id', $bookingIds)->selectRaw("attribution.booking_id, company.id as company_id, company.name as company_name, 'Sales attribution' as source")],
            [DB::table('bookings as booking')->join('corporates as corporate', 'corporate.id', '=', 'booking.corporate_account_id')->join('companies as company', 'company.id', '=', 'corporate.company_id')
                ->whereIn('booking.id', $bookingIds)->selectRaw("booking.id as booking_id, company.id as company_id, company.name as company_name, 'Corporate account' as source")],
            [DB::table('bookings as booking')->join('staff as staff', 'staff.user_id', '=', 'booking.responsible_user_id')->join('companies as company', 'company.id', '=', 'staff.company_id')
                ->whereIn('booking.id', $bookingIds)->selectRaw("booking.id as booking_id, company.id as company_id, company.name as company_name, 'Responsible employee' as source")],
            [DB::table('bookings as booking')->join('staff as staff', 'staff.user_id', '=', 'booking.created_by_user_id')->join('companies as company', 'company.id', '=', 'staff.company_id')
                ->whereIn('booking.id', $bookingIds)->selectRaw("booking.id as booking_id, company.id as company_id, company.name as company_name, 'Created by employee' as source")],
            [DB::table('bookings as booking')->join('staff as staff', 'staff.id', '=', 'booking.commission_owner_staff_id')->join('companies as company', 'company.id', '=', 'staff.company_id')
                ->whereIn('booking.id', $bookingIds)->selectRaw("booking.id as booking_id, company.id as company_id, company.name as company_name, 'Commission owner' as source")],
            [DB::table('bookings as booking')->join('sales_opportunities as opportunity', 'opportunity.id', '=', 'booking.sales_opportunity_id')->join('companies as company', 'company.id', '=', 'opportunity.company_id')
                ->whereIn('booking.id', $bookingIds)->selectRaw("booking.id as booking_id, company.id as company_id, company.name as company_name, 'Sales opportunity' as source")],
            [DB::table('bookings as booking')->join('booking_items as item', 'item.booking_id', '=', 'booking.id')->join('vehicles as vehicle', 'vehicle.id', '=', 'item.vehicle_id')->join('companies as company', 'company.id', '=', 'vehicle.company_id')
                ->whereIn('booking.id', $bookingIds)->selectRaw("booking.id as booking_id, company.id as company_id, company.name as company_name, 'Assigned vehicle' as source")],
            [DB::table('bookings as booking')->join('booking_items as item', 'item.booking_id', '=', 'booking.id')->join('drivers as driver', 'driver.id', '=', 'item.driver_id')->join('staff as staff', 'staff.user_id', '=', 'driver.user_id')->join('companies as company', 'company.id', '=', 'staff.company_id')
                ->whereIn('booking.id', $bookingIds)->selectRaw("booking.id as booking_id, company.id as company_id, company.name as company_name, 'Assigned driver' as source")],
            [DB::table('bookings as booking')->join('booking_items as item', 'item.booking_id', '=', 'booking.id')->join('service_types as service', 'service.id', '=', 'item.service_type_id')->join('companies as company', function ($join) {
                $join->whereRaw('company.id::text = service.owner_id')->where('service.owner_type', 'company');
            })->whereIn('booking.id', $bookingIds)->selectRaw("booking.id as booking_id, company.id as company_id, company.name as company_name, 'Service type' as source")],
        ];

        return collect($sources)->flatMap(fn (array $source) => $source[0]->get())
            ->groupBy(fn ($row) => (string) $row->booking_id)
            ->map(fn (Collection $rows) => $rows->groupBy(fn ($row) => (string) $row->company_id)
                ->map(fn (Collection $companyRows) => [
                    'company_id' => (string) $companyRows->first()->company_id,
                    'company_name' => $companyRows->first()->company_name,
                    'sources' => $companyRows->pluck('source')->unique()->values(),
                ])->values());
    }
}
