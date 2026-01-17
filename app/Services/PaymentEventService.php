<?php

namespace App\Services;

use App\Models\PaymentEvent;
use Illuminate\Support\Facades\Log;

class PaymentEventService
{
    /**
     * Record a payment event for auditability
     *
     * @param string $eventType
     * @param array $data
     *      Possible keys: booking_id, booking_number, source, transaction_id, status, payload, message
     * @return PaymentEvent|null
     */
    public function recordEvent(string $eventType, array $data = []): ?PaymentEvent
    {
        try {
            $payload = $data['payload'] ?? null;
            if (is_array($payload) && empty($payload)) {
                $payload = null;
            }

            $record = PaymentEvent::create([
                'booking_id' => $data['booking_id'] ?? null,
                'booking_number' => $data['booking_number'] ?? null,
                'event_type' => $eventType,
                'source' => $data['source'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'status' => $data['status'] ?? null,
                'payload' => $payload,
                'message' => $data['message'] ?? null,
                'created_user_id' => $data['created_user_id'] ?? null,
            ]);

            Log::info('PaymentEvent recorded', ['id' => $record->id, 'event_type' => $eventType, 'booking_id' => $record->booking_id]);

            return $record;
        } catch (\Exception $e) {
            Log::error('Failed to record PaymentEvent', ['error' => $e->getMessage(), 'data' => $data]);
            return null;
        }
    }
}
