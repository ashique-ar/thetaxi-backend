<?php

namespace App\Models;

class LoyaltyReward extends BaseModel
{
    protected $table = 'loyalty_rewards';

    protected $fillable = [
        'name',
        'description',
        'points_required',
        'value',
        'category',
        'stock_quantity',
        'redemptions_count',
        'is_active',
        'starts_at',
        'ends_at',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'points_required' => 'integer',
        'value' => 'decimal:2',
        'stock_quantity' => 'integer',
        'redemptions_count' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
