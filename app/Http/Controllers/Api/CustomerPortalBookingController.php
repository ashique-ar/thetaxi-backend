<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Booking\BookingFlowController;
use App\Models\Customer;
use App\Services\UserContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPortalBookingController extends BookingFlowController
{
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
