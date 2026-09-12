<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Website\PendingPaymentLink;
use App\Services\UrlShortenerService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PendingPaymentManager
 * 
 * Manages pending payment links and recovery data independently of cart
 * Generates persistent payment links with full booking context for email communication
 * Allows customers to resume payment from emails without relying on cart state
 */
class PendingPaymentManager
{
    /**
     * Create a persistent pending payment link
     * Stores full booking context in database for secure retrieval
     * 
     * @param Booking $booking
     * @param int $expiryHours Default: 7 days (168 hours)
     * @return PendingPaymentLink
     */
    public static function createPaymentLink(Booking $booking, int $expiryHours = 168): PendingPaymentLink
    {
        // Generate unique secure token
        $token = Str::random(64);

        // Prepare booking context data (with enhanced logging)
        try {
            $bookingContext = self::prepareBookingContext($booking);
        } catch (\Exception $e) {
            Log::error('PendingPaymentManager: Failed preparing booking context', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        // Calculate amount due - handle various booking states
        $amountDue = self::calculateAmountDue($booking);

        if ($amountDue <= 0) {
            Log::warning('PendingPaymentManager: Amount due is zero or negative when creating payment link', [
                'booking_id' => $booking->id ?? null,
                'total_estimated' => $booking->total_estimated ?? null,
                'amount_to_pay' => $booking->amount_to_pay ?? null,
                'quotation_amount' => $booking->quotation_amount ?? null,
                'calculated_amount_due' => $amountDue,
            ]);
        }

        // Reuse active pending payment link if one exists to avoid invalidating previously sent tokens
        $existing = PendingPaymentLink::where('booking_id', $booking->id)
            ->where('type', 'payment_reminder')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($existing) {

            // Ensure context and amount are up-to-date
            $existing->update([
                'booking_context' => json_encode($bookingContext),
                'amount_due' => $amountDue,
                'updated_at' => Carbon::now(),
            ]);

            return $existing;
        }

        // No active link found: create a new persistent payment link record
        $paymentLink = PendingPaymentLink::create([
            'booking_id' => $booking->id,
            'token' => $token,
            'type' => 'payment_reminder',
            'booking_context' => json_encode($bookingContext),
            'amount_due' => $amountDue,
            'expires_at' => Carbon::now()->addHours($expiryHours),
            'accessed_at' => null,
            'access_count' => 0,
        ]);


        return $paymentLink;
    }

    /**
     * Calculate amount due for various booking types
     * 
     * @param Booking $booking
     * @return float
     */
    private static function calculateAmountDue(Booking $booking): float
    {
        // For quotations, try multiple fields to get the amount
        $totalAmount = $booking->total_estimated
            ?? $booking->amount_to_pay
            ?? $booking->quotation_amount
            ?? $booking->base_amount
            ?? 0;

        $amountPaid = $booking->amount_paid ?? 0;

        return max(0, $totalAmount - $amountPaid);
    }

    /**
     * Generate full payment link URL for email
     * 
     * @param Booking $booking
     * @param int $expiryHours
     * @return string
     */
    public static function generatePaymentLinkUrl(Booking $booking, int $expiryHours = 168): string
    {
        try {

            $paymentLink = self::createPaymentLink($booking, $expiryHours);

            $originalUrl = route('checkout.payment-resume', [
                'token' => $paymentLink->token
            ]);

            // Create shortened URL for email-friendly links
            $shortenedUrl = UrlShortenerService::shorten(
                $originalUrl,
                $expiryHours,
                'booking',
                $booking->id,
                'email', // source
                'payment_reminder', // campaign
                'transactional', // medium
                [ // metadata
                    'booking_number' => $booking->booking_number ?? null,
                    'booking_status' => $booking->status ?? null,
                    'amount_due' => $paymentLink->amount_due,
                    'customer_id' => $booking->customer_id ?? null,
                ]
            );

            $shortUrl = UrlShortenerService::getShortUrl($shortenedUrl);


            return $shortUrl;
        } catch (\Exception $e) {
            Log::error('PendingPaymentManager: Payment link generation failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Fallback to direct checkout
            return route('checkout');
        }
    }

    /**
     * Retrieve and validate pending payment link
     * Marks link as accessed and updates access count
     * 
     * @param string $token
     * @return array|null
     */
    public static function retrievePaymentLink(string $token): ?array
    {
        try {
            $paymentLink = PendingPaymentLink::where('token', $token)
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if (!$paymentLink) {
                Log::warning('PendingPaymentManager: retrievePaymentLink did not find a matching record', [
                    'token' => $token,
                ]);

                return null;
            }

            // Update access tracking
            $paymentLink->update([
                'accessed_at' => Carbon::now(),
                'access_count' => $paymentLink->access_count + 1,
            ]);

            // Return booking context and payment details
            return [
                'booking' => $paymentLink->booking()->first(),
                'context' => json_decode($paymentLink->booking_context, true),
                'amount_due' => $paymentLink->amount_due,
                'token' => $token,
                'expires_at' => $paymentLink->expires_at,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Prepare complete booking context for payment recovery
     * Includes all necessary data to recreate checkout session
     * 
     * @param Booking $booking
     * @return array
     */
    private static function prepareBookingContext(Booking $booking): array
    {
        try {
            // Load related data - use bookingItems instead of items (quotation bookings use bookingItems)
            $booking->load([
                'customer',
                'vehicleGroup',
                'serviceType',
                'bookingItems.vehicleGroup',
                'bookingItems.serviceType',
                'addons'
            ]);
        } catch (\Exception $e) {
            Log::error('PendingPaymentManager: prepareBookingContext failed to load relationships', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Safely compute items and addons counts (handle legacy/cart-based structures)
        $itemsCount = 0;
        if (method_exists($booking, 'bookingItems')) {
            $itemsCount = $booking->bookingItems?->count() ?? 0;
        } elseif (!empty($booking->items) && is_array($booking->items)) {
            $itemsCount = count($booking->items);
        }

        $addonsCount = 0;
        if (method_exists($booking, 'addons')) {
            $addonsCount = $booking->addons?->count() ?? 0;
        }

        $addonCharges = (float) ($booking->addons
            ?->reject(fn ($addon) => (bool) ($addon->is_milage ?? false))
            ->sum('amount') ?? 0);
        $extraKmCharges = (float) ($booking->addons
            ?->filter(fn ($addon) => (bool) ($addon->is_milage ?? false))
            ->sum('amount') ?? 0);
        if ($extraKmCharges <= 0 && $booking->bookingItems) {
            foreach ($booking->bookingItems as $item) {
                $metadata = is_array($item->metadata ?? null) ? $item->metadata : [];
                $extraKmCharges += (float) ($metadata['extra_km_total'] ?? 0);

                if (empty($metadata['extra_km_total'])) {
                    $customizations = is_array($item->customizations ?? null) ? $item->customizations : [];
                    foreach ($customizations as $customization) {
                        if (($customization['type'] ?? null) === 'extra_km') {
                            $extraKmCharges += (float) ($customization['total_cost'] ?? ($customization['total'] ?? 0));
                        }
                    }
                }
            }
        }

        return [
            'booking_id' => $booking->id,
            'booking_number' => $booking->booking_number,
            'customer' => [
                'id' => $booking->customer?->id,
                'name' => $booking->customer?->user?->full_name,
                'email' => $booking->customer?->user?->email,
                'phone' => $booking->customer?->user?->phone,
                'address' => $booking->customer?->address,
                'city' => $booking->customer?->city,
                'country' => $booking->customer?->country,
                'country_code' => $booking->customer?->country_code,
                'postal_code' => $booking->customer?->postal_code,
            ],
            'vehicle_group' => [
                'id' => $booking->vehicle_group_id,
                'name' => $booking->vehicleGroup?->name,
            ],
            'service_type' => [
                'id' => $booking->service_type_id,
                'code' => $booking->serviceType?->code,
                'name' => $booking->serviceType?->name,
            ],
            'dates' => [
                'from_date' => $booking->from_date?->toDateString(),
                'to_date' => $booking->to_date?->toDateString(),
                'from_time' => $booking->from_time,
                'to_time' => $booking->to_time,
            ],
            'locations' => [
                'pickup' => is_array($booking->pickup_location) ? $booking->pickup_location : json_decode($booking->pickup_location ?? '{}', true),
                'dropoff' => is_array($booking->dropoff_location) ? $booking->dropoff_location : json_decode($booking->dropoff_location ?? '{}', true),
            ],
            'pricing' => [
                'base_amount' => $booking->base_amount ?? 0,
                'service_fee' => $booking->service_fee ?? 0,
                'tax_amount' => $booking->tax_amount ?? 0,
                'vat_amount' => $booking->vat_amount ?? 0,
                'addon_charges' => $addonCharges,
                'extra_km_charges' => $extraKmCharges,
                'discount_amount' => $booking->discount_amount ?? 0,
                'total_estimated' => $booking->total_estimated ?? $booking->amount_to_pay ?? $booking->quotation_amount ?? $booking->base_amount ?? 0,
                'amount_paid' => $booking->amount_paid ?? 0,
                'currency' => $booking->currency ?? 'LKR',
            ],
            'items_count' => $itemsCount,
            'booking_items' => $booking->bookingItems->map(function ($item) {
                // Get vehicle group images
                $vehicleGroupImages = [];
                $vehicleGroup = $item->vehicleGroup;
                if ($vehicleGroup && $vehicleGroup->images) {
                    $vehicleGroupImages = is_array($vehicleGroup->images) ? $vehicleGroup->images : [];
                }

                // Process addons data with improved structure
                $addonsData = [];
                if ($item->addons && is_array($item->addons)) {
                    foreach ($item->addons as $addon) {
                        $addonName = $addon['name'] ?? ($addon['label'] ?? ($addon['addon_name'] ?? 'Unknown Add-on'));
                        $addonQty = max(1, (int) ($addon['quantity'] ?? ($addon['qty'] ?? 1)));
                        $addonRate = (float) ($addon['rate'] ?? ($addon['unit_price'] ?? ($addon['price'] ?? ($addon['amount'] ?? 0))));
                        $addonTotal = (float) ($addon['total_price'] ?? ($addon['total'] ?? ($addon['calculated_amount'] ?? ($addon['amount'] ?? ($addonRate * $addonQty)))));

                        if ($addonName && ($addonTotal > 0 || $addonRate > 0)) {
                            $addonsData[] = [
                                'name' => $addonName,
                                'quantity' => $addonQty,
                                'qty' => $addonQty, // Keep both for compatibility
                                'rate' => $addonRate,
                                'unit_price' => $addonRate, // Keep both for compatibility
                                'total_price' => $addonTotal,
                                'total' => $addonTotal, // Keep both for compatibility
                            ];
                        }
                    }
                }

                // Check for extra kilometers in customizations or metadata
                $extraKilometers = 0;
                $extraKmRate = 0;
                $extraKmTotal = 0;

                $customizations = $item->customizations ?? [];
                $metadata = $item->metadata ?? [];

                // Look for extra kilometers in various places
                if (is_array($customizations)) {
                    foreach ($customizations as $customization) {
                        if (isset($customization['type']) && in_array($customization['type'], ['extra_km', 'extra_kilometers', 'additional_km'])) {
                            $extraKilometers = $customization['quantity'] ?? $customization['km'] ?? $customization['value'] ?? 0;
                            $extraKmRate = $customization['rate_per_km'] ?? $customization['rate'] ?? $customization['price_per_km'] ?? 0;
                            $extraKmTotal = $customization['total_cost'] ?? $customization['total'] ?? ($extraKilometers * $extraKmRate);
                            break;
                        }
                    }
                }

                if ($extraKilometers == 0 && is_array($metadata)) {
                    $extraKilometers = $metadata['extra_km'] ?? $metadata['extra_kilometers'] ?? $metadata['additional_km'] ?? 0;
                    $extraKmRate = $metadata['extra_km_rate'] ?? $metadata['km_rate'] ?? 0;
                    $extraKmTotal = $metadata['extra_km_total'] ?? ($extraKilometers * $extraKmRate);
                }

                $distanceDetails = is_array($metadata['distance_details'] ?? null) ? $metadata['distance_details'] : [];
                $outboundKm = $distanceDetails['outbound_distance_km'] ?? null;
                $returnKm = $distanceDetails['return_distance_km'] ?? null;
                $durationDays = max(1, (int) ($item->duration_days ?? 1));
                $freeKmPerDay = $distanceDetails['free_km_per_day'] ?? null;
                $freeKmPerPackage = $distanceDetails['free_km_per_package'] ?? null;
                $allowedTotalKm = $distanceDetails['allowed_total_km'] ?? null;
                $includedTotalKmForExtra = null;
                if ($freeKmPerDay && $durationDays > 1) {
                    $includedTotalKmForExtra = (float) ($allowedTotalKm ?: ($freeKmPerDay * $durationDays));
                } elseif ($freeKmPerPackage) {
                    $includedTotalKmForExtra = (float) $freeKmPerPackage;
                } elseif ($allowedTotalKm) {
                    $includedTotalKmForExtra = (float) $allowedTotalKm;
                }

                $baseTotalKm = null;
                if ($outboundKm && $returnKm) {
                    $baseTotalKm = (float) $outboundKm + (float) $returnKm;
                } elseif (isset($distanceDetails['total_distance'])) {
                    $baseTotalKm = (float) $distanceDetails['total_distance'];
                } elseif (isset($distanceDetails['actual_journey_distance'])) {
                    $baseTotalKm = (float) $distanceDetails['actual_journey_distance'];
                } elseif (isset($distanceDetails['journey_distance'])) {
                    $baseTotalKm = (float) $distanceDetails['journey_distance'];
                }
                $displayBaseTotalKm = $includedTotalKmForExtra ?? $baseTotalKm;

                return [
                    'vehicle_group' => $item->vehicle_group_name ?? ($item->vehicleGroup?->name ?? 'N/A'),
                    'service_type' => $item->service_type_name ?? ($item->serviceType?->name ?? 'N/A'),
                    'from_date' => $item->from_date?->toDateString(),
                    'to_date' => $item->to_date?->toDateString(),
                    'from_time' => $item->from_time,
                    'to_time' => $item->to_time,
                    'duration_days' => $item->duration_days,
                    'pickup_location' => is_array($item->pickup_location) ? $item->pickup_location : json_decode($item->pickup_location ?? '{}', true),
                    'dropoff_location' => is_array($item->dropoff_location) ? $item->dropoff_location : json_decode($item->dropoff_location ?? '{}', true),
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'vehicle_group_images' => $vehicleGroupImages,
                    'addons' => $addonsData,
                    'extra_kilometers' => $extraKilometers,
                    'extra_km_rate' => $extraKmRate,
                    'extra_km_total' => $extraKmTotal,
                    'base_total_km' => $baseTotalKm,
                    'display_base_total_km' => $displayBaseTotalKm,
                    'total_km_with_extra' => $extraKilometers > 0 && $displayBaseTotalKm !== null
                        ? $displayBaseTotalKm + (float) $extraKilometers
                        : null,
                ];
            })->toArray(),
            'addons_count' => $addonsCount,
            'payment_method' => $booking->payment_method,
            'payment_status' => $booking->payment_status,
            'booking_status' => $booking->status,
        ];
    }

    /**
     * Invalidate payment link after successful payment
     * 
     * @param string $token
     * @return void
     */
    public static function invalidatePaymentLink(string $token): void
    {
        PendingPaymentLink::where('token', $token)
            ->update([
                'expires_at' => Carbon::now()->subMinute(),
                'invalidated_at' => Carbon::now(),
            ]);
    }

    /**
     * Get all active pending payment links for a booking
     * 
     * @param string $bookingId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActiveLinksForBooking(string $bookingId)
    {
        return PendingPaymentLink::where('booking_id', $bookingId)
            ->where('expires_at', '>', Carbon::now())
            ->get();
    }

    /**
     * Cleanup expired payment links (run via scheduled task)
     * 
     * @return int Number of deleted records
     */
    public static function cleanupExpiredLinks(): int
    {
        return PendingPaymentLink::where('expires_at', '<', Carbon::now())
            ->where('invalidated_at', null)
            ->delete();
    }
}
