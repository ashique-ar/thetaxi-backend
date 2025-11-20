<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Booking\Booking;

class WebXPayService
{
    protected string $merchantId;
    protected string $merchantSecret;
    protected string $apiUrl;
    protected string $currency;
    protected bool $enabled;

    public function __construct()
    {
        $this->merchantId = config('booking.webxpay.merchant_id');
        $this->merchantSecret = config('booking.webxpay.merchant_secret');
        $this->apiUrl = config('booking.webxpay.api_url');
        $this->currency = config('booking.webxpay.currency', 'LKR');
        $this->enabled = config('booking.webxpay.enabled', false);
    }

    /**
     * Check if WebXPay is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->merchantId) && !empty($this->merchantSecret);
    }

    /**
     * Create payment request
     */
    public function createPayment(Booking $booking, float $amount, string $paymentType = 'full'): array
    {
        if (!$this->isEnabled()) {
            throw new \Exception('WebXPay is not enabled or configured properly');
        }

        try {
            $orderId = $booking->booking_number . '-' . time();
            
            $paymentData = [
                'merchant_id' => $this->merchantId,
                'order_id' => $orderId,
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $this->currency,
                'customer_name' => $booking->customer->full_name ?? 'Customer',
                'customer_email' => $booking->customer->email ?? '',
                'customer_phone' => $booking->customer->phone ?? '',
                'description' => "Booking #{$booking->booking_number} - {$paymentType} payment",
                'return_url' => config('booking.webxpay.return_url') ?: route('checkout.webxpay.callback'),
                'cancel_url' => config('booking.webxpay.cancel_url') ?: route('checkout.webxpay.cancel'),
                'notify_url' => config('booking.webxpay.notify_url') ?: route('checkout.webxpay.notify'),
                'custom_1' => $booking->id,
                'custom_2' => $paymentType,
            ];

            // Generate hash for security
            $paymentData['hash'] = $this->generateHash($paymentData);

            // Make API request to WebXPay
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->apiUrl . '/payment/initiate', $paymentData);

            if ($response->successful()) {
                $result = $response->json();
                
                Log::info('WebXPay payment created', [
                    'booking_id' => $booking->id,
                    'order_id' => $orderId,
                    'amount' => $amount
                ]);

                return [
                    'success' => true,
                    'payment_url' => $result['payment_url'] ?? null,
                    'order_id' => $orderId,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ];
            } else {
                Log::error('WebXPay payment creation failed', [
                    'booking_id' => $booking->id,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to create payment request',
                    'error' => $response->json()['message'] ?? 'Unknown error'
                ];
            }
        } catch (\Exception $e) {
            Log::error('WebXPay exception', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Payment gateway error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verify payment callback
     */
    public function verifyPayment(array $callbackData): array
    {
        try {
            // Verify hash
            if (!$this->verifyHash($callbackData)) {
                return [
                    'success' => false,
                    'message' => 'Invalid payment hash'
                ];
            }

            $orderId = $callbackData['order_id'] ?? null;
            $transactionId = $callbackData['transaction_id'] ?? null;
            $status = $callbackData['status'] ?? null;

            if (!$orderId || !$transactionId) {
                return [
                    'success' => false,
                    'message' => 'Missing required callback data'
                ];
            }

            // Query payment status from WebXPay
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->merchantSecret,
                ])
                ->get($this->apiUrl . '/payment/status', [
                    'merchant_id' => $this->merchantId,
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                ]);

            if ($response->successful()) {
                $result = $response->json();
                
                return [
                    'success' => true,
                    'status' => $result['status'] ?? $status,
                    'transaction_id' => $transactionId,
                    'order_id' => $orderId,
                    'amount' => $result['amount'] ?? $callbackData['amount'] ?? 0,
                    'payment_method' => $result['payment_method'] ?? 'online',
                    'paid_at' => $result['paid_at'] ?? now(),
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to verify payment status'
            ];
        } catch (\Exception $e) {
            Log::error('WebXPay verification exception', [
                'error' => $e->getMessage(),
                'callback_data' => $callbackData
            ]);

            return [
                'success' => false,
                'message' => 'Payment verification error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate hash for payment data
     */
    protected function generateHash(array $data): string
    {
        $hashString = $this->merchantId 
            . $data['order_id'] 
            . $data['amount'] 
            . $data['currency'] 
            . $this->merchantSecret;
        
        return strtoupper(md5($hashString));
    }

    /**
     * Verify callback hash
     */
    protected function verifyHash(array $data): bool
    {
        $receivedHash = $data['hash'] ?? '';
        
        $hashString = $this->merchantId 
            . ($data['order_id'] ?? '') 
            . ($data['amount'] ?? '') 
            . ($data['currency'] ?? $this->currency) 
            . $this->merchantSecret;
        
        $calculatedHash = strtoupper(md5($hashString));
        
        return hash_equals($calculatedHash, $receivedHash);
    }

    /**
     * Refund payment
     */
    public function refundPayment(string $transactionId, float $amount, string $reason = ''): array
    {
        if (!$this->isEnabled()) {
            throw new \Exception('WebXPay is not enabled or configured properly');
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->merchantSecret,
                ])
                ->post($this->apiUrl . '/payment/refund', [
                    'merchant_id' => $this->merchantId,
                    'transaction_id' => $transactionId,
                    'amount' => number_format($amount, 2, '.', ''),
                    'reason' => $reason,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'refund_id' => $response->json()['refund_id'] ?? null,
                ];
            }

            return [
                'success' => false,
                'message' => 'Refund failed',
                'error' => $response->json()['message'] ?? 'Unknown error'
            ];
        } catch (\Exception $e) {
            Log::error('WebXPay refund exception', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Refund processing error',
                'error' => $e->getMessage()
            ];
        }
    }
}
