<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Booking\Booking;
use App\Models\Website\WebsiteSetting;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\Common\AsymmetricKey;

class WebXPayService
{
    protected string $secretKey;
    protected string $publicKey;
    protected string $apiUrl;
    protected string $checkoutUrl;
    protected string $currency;
    protected bool $enabled;
    protected ?string $apiUsername;
    protected ?string $apiPassword;
    protected ?string $returnUrl;
    protected ?string $cancelUrl;
    protected ?string $notifyUrl;
    protected ?string $jwtToken = null;

    public function __construct()
    {
        $this->secretKey = $this->getSettingValue('webxpay_merchant_secret', config('booking.webxpay.merchant_secret'));
        $this->publicKey = $this->getSettingValue('webxpay_public_key', config('booking.webxpay.public_key'));
        $this->apiUrl = $this->getSettingValue('webxpay_api_url', config('booking.webxpay.api_url'));
        $this->checkoutUrl = $this->getSettingValue('webxpay_checkout_url', config('booking.webxpay.checkout_url'));
        $this->currency = $this->getSettingValue('webxpay_currency', config('booking.webxpay.currency', 'LKR'));
        $settingEnabled = $this->getSettingValue('webxpay_enabled', null);
        $this->enabled = $this->normalizeBoolean($settingEnabled, (bool) config('booking.webxpay.enabled', false));
        $this->apiUsername = $this->getSettingValue('webxpay_api_username', config('booking.webxpay.api_username'));
        $this->apiPassword = $this->getSettingValue('webxpay_api_password', config('booking.webxpay.api_password'));
        $this->returnUrl = $this->getSettingValue('webxpay_return_url', config('booking.webxpay.return_url'));
        $this->cancelUrl = $this->getSettingValue('webxpay_cancel_url', config('booking.webxpay.cancel_url'));
        $this->notifyUrl = $this->getSettingValue('webxpay_notify_url', config('booking.webxpay.notify_url'));
    }

    /**
     * Check if WebXPay is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->secretKey) && !empty($this->publicKey);
    }

    /**
     * Authenticate with WebXPay API and get JWT token
     */
    protected function authenticate(): ?string
    {
        if ($this->jwtToken) {
            return $this->jwtToken;
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->apiUrl . '/auth', [
                    'username' => $this->apiUsername,
                    'password' => $this->apiPassword,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->jwtToken = $data['token'] ?? null;
                return $this->jwtToken;
            }

            Log::error('WebXPay authentication failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WebXPay authentication exception', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create payment request using RSA encryption (WebXPay Redirect Method)
     * Based on official WebXPay redirect-sample-code
     */
    public function createPayment(Booking $booking, float $amount, string $paymentType = 'full'): array
    {
        if (!$this->isEnabled()) {
            throw new \Exception('WebXPay is not enabled or configured properly');
        }

        try {
            $orderId = $booking->booking_number . '-' . time();
            
            // Step 1: Create plaintext payment data
            // Format: unique_order_id|total_amount
            // WebXPay expects amount as decimal with 2 decimal places (e.g., 240696.51)
            $amountFormatted = number_format($amount, 2, '.', '');
            $plaintext = $orderId . '|' . $amountFormatted;
            
            // Step 2: Encrypt with RSA public key
            $encryptedPayment = $this->encryptWithPublicKey($plaintext);
            
            if (!$encryptedPayment) {
                throw new \Exception('Failed to encrypt payment data');
            }
            
            // Step 3: Prepare customer details
            $customerData = [
                'first_name' => $booking->customer?->user?->first_name ?? explode(' ', $booking->customer?->user?->full_name ?? 'Customer')[0],
                'last_name' => $booking->customer?->user?->last_name ?? explode(' ', $booking->customer?->user?->full_name ?? 'Customer')[1] ?? '',
                'email' => $booking->customer?->user?->email ?? 'customer@example.com',
                'contact_number' => $this->formatPhoneNumber($booking->customer?->user?->phone ?? '0000000000'),
                'address_line_one' => $booking->customer->address ?? $booking->customer?->user?->address ?? '',
                'address_line_two' => '',
                'city' => $booking->customer->city ?? $booking->customer?->user?->city ?? '',
                'state' => 'Western',
                'postal_code' => '10000',
                'country' => 'Sri Lanka',
                'process_currency' => $this->currency,
                'cms' => 'Laravel',
            ];
            
            // Step 4: Prepare custom fields (booking_id|payment_type|booking_number|customer_id)
            $customFields = implode('|', [
                $booking->id,
                $paymentType,
                $booking->booking_number,
                $booking->customer->id ?? 'guest'
            ]);
            $encryptedCustomFields = base64_encode($customFields);
            
            Log::info('WebXPay payment initiated (RSA Redirect)', [
                'booking_id' => $booking->id,
                'order_id' => $orderId,
                'amount_original' => $amount,
                'amount_test_fixed' => $amountFormatted,
                'plaintext' => $plaintext,
                'note' => 'Testing with fixed amount 100 to match WebXPay samples'
            ]);

            Log::debug('WebXPay customer data', $customerData);
            Log::debug('WebXPay custom fields', [
                'custom_fields_plaintext' => $customFields,
                'custom_fields_encrypted' => $encryptedCustomFields,
                'encryptedPayment'=> $encryptedPayment
            ]); 
            // Step 5: Return all data for form submission
            return [
                'success' => true,
                'payment_url' => $this->checkoutUrl, // e.g., https://webxpay.com/index.php?route=checkout/billing
                'order_id' => $orderId,
                'encrypted_payment' => $encryptedPayment,
                'secret_key' => $this->secretKey,
                'custom_fields' => $encryptedCustomFields,
                'enc_method' => 'JCs3J+6oSz4V0LgE0zi/Bg==', // Encryption method indicator (from WebXPay sample)
                'customer_data' => $customerData,
                'return_url' => $this->returnUrl,
                'cancel_url' => $this->cancelUrl,
                'notify_url' => $this->notifyUrl,
                'method' => 'rsa_redirect' // Indicates RSA form redirect
            ];

        } catch (\Exception $e) {
            Log::error('WebXPay payment creation exception', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Payment gateway error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Encrypt data using WebXPay public key (RSA PKCS1 padding - OpenSSL compatible)
     */
    protected function encryptWithPublicKey(string $plaintext): ?string
    {
        try {
            // Use openssl_public_encrypt for compatibility with WebXPay sample code
            $success = openssl_public_encrypt($plaintext, $encrypted, $this->publicKey);
            
            if (!$success) {
                throw new \Exception('OpenSSL encryption failed: ' . openssl_error_string());
            }
            
            // Base64 encode for transmission
            return base64_encode($encrypted);
            
        } catch (\Exception $e) {
            Log::error('RSA encryption failed', [
                'error' => $e->getMessage(),
                'public_key_length' => strlen($this->publicKey)
            ]);
            return null;
        }
    }

    /**
     * Normalize a boolean value coming from settings.
     */
    protected function normalizeBoolean($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    protected function getSettingValue(string $key, $default = null)
    {
        $value = WebsiteSetting::getValue($key, null);
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $default;
    }

    /**
     * Verify payment callback using RSA signature
     * Based on official WebXPay response.php sample
     */
    public function verifyPayment(array $callbackData): array
    {
        try {
            // Step 1: Get the encrypted payment response and signature
            $encryptedPayment = $callbackData['payment'] ?? null;
            $encryptedSignature = $callbackData['signature'] ?? null;
            $encryptedCustomFields = $callbackData['custom_fields'] ?? null;

            if (!$encryptedPayment || !$encryptedSignature) {
                return [
                    'success' => false,
                    'message' => 'Missing payment or signature data'
                ];
            }

            // Step 2: Base64 decode
            $payment = base64_decode($encryptedPayment);
            $signature = base64_decode($encryptedSignature);
            $customFields = $encryptedCustomFields ? base64_decode($encryptedCustomFields) : '';

            // Step 3: Decrypt signature with public key
            $decryptedSignature = $this->decryptWithPublicKey($signature);

            // Step 4: Verify signature matches payment data
            if ($decryptedSignature !== $payment) {
                Log::warning('WebXPay signature verification failed', [
                    'expected' => substr($payment, 0, 100),
                    'got' => substr($decryptedSignature, 0, 100)
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Invalid payment signature'
                ];
            }

            // Step 5: Parse payment response
            // Format: order_id|order_reference_number|date_time_transaction|payment_gateway_used|status_code|comment
            $responseData = explode('|', $payment);
            
            if (count($responseData) < 5) {
                return [
                    'success' => false,
                    'message' => 'Invalid payment response format'
                ];
            }

            // Step 6: Parse custom fields
            // Format: booking_id|payment_type|booking_number|customer_id
            $customData = $customFields ? explode('|', $customFields) : [];

            // Step 7: Extract payment details
            $orderId = $responseData[0] ?? null;
            $referenceNumber = $responseData[1] ?? null;
            $transactionDateTime = $responseData[2] ?? null;
            $paymentGateway = $responseData[3] ?? null;
            $statusCode = $responseData[4] ?? null;
            $comment = $responseData[5] ?? '';

            Log::info('WebXPay payment verified', [
                'order_id' => $orderId,
                'reference_number' => $referenceNumber,
                'status_code' => $statusCode,
                'payment_gateway' => $paymentGateway,
                'custom_fields' => $customData
            ]);

            // Status code 2 = success in WebXPay
            $isSuccessful = ($statusCode == '2');

            return [
                'success' => $isSuccessful,
                'order_id' => $orderId,
                'transaction_id' => $referenceNumber,
                'status' => $isSuccessful ? 'success' : 'failed',
                'status_code' => $statusCode,
                'payment_gateway' => $paymentGateway,
                'transaction_date' => $transactionDateTime,
                'comment' => $comment,
                'booking_id' => $customData[0] ?? null,
                'payment_type' => $customData[1] ?? null,
                'booking_number' => $customData[2] ?? null,
                'customer_id' => $customData[3] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('WebXPay verification exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Payment verification error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Decrypt signature using WebXPay public key (OpenSSL compatible)
     */
    protected function decryptWithPublicKey(string $encrypted): ?string
    {
        try {
            // Use openssl_public_decrypt for compatibility with WebXPay sample code
            $success = openssl_public_decrypt($encrypted, $decrypted, $this->publicKey);
            
            if (!$success) {
                throw new \Exception('OpenSSL decryption failed: ' . openssl_error_string());
            }
            
            return $decrypted;
            
        } catch (\Exception $e) {
            Log::error('RSA decryption failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Format phone number for WebXPay
     * Removes + sign and country code prefix, keeps only digits
     * e.g., +94772090741 becomes 0772090741
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove + sign if present
        $phone = str_replace('+', '', $phone);
        
        // If starts with country code (94 for Sri Lanka), replace with 0
        if (str_starts_with($phone, '94')) {
            $phone = '0' . substr($phone, 2);
        }
        
        // Keep only digits
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        return $phone ?: '0000000000';
    }
}

