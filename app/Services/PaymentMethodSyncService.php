<?php

namespace App\Services;

use App\Models\Driver\Driver;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class PaymentMethodSyncService
{
    public function syncMany(Model $payable, array $methods, string $actorUserId): void
    {
        $seenIds = [];

        foreach ($methods as $method) {
            $payload = $this->cleanPayload($method);
            $payload['updated_user_id'] = $actorUserId;

            if (!empty($payload['id'])) {
                $paymentMethod = $payable->paymentMethods()->whereKey($payload['id'])->firstOrFail();
                unset($payload['id']);
                $paymentMethod->update($payload);
                $seenIds[] = $paymentMethod->id;
            } else {
                unset($payload['id']);
                $paymentMethod = $payable->paymentMethods()->create($payload + ['created_user_id' => $actorUserId]);
                $seenIds[] = $paymentMethod->id;
            }

            if ($paymentMethod->is_default) {
                $this->clearOtherDefaults($paymentMethod);
            }
        }

        $payable->paymentMethods()->whereNotIn('id', $seenIds)->update(['is_active' => false]);
    }

    public function syncOne(Driver $driver, ?array $method, string $actorUserId): void
    {
        if (!$method) {
            return;
        }

        $payload = $this->cleanPayload($method);
        $payload['is_default'] = true;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $existing = $driver->paymentMethod()->first();

        if ($existing) {
            unset($payload['id']);
            $existing->update($payload + ['updated_user_id' => $actorUserId]);
            return;
        }

        if (!empty($payload['id'])) {
            throw ValidationException::withMessages(['payment_method.id' => ['The selected payment method does not belong to this driver.']]);
        }

        $driver->paymentMethod()->create($payload + ['created_user_id' => $actorUserId]);
    }

    private function cleanPayload(array $method): array
    {
        return [
            'id' => $method['id'] ?? null,
            'method_type' => $method['method_type'] ?? 'bank_transfer',
            'label' => $method['label'] ?? null,
            'account_holder_name' => $method['account_holder_name'] ?? null,
            'bank_name' => $method['bank_name'] ?? null,
            'bank_branch' => $method['bank_branch'] ?? null,
            'account_number' => $method['account_number'] ?? null,
            'routing_number' => $method['routing_number'] ?? null,
            'card_brand' => $method['card_brand'] ?? null,
            'card_last_four' => $method['card_last_four'] ?? null,
            'card_expiry_month' => $method['card_expiry_month'] ?? null,
            'card_expiry_year' => $method['card_expiry_year'] ?? null,
            'wallet_provider' => $method['wallet_provider'] ?? null,
            'wallet_identifier' => $method['wallet_identifier'] ?? null,
            'cheque_payee_name' => $method['cheque_payee_name'] ?? null,
            'cheque_bank_name' => $method['cheque_bank_name'] ?? null,
            'metadata' => $method['metadata'] ?? null,
            'is_default' => $method['is_default'] ?? false,
            'is_active' => $method['is_active'] ?? true,
        ];
    }

    private function clearOtherDefaults(PaymentMethod $method): void
    {
        PaymentMethod::where('payable_type', $method->payable_type)
            ->where('payable_id', $method->payable_id)
            ->where('id', '!=', $method->id)
            ->update(['is_default' => false]);
    }
}
