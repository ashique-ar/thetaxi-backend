<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentMethod extends BaseModel
{
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
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
