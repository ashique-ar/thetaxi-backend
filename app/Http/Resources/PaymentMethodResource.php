<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray($request): array
    {
        if ($this->resource->isStaffOwned()) {
            return (new StaffPaymentMethodResource($this->resource))->toArray($request);
        }

        return [
            'id' => $this->id,
            'payable_type' => $this->payable_type,
            'payable_id' => $this->payable_id,
            'method_type' => $this->method_type,
            'label' => $this->label,
            'account_holder_name' => $this->account_holder_name,
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'account_number' => $this->account_number,
            'routing_number' => $this->routing_number,
            'card_brand' => $this->card_brand,
            'card_last_four' => $this->card_last_four,
            'card_expiry_month' => $this->card_expiry_month,
            'card_expiry_year' => $this->card_expiry_year,
            'wallet_provider' => $this->wallet_provider,
            'wallet_identifier' => $this->wallet_identifier,
            'cheque_payee_name' => $this->cheque_payee_name,
            'cheque_bank_name' => $this->cheque_bank_name,
            'metadata' => $this->metadata,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
