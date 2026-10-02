<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class StaffPaymentMethodResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->payable_id,
            'method_type' => $this->method_type,
            'label' => $this->label,
            'account_holder_name_masked' => $this->maskWords($this->account_holder_name),
            'bank_name' => $this->bank_name,
            'bank_branch_masked' => $this->mask($this->bank_branch, 2, 1),
            'account_number_masked' => $this->mask($this->account_number, 0, 4),
            'routing_number_masked' => $this->mask($this->routing_number, 0, 3),
            'card_brand' => $this->card_brand,
            'card_last_four' => $this->card_last_four,
            'wallet_provider' => $this->wallet_provider,
            'wallet_identifier_masked' => $this->mask($this->wallet_identifier, 1, 3),
            'cheque_payee_name_masked' => $this->maskWords($this->cheque_payee_name),
            'cheque_bank_name' => $this->cheque_bank_name,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at,
        ];
    }

    private function mask(?string $value, int $visibleStart, int $visibleEnd): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = Str::length($value);
        if ($length <= $visibleStart + $visibleEnd) {
            return str_repeat('*', $length);
        }

        return Str::substr($value, 0, $visibleStart)
            .str_repeat('*', $length - $visibleStart - $visibleEnd)
            .($visibleEnd > 0 ? Str::substr($value, -$visibleEnd) : '');
    }

    private function maskWords(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return collect(preg_split('/\s+/', trim($value)))
            ->map(fn (string $word) => $this->mask($word, 1, 0))
            ->implode(' ');
    }
}
