<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\BookingLifecycleStatus;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingExtensionRequest;
use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Vehicle\VehicleAssignment;
use App\Models\Vehicle\Vehicle;
use App\Models\DriverAssignment;
use App\Models\Driver\Driver;
use App\Services\BookingPaymentLedgerService;
use App\Services\BookingFlowService;
use App\Services\CompanySettingsAccessService;
use App\Services\BookingWorkflowSettingsResolver;
use App\Services\WebsiteSettingsService;
use App\Services\TimezoneService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingExtensionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
        abort_unless($companyId, 409, 'Select a business before reviewing extension requests.');
        app(CompanySettingsAccessService::class)->assertCanAccess($request->user(), (string) $companyId);

        $requests = BookingExtensionRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->with([
                'booking:id,booking_number,customer_id',
                'booking.customer.user:id,first_name,last_name',
                'bookingItem:id,booking_id,item_code,from_date,to_date,vehicle_id,driver_id',
                'bookingItem.vehicle:id,title,license_plate',
                'requester:id,first_name,last_name',
            ])
            ->orderBy('created_at')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json(['status' => 'success', 'data' => $requests]);
    }

    public function store(Request $request, string $bookingItemId): JsonResponse
    {
        $data = $request->validate([
            'new_end_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'in:customer_request,flight_delay,event_extension,business_need,other'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $companyId = app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
        abort_unless($companyId, 409, 'Select a business before requesting an extension.');
        app(CompanySettingsAccessService::class)->assertCanAccess($request->user(), (string) $companyId);

        $extension = DB::transaction(function () use ($data, $request, $companyId, $bookingItemId) {
            $bookingQuery = Booking::query()->whereHas('bookingItems', fn ($query) => $query->whereKey($bookingItemId));
            \App\Services\CompanyBookingScope::constrain($bookingQuery, 'bookings', (string) $companyId);
            $booking = $bookingQuery->lockForUpdate()->firstOrFail();
            $item = BookingItem::query()->where('booking_id', $booking->id)->whereKey($bookingItemId)->lockForUpdate()->firstOrFail();
            abort_unless(BookingLifecycleStatus::tryFrom((string) $booking->status) === BookingLifecycleStatus::ONGOING_ACTIVE, 409,
                'Only an active rental can request an extension.');
            $workflow = app(BookingWorkflowSettingsResolver::class)->resolve($item->workflow_config_snapshot, (string) $companyId);
            abort_unless($workflow['enable_return_stage'], 422, 'This service does not use rental return extensions.');

            if (BookingExtensionRequest::query()->where('booking_item_id', $item->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['new_end_date' => ['This rental already has an extension waiting for review.']]);
            }

            $attributes = $item->getAttributes();
            abort_unless(!empty($attributes['to_date']), 409, 'The rental has no scheduled return time to extend.');
            $currentEnd = CarbonImmutable::parse($attributes['to_date'], 'UTC');
            $localEndTime = $currentEnd->setTimezone(TimezoneService::getUserTimezone())->format('H:i:s');
            $requestedEnd = CarbonImmutable::instance(TimezoneService::toUtc($data['new_end_date'].' '.$localEndTime));
            if ($requestedEnd->lessThanOrEqualTo($currentEnd)) {
                throw ValidationException::withMessages(['new_end_date' => ['Choose a date after the current return date.']]);
            }

            $extension = BookingExtensionRequest::create([
                'company_id' => $companyId,
                'booking_id' => $booking->id,
                'booking_item_id' => $item->id,
                'current_end_at' => $currentEnd,
                'requested_end_at' => $requestedEnd,
                'reason' => $data['reason'],
                'notes' => isset($data['notes']) ? trim($data['notes']) : null,
                'status' => 'pending',
                'requested_by' => (string) $request->user()->id,
            ]);

            BookingActivity::create([
                'company_id' => $companyId,
                'booking_id' => $booking->id,
                'booking_item_id' => $item->id,
                'event_key' => 'rental_extension_requested',
                'channel' => 'system',
                'result_status' => 'pending',
                'source' => 'portal',
                'title' => 'Rental extension requested',
                'detail' => $data['reason'],
                'idempotency_key' => 'booking-extension-request-'.$extension->id,
                'meta' => [
                    'extension_request_id' => $extension->id,
                    'current_end_at' => $currentEnd->toIso8601String(),
                    'requested_end_at' => $requestedEnd->toIso8601String(),
                ],
                'event_at' => now(),
            ]);

            return $extension;
        });

        return response()->json(['status' => 'success', 'data' => $extension, 'message' => 'Extension request sent for manager review.'], 201);
    }

    public function decide(Request $request, string $extensionId): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_notes' => ['required', 'string', 'max:3000'],
            'additional_charge' => ['required_if:decision,approved', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
        $companyId = app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
        abort_unless($companyId, 409, 'Select a business before reviewing extension requests.');
        app(CompanySettingsAccessService::class)->assertCanAccess($request->user(), (string) $companyId);

        $extension = DB::transaction(function () use ($data, $request, $companyId, $extensionId) {
            $extension = BookingExtensionRequest::query()->where('company_id', $companyId)->whereKey($extensionId)->lockForUpdate()->firstOrFail();
            abort_unless($extension->status === 'pending', 409, 'This extension request has already been reviewed.');
            $booking = Booking::query()->whereKey($extension->booking_id)->lockForUpdate()->firstOrFail();
            $item = BookingItem::query()->where('booking_id', $booking->id)->whereKey($extension->booking_item_id)->lockForUpdate()->firstOrFail();
            abort_unless(BookingLifecycleStatus::tryFrom((string) $booking->status) === BookingLifecycleStatus::ONGOING_ACTIVE, 409, 'This rental is no longer active.');
            abort_unless(app(BookingWorkflowSettingsResolver::class)->resolve($item->workflow_config_snapshot, (string) $companyId)['enable_return_stage'], 409, 'This service no longer supports rental extensions.');
            $currentEnd = CarbonImmutable::parse($item->getAttributes()['to_date'], 'UTC');
            abort_unless($currentEnd->equalTo(CarbonImmutable::parse($extension->current_end_at)->utc()), 409, 'The rental return time changed after this request was submitted.');

            if ($data['decision'] === 'approved') {
                abort_unless($item->vehicle_id && $item->vehicle_group_id && $item->service_type_id, 409, 'Assign a vehicle and service type before approving the extension.');
                $flow = app(BookingFlowService::class);
                $flow->lockVehicleGroupCapacity([(string) $item->vehicle_group_id]);
                Vehicle::query()->whereKey($item->vehicle_id)->lockForUpdate()->firstOrFail();
                $vehicleAssignment = VehicleAssignment::query()->where('booking_id', $booking->id)->where('vehicle_id', $item->vehicle_id)->where('status', 'active')->lockForUpdate()->first();
                abort_unless($vehicleAssignment, 409, 'Assign a vehicle to this rental before approving the extension.');
                $newEnd = CarbonImmutable::parse($extension->requested_end_at)->utc();
                $localNewEnd = $newEnd->setTimezone(TimezoneService::getUserTimezone());
                $availabilityStart = $currentEnd->addSecond()->setTimezone(TimezoneService::getUserTimezone());
                $availability = $flow->getAvailableVehicleGroups([
                    'service_type' => (string) $item->service_type_id,
                    'vehicle_group_id' => (string) $item->vehicle_group_id,
                    'from_date' => $availabilityStart->toDateString(),
                    'from_time' => $availabilityStart->format('H:i:s'),
                    'to_date' => $localNewEnd->toDateString(),
                    'to_time' => $localNewEnd->format('H:i:s'),
                    'exclude_booking_id' => $booking->id,
                    'page' => 1,
                    'per_page' => 1,
                    'defer_pricing' => true,
                ]);
                $groupAvailability = collect($availability['data'] ?? [])->firstWhere('id', (string) $item->vehicle_group_id);
                $siblingReservations = BookingItem::query()->where('booking_id', $booking->id)
                    ->where('id', '!=', $item->id)->where('vehicle_group_id', $item->vehicle_group_id)
                    ->whereNotIn('status', ['cancelled', 'completed', 'returned'])
                    ->where('from_date', '<', $newEnd)->where('to_date', '>', $currentEnd)->count();
                abort_unless((int) ($groupAvailability['available_count'] ?? 0) - $siblingReservations > 0, 409, 'There is not enough vehicle capacity for those dates.');
                abort_unless(!VehicleAssignment::query()->where('vehicle_id', $item->vehicle_id)->whereIn('status', ['active', 'pending_approval'])->whereKeyNot($vehicleAssignment->id)->where('assigned_from', '<', $newEnd)->where('assigned_to', '>', $currentEnd)->exists(), 409, 'This vehicle is assigned to another rental during the requested extension.');
                if ($item->driver_id) {
                    Driver::query()->whereKey($item->driver_id)->lockForUpdate()->firstOrFail();
                    $driverAssignment = DriverAssignment::query()->where('booking_id', $booking->id)->where('booking_item_id', $item->id)->where('driver_id', $item->driver_id)->where('status', 'active')->lockForUpdate()->first();
                    abort_unless($driverAssignment, 409, 'The assigned driver schedule could not be confirmed.');
                    abort_unless(!DriverAssignment::query()->where('driver_id', $item->driver_id)->whereIn('status', ['active', 'pending_approval'])->whereKeyNot($driverAssignment->id)->where('assigned_from', '<', $newEnd)->where('assigned_to', '>', $currentEnd)->exists(), 409, 'This driver is assigned to another job during the requested extension.');
                    $driverAssignment->update(['assigned_to' => $localNewEnd, 'updated_user_id' => (string) $request->user()->id]);
                }
                $vehicleAssignment->update(['assigned_to' => $localNewEnd, 'updated_user_id' => (string) $request->user()->id]);
                $oldTotal = (float) ($booking->total_actual ?? $booking->total_estimated ?? $booking->amount_to_pay ?? 0);
                $charge = round((float) $data['additional_charge'], 2);
                abort_unless(($booking->payment_arrangement_status ?? '') !== 'complimentary' || $charge === 0.0, 422, 'Complimentary bookings cannot have an extension charge.');
                $collectedAmount = $booking->payment_collected_amount;
                if ($collectedAmount === null
                    && !BookingPaymentReceipt::query()->where('booking_id', $booking->id)->exists()
                    && in_array(strtolower((string) $booking->payment_status), ['paid', 'success', 'online_paid'], true)) {
                    $collectedAmount = $oldTotal;
                }
                $startRaw = $item->getAttributes()['from_date'] ?? null;
                abort_unless($startRaw, 409, 'The rental has no recorded pickup time for duration recalculation.');
                $localStart = CarbonImmutable::parse($startRaw, 'UTC')->setTimezone(TimezoneService::getUserTimezone());
                $duration = $flow->calculateDurationInDaysAndHours(\Carbon\Carbon::instance($localStart), \Carbon\Carbon::instance($localNewEnd));
                $item->update([
                    'to_date' => $localNewEnd,
                    'duration_days' => $duration['days'],
                    'duration_hours' => (int) ceil($duration['hours']),
                    'duration_minutes' => $duration['minutes'],
                    'total_price' => (float) $item->total_price + $charge,
                ]);
                if ($charge > 0) {
                    $booking->update([
                        'base_amount' => (float) ($booking->base_amount ?? $oldTotal) + $charge,
                        'total_actual' => $oldTotal + $charge,
                        'total_estimated' => $oldTotal + $charge,
                        'payment_collected_amount' => $collectedAmount,
                    ]);
                    app(BookingPaymentLedgerService::class)->addScheduleItem($booking, ['label' => 'Rental extension', 'due_date' => $localNewEnd->toDateString(), 'amount' => $charge], (string) $request->user()->id);
                    $summary = app(BookingPaymentLedgerService::class)->summary($booking->fresh());
                    $booking->update(['amount_to_pay' => $summary['due_amount'], 'payment_status' => $summary['payment_status'], 'payment_collection_status' => $summary['due_amount'] <= 0 ? 'paid' : ($summary['paid_amount'] > 0 ? 'partially_paid' : 'pending')]);
                }
            }

            $extension->update(['status' => $data['decision'], 'additional_charge' => $data['decision'] === 'approved' ? round((float) $data['additional_charge'], 2) : null, 'charge_currency' => $data['decision'] === 'approved' ? (strtoupper((string) $booking->currency) ?: null) : null, 'review_notes' => trim($data['review_notes']), 'reviewed_by' => (string) $request->user()->id, 'reviewed_at' => now()]);
            BookingActivity::create(['company_id' => $companyId, 'booking_id' => $booking->id, 'booking_item_id' => $item->id, 'event_key' => 'rental_extension_'.$data['decision'], 'channel' => 'system', 'result_status' => $data['decision'], 'source' => 'portal', 'title' => 'Rental extension '.$data['decision'], 'detail' => trim($data['review_notes']), 'idempotency_key' => 'booking-extension-decision-'.$extension->id, 'meta' => ['extension_request_id' => $extension->id, 'previous_end_at' => $currentEnd->toIso8601String(), 'requested_end_at' => $extension->requested_end_at->toIso8601String(), 'additional_charge' => $extension->additional_charge], 'event_at' => now()]);
            return $extension->fresh();
        });
        return response()->json(['status' => 'success', 'data' => $extension]);
    }
}
