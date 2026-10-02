<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StaffPaymentMethodChangeResource extends JsonResource
{
    public function toArray($request): array
    {
        $payload = $this->payload ?? [];

        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'payment_method_id' => $this->payment_method_id,
            'action' => $this->action,
            'reason' => $this->reason,
            'status' => $this->status,
            'proposed' => [
                'method_type' => $payload['method_type'] ?? null,
                'label' => $payload['label'] ?? null,
                'bank_name' => $payload['bank_name'] ?? null,
                'account_number_masked' => $this->lastFour($payload['account_number'] ?? null),
                'card_brand' => $payload['card_brand'] ?? null,
                'card_last_four' => $payload['card_last_four'] ?? null,
                'wallet_provider' => $payload['wallet_provider'] ?? null,
                'wallet_identifier_masked' => $this->lastFour($payload['wallet_identifier'] ?? null),
                'is_default' => $payload['is_default'] ?? null,
                'is_active' => $payload['is_active'] ?? null,
            ],
            'requested_by' => $this->requested_by,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
            'review_notes' => $this->review_notes,
            'applied_at' => $this->applied_at,
            'created_at' => $this->created_at,
        ];
    }

    private function lastFour(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('*', max(strlen($value) - 4, 0)).substr($value, -4);
    }
}
