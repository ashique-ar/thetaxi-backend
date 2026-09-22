<?php

namespace App\Helpers;

use App\Models\Booking\Booking;
use App\Services\PendingPaymentManager;
use App\Services\UrlShortenerService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * BookingLinkHelper
 * 
 * Generates secure links for payment resumption and quotation conversion
 * Used in email templates to provide one-click checkout for customers
 * Now uses persistent PendingPaymentManager for better cart-independent payment management
 * 
 * URL Shortening: All payment and quotation links are automatically shortened using
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

    /**
     * Generate a quotation conversion link for a booking
     * When customer accepts quotation, clicking this link creates a new booking
     * with same vehicle group, dates, and discounts applied
     * 
     * @param Booking $booking
     * @return string
     */
    public static function getQuotationCheckoutLink(Booking $booking): string
    {
        try {
            // Encrypt booking ID and convert-from-quotation flag
            $encryptedData = self::encryptBookingData([
                'booking_id' => $booking->id,
                'type' => 'quotation_conversion',
                'vehicle_group_id' => $booking->vehicle_group_id,
                'from_date' => $booking->from_date?->toDateString(),
                'to_date' => $booking->to_date?->toDateString(),
            ]);

            $originalUrl = route('checkout.quotation-convert', [
                'token' => $encryptedData
            ]);

            // Create shortened URL for email-friendly links
            $shortenedUrl = UrlShortenerService::shorten(
                $originalUrl,
                168, // 7 days expiry
                'booking',
                $booking->id,
                'email', // source
                'quotation_conversion', // campaign
                'transactional', // medium
                [ // metadata
                    'booking_number' => $booking->booking_number ?? null,
                    'vehicle_group_id' => $booking->vehicle_group_id,
                    'quotation_type' => 'conversion',
                ]
            );

            $shortUrl = UrlShortenerService::getShortUrl($shortenedUrl);


            return $shortUrl;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('BookingLinkHelper: Error generating quotation checkout link', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
            // Fallback: return checkout URL without token
            return route('checkout');
        }
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
