<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Booking\Booking;
use App\Services\WebXPayService;
use Illuminate\Support\Facades\Log;

class WebXPayGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly WebXPayService $webXPayService,
    ) {}

    public function getName(): string
    {
        return 'WebXPay';
    }

    public function isEnabled(): bool
    {
        return $this->webXPayService->isEnabled();
    }

    public function initiatePayment(Booking $booking, float $amount, string $currency, array $options = []): array
    {
        if (!$this->isEnabled()) {
            return [
                'success'      => false,
                'payment_url'  => '',
                'transaction_id' => '',
                'message'      => 'WebXPay is not enabled',
            ];
        }

        try {
            $result = $this->webXPayService->createPayment($booking, $amount, $options['payment_type'] ?? 'full');

            return [
                'success'          => $result['success'] ?? false,
                'payment_url'      => $result['payment_url'] ?? '',
                'transaction_id'   => $result['order_id'] ?? '',
                'gateway_data'     => $result,
            ];
        } catch (\Throwable $e) {
            Log::error('WebXPayGateway::initiatePayment failed', ['error' => $e->getMessage()]);

            return [
                'success'        => false,
                'payment_url'    => '',
                'transaction_id' => '',
                'message'        => $e->getMessage(),
            ];
        }
    }

    public function verifyCallback(array $payload): array
    {
        try {
            $result = $this->webXPayService->verifyPayment($payload);

            return [
                'success'        => $result['success'] ?? false,
                'status'         => $result['status'] ?? 'unknown',
                'transaction_id' => $result['order_id'] ?? $payload['order_id'] ?? '',
                'amount'         => (float) ($result['amount'] ?? 0),
                'raw'            => $result,
            ];
        } catch (\Throwable $e) {
            Log::error('WebXPayGateway::verifyCallback failed', ['error' => $e->getMessage()]);

            return [
                'success'        => false,
                'status'         => 'error',
                'transaction_id' => '',
                'amount'         => 0.0,
                'message'        => $e->getMessage(),
            ];
        }
    }

    public function refund(string $gatewayTransactionId, float $amount, string $reason = ''): array
    {
        // WebXPay does not have a documented server-side refund API in the current integration.
        // Flag as manual so the admin can process it through the WebXPay merchant dashboard.
        Log::info('WebXPay refund flagged for manual processing', [
            'transaction_id' => $gatewayTransactionId,
            'amount'         => $amount,
            'reason'         => $reason,
        ]);

        return [
            'success'   => false,
            'refund_id' => null,
            'message'   => 'WebXPay refunds must be processed manually via the merchant dashboard.',
            'manual'    => true,
        ];
    }
}
