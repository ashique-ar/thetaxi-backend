<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCommissionPlanAssignment extends BaseModel
{
    protected $fillable = [
        'company_id', 'plan_family_id', 'scope_type', 'sales_profile_id', 'staff_id', 'staff_category',
        'precedence', 'effective_from', 'effective_until', 'status', 'created_by', 'approved_by', 'approved_at',
    ];
    protected $casts = [
        'precedence' => 'integer', 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'approved_at' => 'datetime',
    ];

    public function planFamily(): BelongsTo { return $this->belongsTo(SalesCommissionPlanFamily::class, 'plan_family_id'); }
}
