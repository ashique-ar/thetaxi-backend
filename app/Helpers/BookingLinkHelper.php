<?php

namespace App\Helpers;

use App\Models\Booking\Booking;
use App\Models\Vehicle\VehicleGroup;
use App\Services\PendingPaymentManager;
use App\Services\UrlShortenerService;
use Illuminate\Support\Facades\Crypt;

/**
 * BookingLinkHelper
 * 
 * Generates secure links for payment resumption and quotation conversion
 * Used in email templates to provide one-click checkout for customers
 * Now uses persistent PendingPaymentManager for better cart-independent payment management
 * 
 * Payment links can be shortened using
 * UrlShortenerService to create email-friendly URLs (e.g., /s/abc123 instead of 
 * /checkout/payment-resume/very-long-token). This significantly reduces email length
 * and improves deliverability while maintaining security.
 */
class BookingLinkHelper
{
    /**
     * Generate a payment continuation link for a booking
     * Creates persistent payment link stored in database for reliable email delivery
     * Allows customers to complete payment for pending bookings from email
     * 
     * @param Booking $booking
     * @param int $expiryHours Default: 7 days
     * @return string
     */
    public static function getPaymentLink(Booking $booking, int $expiryHours = 168): string
    {
        try {
            // Use PendingPaymentManager for persistent, cart-independent payment links
            $url = PendingPaymentManager::generatePaymentLinkUrl($booking, $expiryHours);
            if ($url === route('checkout')) {
                \Illuminate\Support\Facades\Log::warning('BookingLinkHelper: Payment link generation returned fallback checkout URL', [
                    'booking_id' => $booking->id ?? null,
                    'booking_code' => $booking->booking_number ?? null,
                ]);
            } else {
            }

            return $url;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('BookingLinkHelper: Error generating payment link', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
            // Fallback: return checkout URL without token
            return route('checkout');
        }
    }

    public static function getQuotationCheckoutLink(\App\Models\Website\PendingPaymentLink $quote): string
    {
        return rtrim(config('app.url'), '/') . route('checkout.quotation-convert', [
            'token' => $quote->token,
        ], absolute: false);
    }

    public static function matchesQuotationSnapshot(Booking $booking, string $hash): bool
    {
        $snapshot = self::quotationSnapshot($booking);
        if (hash_equals($hash, hash('sha256', \App\Support\Foundation\CanonicalJson::encode($snapshot)))) {
            return true;
        }

        // Older links were issued before the refundable deposit was included in the hash.
        foreach ($snapshot['items'] as &$item) {
            unset($item['refundable_deposit_lkr']);
        }

        return hash_equals($hash, hash('sha256', \App\Support\Foundation\CanonicalJson::encode($snapshot)));
    }

    public static function quotationSnapshot(Booking $booking): array
    {
        $booking->loadMissing('bookingItems');
        $money = static fn ($value): string => number_format((float) ($value ?? 0), 2, '.', '');

        return [
            'booking_id' => (string) $booking->id,
            'customer_id' => (string) ($booking->customer_id ?? ''),
            'vehicle_group_id' => (string) ($booking->vehicle_group_id ?? ''),
            'from_date' => (string) ($booking->getRawOriginal('from_date') ?? ''),
            'to_date' => (string) ($booking->getRawOriginal('to_date') ?? ''),
            'currency' => (string) ($booking->currency ?? ''),
            'base_amount' => $money($booking->base_amount),
            'addons_cost' => $money($booking->addons_cost),
            'discount_amount' => $money($booking->discount_amount),
            'tax_amount' => $money($booking->tax_amount),
            'total_estimated' => $money($booking->total_estimated),
            'amount_to_pay' => $money($booking->amount_to_pay),
            'amount_paid' => $money($booking->amount_paid),
            'quotation_options_hash' => hash('sha256', \App\Support\Foundation\CanonicalJson::encode(data_get($booking->workflow_data, 'quotation_options', []))),
            'items' => $booking->bookingItems->sortBy('id')->map(fn ($item) => [
                'id' => (string) $item->id,
                'vehicle_group_id' => (string) ($item->vehicle_group_id ?? ''),
                'service_type_id' => (string) ($item->service_type_id ?? ''),
                'from_date' => (string) ($item->getRawOriginal('from_date') ?? ''),
                'from_time' => (string) ($item->from_time ?? ''),
                'to_date' => (string) ($item->getRawOriginal('to_date') ?? ''),
                'to_time' => (string) ($item->to_time ?? ''),
                'pickup_location' => $item->pickup_location ?? [],
                'dropoff_location' => $item->dropoff_location ?? [],
                'is_self_driven' => (bool) $item->is_self_driven,
                'unit_price' => $money($item->unit_price),
                'total_price' => $money($item->total_price),
                'quantity' => (string) ($item->quantity ?? ''),
                ...(isset($item->metadata['refundable_deposit_lkr']) ? [
                    'refundable_deposit_lkr' => $money($item->metadata['refundable_deposit_lkr']),
                ] : []),
                'pricing_breakdown' => $item->pricing_breakdown ?? [],
            ])->values()->all(),
        ];
    }

    public static function quotationDisplaySnapshot(Booking $booking): array
    {
        $booking->loadMissing(['bookingItems.serviceType', 'bookingItems.vehicleGroup']);
        $snapshot = self::quotationSnapshot($booking);
        $options = data_get($booking->workflow_data, 'quotation_options', []);
        $items = $booking->bookingItems->sortBy('id')->values();
        if (count($options) > 1) {
            $firstOptionItemIds = collect(data_get($options, '0.booking_items', []))
                ->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();
            if ($firstOptionItemIds) {
                $items = collect($firstOptionItemIds)
                    ->map(fn ($id) => $booking->bookingItems->firstWhere('id', $id))
                    ->filter()->values();
            }
        }
        $currency = (string) ($booking->currency ?? 'LKR');
        $seenTripIndexes = [];
        $displayItems = [];
        foreach ($items as $item) {
            $tripIndex = data_get($item->metadata, '_quotation_trip_index');
            if (count($options) > 1 && $tripIndex !== null) {
                $tripIndex = (string) $tripIndex;
                if (isset($seenTripIndexes[$tripIndex])) continue;
                $seenTripIndexes[$tripIndex] = true;
            }

            $displayItem = collect($snapshot['items'])->firstWhere('id', (string) $item->id) ?? [];
            $displayItem['service_name'] = (string) ($item->serviceType?->name ?? '');
            $displayItem['vehicle_group_name'] = (string) ($item->vehicleGroup?->name ?? '');
            $displayItem['route'] = implode(' → ', array_values(array_filter([
                data_get($item->pickup_location, 'address'),
                ...collect(data_get($item->metadata, 'multi_route_stop_order', []))
                    ->sortBy('route_order')->pluck('address')->all(),
                data_get($item->dropoff_location, 'address'),
            ])));
            $displayItem['distance_details'] = data_get($item->metadata, 'distance_details')
                ?? data_get($item->pricing_breakdown, 'base_pricing.distance_details', []);
            $displayItem['service_code'] = (string) ($item->serviceType?->code ?? '');
            $displayItem['pricing_mode'] = $item->serviceType?->pricing_mode;
            $displayItem['duration_days'] = max(1, (int) ($item->duration_days ?? 1));
            $displayItem['included_km_label'] = self::quotationIncludedKmLabel(
                (array) $displayItem['distance_details'],
                isServiceDurationBased($displayItem['service_code'], $displayItem['pricing_mode']),
                $displayItem['duration_days']
            );
            $displayItem['extra_km_rate_label'] = self::quotationExtraKmRateLabel(
                (array) $displayItem['distance_details'],
                $currency,
                $item->vehicle_group_id ? (string) $item->vehicle_group_id : null,
                $item->service_type_id ? (string) $item->service_type_id : null
            );
            $displayItems[] = $displayItem;
        }
        $snapshot['items'] = $displayItems;

        return $snapshot;
    }

    public static function quotationTripOptionGroups(array $options, Booking $booking, string $currency): array
    {
        $trips = [];
        $items = $booking->bookingItems()->with('serviceType')->get()
            ->sortBy(fn ($item) => (int) data_get($item->metadata, '_quotation_trip_index', 0))->values();
        $groupIds = collect($options)->flatMap(fn ($option) => $option['vehicle_group_ids'] ?? [])->unique();
        $vehicleGroups = VehicleGroup::query()->whereIn('id', $groupIds)->get(['id', 'thumbnail', 'refundable_deposit'])->keyBy('id');
        $currencyService = app(\App\Services\CurrencyService::class);
        foreach ($options as $option) {
            foreach (($option['trip_choices'] ?? []) as $index => $tripChoice) {
                $tripNumber = (int) ($tripChoice['trip_number'] ?? $index + 1);
                $groupId = (string) ($option['vehicle_group_ids'][$index] ?? '');
                $itemId = (string) ($option['booking_items'][$index]['id'] ?? '');
                $price = data_get($option, "pricing.items.{$itemId}.summary.total")
                    ?? data_get($option, "pricing.items.{$itemId}.total_amount")
                    ?? 0;
                $trips[$tripNumber] ??= [];
                $key = $groupId !== '' ? $groupId : (string) ($tripChoice['vehicle_group_name'] ?? '');
                $item = $items->firstWhere('id', $itemId) ?? $items->get($tripNumber - 1);
                $distanceDetails = (array) (
                    data_get($option, "pricing.items.{$itemId}.distance_details")
                    ?? data_get($option, "pricing.items.{$itemId}.base_pricing.distance_details")
                    ?? data_get($option, "booking_items.{$index}.metadata.distance_details")
                    ?? data_get($item?->metadata, 'distance_details', [])
                );
                $requiresDeposit = (bool) ($item?->metadata['requires_refundable_deposit']
                    ?? $item?->workflow_config_snapshot['requires_refundable_deposit']
                    ?? $item?->serviceType?->requires_refundable_deposit);
                $depositLkr = $requiresDeposit
                    ? max(0, (float) $vehicleGroups->get($groupId)?->refundable_deposit) * max(1, (int) ($item?->quantity ?? 1))
                    : 0;
                $deposit = $currencyService->convertFromLKR($depositLkr, $currency);
                $trips[$tripNumber][$key] ??= [
                    'vehicle_group_id' => $groupId,
                    'vehicle_group_name' => (string) ($tripChoice['vehicle_group_name'] ?? 'Vehicle option'),
                    'price' => (float) $price,
                    'deposit' => $deposit,
                    'included_km_label' => self::quotationIncludedKmLabel(
                        $distanceDetails,
                        isServiceDurationBased((string) ($item?->serviceType?->code ?? ''), $item?->serviceType?->pricing_mode),
                        max(1, (int) ($item?->duration_days ?? 1))
                    ),
                    'extra_km_rate_label' => self::quotationExtraKmRateLabel(
                        $distanceDetails,
                        $currency,
                        $groupId !== '' ? $groupId : null,
                        $item?->service_type_id ? (string) $item->service_type_id : null
                    ),
                ];
            }
        }

        foreach ($trips as &$choices) {
            foreach ($choices as &$choice) {
                $thumbnail = $vehicleGroups->get($choice['vehicle_group_id'])?->thumbnail;
                $thumbnail = is_array($thumbnail) ? ($thumbnail['path'] ?? $thumbnail['url'] ?? reset($thumbnail)) : $thumbnail;
                $choice['image'] = $thumbnail
                    ? app(\App\Services\EmailImageVariantService::class)->url($thumbnail, 'vehicle')
                    : asset('assets/img/default-vehicle.jpg');
            }
            unset($choice);
        }
        unset($choices);

        ksort($trips);
        return array_map(fn ($choices) => array_values($choices), $trips);
    }

    public static function quotationIncludedKmLabel(array $distanceDetails, bool $isDayPackage, int $durationDays): ?string
    {
        $days = max(1, (int) ($distanceDetails['effective_days'] ?? $durationDays));
        $perDay = (float) ($distanceDetails['free_km_per_day'] ?? 0);
        $perPackage = (float) ($distanceDetails['free_km_per_package'] ?? 0);
        $allowed = (float) ($distanceDetails['allowed_total_km'] ?? 0);

        if ($perDay > 0) {
            if ($isDayPackage) {
                $total = $allowed > 0 ? $allowed : $perDay * $days;
                return number_format($total, 0).' km total included ('.$days.' '.\Illuminate\Support\Str::plural('day', $days).')';
            }

            return number_format($perDay, 0).' km included per day'.($allowed > 0
                ? ' ('.number_format($allowed, 0).' km total for '.$days.' '.\Illuminate\Support\Str::plural('day', $days).')'
                : '');
        }

        if ($perPackage > 0) return number_format($perPackage, 0).' km included per package';
        if ($allowed > 0) return number_format($allowed, 0).' km included';

        return null;
    }

    public static function quotationExtraKmRateLabel(
        array $distanceDetails,
        string $currency,
        ?string $vehicleGroupId = null,
        ?string $serviceTypeId = null
    ): ?string {
        $extraKmPriceLkr = (float) ($distanceDetails['extra_km_price'] ?? 0);
        if ($extraKmPriceLkr <= 0 && $vehicleGroupId) {
            try {
                $rateInfo = app(\App\Services\CartService::class)->getExtraKmRateForVehicleGroup($vehicleGroupId, $serviceTypeId);
                $extraKmPriceLkr = (float) ($rateInfo['rate'] ?? 0);
            } catch (\Throwable) {
                $extraKmPriceLkr = 0;
            }
        }

        if ($extraKmPriceLkr <= 0) {
            return null;
        }

        $currencyCode = strtoupper(trim($currency)) ?: 'LKR';
        $converted = $currencyCode === 'LKR'
            ? $extraKmPriceLkr
            : app(\App\Services\CurrencyService::class)->convertFromLKR($extraKmPriceLkr, $currencyCode);
        $decimals = $currencyCode === 'LKR' ? 0 : 2;

        return number_format(max(0, $converted), $decimals) . ' ' . $currencyCode . '/km';
    }

    public static function quotationOptionSelectionMap(array $options): array
    {
        $selectionMap = [];
        foreach ($options as $option) {
            $groupIds = array_map('strval', $option['vehicle_group_ids'] ?? []);
            if ($groupIds === [] || empty($option['id'])) continue;
            $total = (float) data_get($option, 'totals.total_estimated', 0);
            $selectionMap[implode('|', $groupIds)] = [
                'id' => (string) $option['id'],
                'total' => $total,
            ];
        }

        return $selectionMap;
    }

    /**
     * Encrypt booking data for secure transmission in URLs
     * 
     * @param array $data
     * @return string Base64-encoded encrypted data
     */
    protected static function encryptBookingData(array $data): string
    {
        $json = json_encode($data);
        $encrypted = Crypt::encryptString($json);
        return base64_encode($encrypted);
    }

    /**
     * Decrypt booking data from email link token
     * 
     * @param string $token
     * @return array|null
     */
    public static function decryptBookingData(string $token): ?array
    {
        try {
            $encrypted = base64_decode($token);
            $json = Crypt::decryptString($encrypted);
            return json_decode($json, true);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate a direct checkout URL with pre-filled booking
     * Used when quotation is accepted and customer wants to proceed with payment
     * 
     * @param Booking $quotationBooking
     * @return string
     */
    public static function getQuotationPaymentLink(Booking $quotationBooking): string
    {
        try {
            $encryptedData = self::encryptBookingData([
                'booking_id' => $quotationBooking->id,
                'type' => 'quotation_payment',
                'convert_to_booking' => true
            ]);

            $originalUrl = route('checkout.quotation-payment', [
                'token' => $encryptedData
            ]);

            // Create shortened URL for email-friendly links
            $shortenedUrl = UrlShortenerService::shorten(
                $originalUrl,
                168, // 7 days expiry
                'booking',
                $quotationBooking->id,
                'email', // source
                'quotation_payment', // campaign
                'transactional', // medium
                [ // metadata
                    'booking_number' => $quotationBooking->booking_number ?? null,
                    'quotation_type' => 'payment',
                    'amount' => $quotationBooking->total_estimated ?? null,
                ]
            );

            $shortUrl = UrlShortenerService::getShortUrl($shortenedUrl);


            return $shortUrl;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('BookingLinkHelper: Error generating quotation payment link', [
                'booking_id' => $quotationBooking->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return route('checkout');
        }
    }
}
