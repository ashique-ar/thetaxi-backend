<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\UUID;

/**
 * Payment Transaction Model
 * 
 * Represents individual payment transactions generated from payment attempts.
 * Each transaction records a specific amount processed through a payment gateway.
 * 
 * @property string $id Primary key (UUID)
 * @property string $attempt_id Foreign key to payment attempts table
 * @property float $amount Transaction amount
 * @property string|null $currency_id Foreign key to currencies table
 * @property string $gateway Payment gateway used (e.g., "stripe", "paypal")
 * @property string|null $transaction_id Gateway-specific transaction identifier
 * @property string $status Transaction status (success, failed)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read Currency|null $currency Currency used for this transaction
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class PaymentTransaction extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'payment_transactions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'attempt_id',
        'amount',
        'currency_id',
        'gateway',
        'transaction_id',
        'status',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];


    /**
     * Get the currency used for this transaction.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }
}
