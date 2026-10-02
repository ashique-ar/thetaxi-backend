<?php

namespace App\Models;

use App\Casts\EncryptedStaffPaymentValue;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentMethod extends BaseModel
{
    private const STAFF_SENSITIVE_FIELDS = [
        'account_holder_name',
        'bank_name',
        'bank_branch',
        'account_number',
        'routing_number',
        'wallet_identifier',
        'cheque_payee_name',
        'cheque_bank_name',
    ];

    protected $fillable = [
        'payable_type',
        'payable_id',
        'method_type',
        'label',
        'account_holder_name',
        'bank_name',
        'bank_branch',
        'account_number',
        'routing_number',
        'card_brand',
        'card_last_four',
        'card_expiry_month',
        'card_expiry_year',
        'wallet_provider',
        'wallet_identifier',
        'cheque_payee_name',
        'cheque_bank_name',
        'metadata',
        'is_default',
        'is_active',
        'sensitive_fingerprint',
        'sensitive_encrypted_at',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'card_expiry_month' => 'integer',
        'card_expiry_year' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'sensitive_encrypted_at' => 'datetime',
        'account_holder_name' => EncryptedStaffPaymentValue::class,
        'bank_name' => EncryptedStaffPaymentValue::class,
        'bank_branch' => EncryptedStaffPaymentValue::class,
        'account_number' => EncryptedStaffPaymentValue::class,
        'routing_number' => EncryptedStaffPaymentValue::class,
        'wallet_identifier' => EncryptedStaffPaymentValue::class,
        'cheque_payee_name' => EncryptedStaffPaymentValue::class,
        'cheque_bank_name' => EncryptedStaffPaymentValue::class,
    ];

    protected $hidden = [
        'account_holder_name',
        'bank_name',
        'bank_branch',
        'account_number',
        'routing_number',
        'wallet_identifier',
        'cheque_payee_name',
        'cheque_bank_name',
        'metadata',
        'sensitive_fingerprint',
    ];

    protected static function booted(): void
    {
        static::saving(function (PaymentMethod $method): void {
            if (! $method->isStaffOwned()) {
                return;
            }

            // A morphMany create fills attributes before it supplies payable_type.
            // Reassign here so every new Staff value passes through encryption.
            foreach (self::STAFF_SENSITIVE_FIELDS as $field) {
                $method->setAttribute($field, $method->getAttribute($field));
            }

            $method->sensitive_fingerprint = hash('sha256', collect([
                $method->account_holder_name,
                $method->bank_name,
                $method->bank_branch,
                $method->account_number,
                $method->routing_number,
                $method->wallet_identifier,
                $method->cheque_payee_name,
                $method->cheque_bank_name,
            ])->map(fn ($value) => mb_strtolower(trim((string) $value)))->implode('|'));
            $method->sensitive_encrypted_at = now();
        });
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isStaffOwned(): bool
    {
        return in_array($this->payable_type, ['staff', Staff::class], true);
    }
}
