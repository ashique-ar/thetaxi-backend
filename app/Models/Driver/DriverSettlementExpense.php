<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverSettlementExpense extends BaseModel
{
    protected $fillable = [
        'driver_hire_settlement_id',
        'expense_type',
        'claimed_amount',
        'approved_amount',
        'currency',
        'vendor',
        'description',
        'receipt_files',
        'expense_date',
        'status',
        'review_reason',
        'reviewed_by',
        'reviewed_at',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'claimed_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'receipt_files' => 'array',
        'expense_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DriverHireSettlement::class, 'driver_hire_settlement_id');
    }
}
