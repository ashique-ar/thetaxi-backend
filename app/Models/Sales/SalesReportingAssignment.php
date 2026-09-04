<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReportingAssignment extends BaseModel
{
    protected $fillable = [
        'company_id', 'manager_sales_profile_id', 'member_sales_profile_id', 'team_code',
        'effective_from', 'effective_until', 'created_user_id', 'updated_user_id',
    ];

    protected $casts = ['effective_from' => 'datetime', 'effective_until' => 'datetime'];

    public function manager(): BelongsTo { return $this->belongsTo(SalesProfile::class, 'manager_sales_profile_id')->withTrashed(); }
    public function member(): BelongsTo { return $this->belongsTo(SalesProfile::class, 'member_sales_profile_id')->withTrashed(); }
}
