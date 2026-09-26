<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Booking\BookingFlowController;
use App\Models\Customer;
use App\Models\Booking\Booking;
use App\Services\UserContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPortalBookingController extends BookingFlowController
{
    /** Read-only, owner-scoped request list. */
    public function index(Request $request): JsonResponse
    {
        $customer = $this->activeCustomer($request);
        $bookings = Booking::query()
            ->where('customer_id', $customer->id)
            ->where('is_corporate_booking', false)
            ->with(['bookingItems' => fn ($query) => $query->select([
                'id', 'booking_id', 'from_date', 'from_time', 'to_date', 'to_time',
                'pickup_location', 'dropoff_location',
            ])])
            ->select(['id', 'customer_id', 'booking_number', 'status', 'approval_status', 'created_at'])
            ->latest('created_at')
            ->paginate(min(25, max(1, (int) $request->integer('per_page', 10))));

        return response()->json([
            'status' => 'success',
            'data' => $bookings->getCollection()->map(fn (Booking $booking) => $this->requestSummary($booking))->values(),
            'pagination' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'total' => $bookings->total(),
            ],
        ]);
    }

    /** Read-only detail; a foreign ID has the same 404 outcome as an unknown ID. */
    public function show(Request $request, string $id): JsonResponse
    {
        $customer = $this->activeCustomer($request);
        $booking = Booking::query()
            ->whereKey($id)
            ->where('customer_id', $customer->id)
            ->where('is_corporate_booking', false)
            ->with(['bookingItems' => fn ($query) => $query->select([
                'id', 'booking_id', 'from_date', 'from_time', 'to_date', 'to_time',
                'pickup_location', 'dropoff_location',
            ])])
            ->select(['id', 'customer_id', 'booking_number', 'status', 'approval_status', 'created_at'])
            ->firstOrFail();

        return response()->json(['status' => 'success', 'data' => $this->requestSummary($booking)]);
    }

    private function requestSummary(Booking $booking): array
    {
        $status = strtolower((string) $booking->status);
        $approval = strtolower((string) $booking->approval_status);
        $progress = match (true) {
            $approval === 'rejected' || $status === 'rejected' => 'Rejected',
            $status === 'cancelled' => 'Cancelled',
            $status === 'completed' => 'Completed',
            in_array($status, ['confirmed', 'approved'], true) || $approval === 'approved' => 'Confirmed',
            $approval === 'pending' || in_array($status, ['pending_approval', 'under_review'], true) => 'Under review',
            default => 'Received',
        };

        return [
            'id' => (string) $booking->id,
            'reference' => $booking->booking_number ?: (string) $booking->id,
            'submitted_at' => $booking->created_at?->toIso8601String(),
            'request_status' => $progress,
            'trips' => $booking->bookingItems->map(fn ($item) => [
                'id' => (string) $item->id,
                'pickup' => $this->locationLabel($item->pickup_location),
                'destination' => $this->locationLabel($item->dropoff_location),
                'from_date' => $item->from_date,
                'from_time' => $item->from_time,
                'to_date' => $item->to_date,
                'to_time' => $item->to_time,
            ])->values()->all(),
            'support_hint' => 'Contact TheTaxi support and quote this reference for help with your request.',
        ];
    }

    private function locationLabel(mixed $value): ?string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }
        if (is_array($value)) {
            foreach (['address', 'formatted_address', 'label', 'name', 'description'] as $key) {
                if (is_string($value[$key] ?? null) && trim($value[$key]) !== '') return trim($value[$key]);
            }
            return null;
        }
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function activeCustomer(Request $request): Customer
    {
        $user = $request->user();
        abort_unless($user, 403, 'An active customer profile is required.');
        $activeContext = app(UserContextService::class)->resolveActiveContextFromRequest($user, $request);
        abort_unless($activeContext && ($activeContext['portal_profile'] ?? null) === 'customer', 403,
            'An active customer profile is required.');
        abort_if($request->header('X-Active-Context-Id') &&
            $request->header('X-Active-Context-Id') !== (string) $activeContext['id'], 403,
            'The selected customer context is unavailable.');
        abort_if($request->header('X-Active-Context-Type') &&
            $request->header('X-Active-Context-Type') !== 'customer', 403,
            'A customer context is required.');
        abort_if($request->header('X-Active-Portal-Profile') &&
            $request->header('X-Active-Portal-Profile') !== 'customer', 403,
            'A customer profile is required.');
        $context = $user->contexts()->whereKey($activeContext['id'])
            ->where('context_type', 'customer')->where('is_active', true)->first();
        $customer = $context?->context_id
            ? Customer::query()->whereKey($context->context_id)->where('user_id', $user->id)->first()
            : null;
        abort_unless($customer, 403, 'An active customer profile is required.');
        return $customer;
    }

    /**
     * Create a customer-owned booking request for staff review.
     *
     * Customer identity and confirmation state are server-controlled. This
     * endpoint intentionally provides no customer payment, draft, or quote
     * actions while those capabilities remain undefined.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeContext = $user
            ? app(UserContextService::class)->resolveActiveContextFromRequest($user, $request)
            : null;
        $customerContext = $activeContext && ($activeContext['portal_profile'] ?? null) === 'customer'
            ? $user->contexts()
                ->whereKey($activeContext['id'])
                ->where('context_type', 'customer')
                ->where('is_active', true)
                ->first()
            : null;

        $customer = $customerContext && $customerContext->context_id
            ? Customer::query()
                ->whereKey($customerContext->context_id)
                ->where('user_id', $user->id)
                ->first()
            : null;

        abort_unless($customer, 403, 'An active customer profile is required.');

        $payload = $request->except([
            'customer_id',
            'corporate_account_id',
            'corporate_employee_id',
            'corporate_department_id',
            'corporate_division_id',
            'employee_id',
            'booking_party_mode',
            'corporate_contact',
            'payment_collection_method',
            'payment_responsibility',
            'booking_id',
            'session_id',
            'defer_confirmation',
            'send_confirmation_sms',
            'send_confirmation_emails',
            'notify_internal_team',
            'applied_discounts',
            'discounts',
            'override_reasons',
            'approval_reason',
            'preserve_custom_pricing',
            'preserve_custom_addon_prices',
            'force_recalculation',
            'vehicles',
            'drivers',
            'vehicle_driver_assignments',
        ]);

        $payload['customer_id'] = (string) $customer->id;
        $payload['is_corporate_booking'] = false;
        $payload['payment_responsibility'] = 'customer';
        $payload['defer_confirmation'] = true;
        $payload['send_confirmation_sms'] = false;
        $payload['send_confirmation_emails'] = false;
        $payload['notify_internal_team'] = false;

        if (is_array($payload['booking_items'] ?? null)) {
            foreach ($payload['booking_items'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                unset(
                    $item['id'],
                    $item['final_price'],
                    $item['total_price'],
                    $item['price_override_amount'],
                    $item['price_adjustment_reason'],
                    $item['vehicles'],
                    $item['drivers'],
                    $item['vehicle_driver_assignments']
                );
                if (is_array($item['selected_addons'] ?? null)) {
                    foreach ($item['selected_addons'] as &$addon) {
                        if (is_array($addon)) {
                            unset($addon['custom_price'], $addon['total_price']);
                        }
                    }
                    unset($addon);
                }
            }
            unset($item);
        }
        unset($payload['variable_customizations']);
        $payload = $this->stripPriceOverrideInputs($payload);

        $request->replace($payload);
        $response = parent::confirmBooking($request);
        $body = $response->getData(true);
        $body['message'] = 'Your booking request was received and is awaiting confirmation.';
        $body['requires_confirmation'] = true;
        $booking = $body['data'] ?? [];
        $body['data'] = [
            'id' => $booking['id'] ?? null,
            'booking_number' => $booking['booking_number'] ?? null,
            'status' => $booking['status'] ?? 'pending',
        ];

        return response()->json($body, $response->getStatusCode());
    }

    private function stripPriceOverrideInputs(array $values): array
    {
        $blockedKeys = [
            'custom_price',
            'final_price',
            'total_price',
            'price_override_amount',
            'price_adjustment_reason',
            'custom_rate',
            'is_custom_price',
        ];

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $blockedKeys, true)) {
                unset($values[$key]);
                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->stripPriceOverrideInputs($value);
            }
        }

        return $values;
    }
}
