<?php

namespace App\Services\Sms;

use App\Services\WebsiteSettingsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class SmsSettingsService
{
    public function __construct(
        private WebsiteSettingsService $websiteSettingsService
    ) {}

    public function getSettings(): array
    {
        $settings = $this->websiteSettingsService->getSmsSettings();

        return [
            'enabled' => $this->toBool($settings['sms_enabled'] ?? true, true),
            'provider' => ($settings['sms_provider'] ?? null) ?: 'esms',
            'default_sender_mask' => $settings['sms_default_sender_mask'] ?? null,
            'allow_mask_override' => $this->toBool(
                $settings['sms_allow_mask_override'] ?? true,
                true
            ),
            'queue_enabled' => $this->toBool(
                $settings['sms_queue_enabled'] ?? true,
                true
            ),
            'dry_run' => $this->toBool(
                $settings['sms_dry_run'] ?? false,
                false
            ),
            'bulk_chunk_size' => max(1, (int) ($settings['sms_bulk_chunk_size'] ?? 250)),
            'cost_per_segment' => max(0, (float) ($settings['sms_cost_per_segment'] ?? 0)),
            'cost_currency' => strtoupper(substr((string) ($settings['sms_cost_currency'] ?? 'LKR'), 0, 3)),
            'webhook_secret' => $settings['sms_webhook_secret'] ?? null,
            'booking_status_enabled' => $this->toBool(
                $settings['sms_booking_status_enabled'] ?? false,
                false
            ),
            'booking_confirmation_enabled' => $this->toBool(
                $settings['sms_booking_confirmation_enabled'] ?? false,
                false
            ),
            'quotation_requested_enabled' => $this->toBool(
                $settings['sms_quotation_requested_enabled'] ?? false,
                false
            ),
            'inquiry_received_enabled' => $this->toBool(
                $settings['sms_inquiry_received_enabled'] ?? false,
                false
            ),
            'driver_dispatched_enabled' => $this->toBool(
                $settings['sms_driver_dispatched_enabled'] ?? false,
                false
            ),
            'driver_arrived_enabled' => $this->toBool(
                $settings['sms_driver_arrived_enabled'] ?? false,
                false
            ),
            'trip_completion_enabled' => $this->toBool(
                $settings['sms_trip_completion_enabled'] ?? false,
                false
            ),
            'payment_confirmation_enabled' => $this->toBool(
                $settings['sms_payment_confirmation_enabled'] ?? false,
                false
            ),
            'trip_completion_scope' => in_array(($settings['sms_trip_completion_scope'] ?? 'booking'), ['booking', 'item'], true)
                ? ($settings['sms_trip_completion_scope'] ?? 'booking')
                : 'booking',
            'driver_assignment_fallback_enabled' => $this->toBool(
                $settings['sms_driver_assignment_fallback_enabled'] ?? false,
                false
            ),
            'driver_assignment_fallback_timeout_minutes' => max(1, min(120, (int) (
                $settings['sms_driver_assignment_fallback_timeout_minutes'] ?? 10
            ))),
            'admin_booking_summary_enabled' => $this->toBool(
                $settings['sms_admin_booking_summary_enabled'] ?? false,
                false
            ),
            'admin_booking_summary_numbers' => $this->adminNumbers(
                $settings['sms_admin_booking_summary_numbers'] ?? []
            ),
            'booking_confirmation_template' => trim((string) (
                $settings['sms_booking_confirmation_template']
                    ?? 'Your booking with TheTaxi is confirmed. Booking #: {booking_number}. Pickup date: {pickup_date}. Pickup time: {pickup_time}. For assistance: 011 286 1111.'
            )),
            'quotation_requested_template' => trim((string) (
                $settings['sms_quotation_requested_template']
                    ?? 'Thank you for requesting a quotation from TheTaxi. Reference: {booking_number}. Our team will contact you shortly. Assistance: 011 286 1111.'
            )),
            'inquiry_received_template' => trim((string) (
                $settings['sms_inquiry_received_template']
                    ?? 'Thank you for contacting TheTaxi. Inquiry reference: {inquiry_number}. We have received your inquiry and will contact you shortly. Assistance: 011 286 1111.'
            )),
            'driver_dispatched_template' => trim((string) (
                $settings['sms_driver_dispatched_template']
                    ?? "Your Taxi is on the way. Booking #: {booking_number}. Pickup: {pickup_date} {pickup_time}. Driver: {driver_name}. Mobile: {driver_mobile}. Vehicle: {vehicle_description}. Vehicle No: {vehicle_number}. TheTaxi: 011 286 1111."
            )),
            'driver_arrived_template' => trim((string) (
                $settings['sms_driver_arrived_template']
                    ?? 'Your Taxi has arrived at the pickup location. Booking #: {booking_number}. Scheduled pickup: {pickup_date} {pickup_time}. Driver: {driver_name}. Vehicle: {vehicle_number}. Mobile: {driver_mobile}.'
            )),
            'trip_completion_template' => trim((string) (
                $settings['sms_trip_completion_template']
                    ?? "Thank you for travelling with TheTaxi.\n\nBooking #: {booking_number}\nYour trip has been completed.\n\nWe hope you had a pleasant journey.\n\nwww.thetaxi.lk"
            )),
            'payment_confirmation_template' => trim((string) (
                $settings['sms_payment_confirmation_template']
                    ?? "Payment received for your TheTaxi booking.\n\nBooking #: {booking_number}\nAmount: {currency} {amount}\nReference: {payment_reference}\n\nThank you."
            )),
            'driver_assignment_fallback_template' => trim((string) (
                $settings['sms_driver_assignment_fallback_template']
                    ?? "New booking assigned.\n\nBooking #: {booking_number}\nPickup date: {pickup_date}\nPickup time: {pickup_time}\nCustomer: {customer_name}\n\nPlease check TheTaxi Driver App."
            )),
            'admin_booking_summary_template' => trim((string) (
                $settings['sms_admin_booking_summary_template']
                    ?? "NEW BOOKING CONFIRMED\nBooking: #{booking_number}\nCustomer: {customer_name}\nMobile: {customer_mobile}\nPickup date: {pickup_date}\nPickup time: {pickup_time}\nFrom: {origin}\nTo: {destination}\nItems/Trips: {item_count}\nTotal: {currency} {total}"
            )),
            'providers' => [
                'esms' => [
                    'base_url' => rtrim(
                        ($settings['sms_esms_base_url'] ?? null) ?: 'https://e-sms.dialog.lk/api',
                        '/'
                    ),
                    'username' => $settings['sms_esms_username'] ?? null,
                    'password' => $settings['sms_esms_password'] ?? null,
                    'api_key' => $settings['sms_esms_api_key'] ?? null,
                    'esmsqk' => $settings['sms_esms_esmsqk'] ?? null,
                    'default_sender_mask' => $settings['sms_default_sender_mask'] ?? null,
                    'delivery_callback_url' => $settings['sms_esms_delivery_callback_url'] ?? null,
                ],
            ],
        ];
    }

    public function getActiveProvider(): string
    {
        return $this->getSettings()['provider'];
    }

    public function getProviderConfig(string $provider): array
    {
        return $this->getSettings()['providers'][$provider] ?? [];
    }

    public function isEnabled(): bool
    {
        return $this->getSettings()['enabled'];
    }

    public function protectAdminNumbers(array $numbers): string
    {
        return 'enc:v1:' . Crypt::encryptString(json_encode($this->adminNumbers($numbers), JSON_THROW_ON_ERROR));
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    private function adminNumbers(mixed $value): array
    {
        if (is_string($value) && str_starts_with($value, 'enc:v1:')) {
            try {
                $value = Crypt::decryptString(substr($value, 7));
            } catch (DecryptException) {
                return [];
            }
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,\r\n]+/', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_slice(array_unique(array_filter(array_map(
            static function (mixed $number): string {
                $digits = preg_replace('/\D+/', '', (string) $number);
                if (str_starts_with($digits, '0')) {
                    return '94' . substr($digits, 1);
                }
                if (str_starts_with($digits, '7') && strlen($digits) === 9) {
                    return '94' . $digits;
                }
                return $digits;
            },
            $value
        ))), 0, 2));
    }
}
