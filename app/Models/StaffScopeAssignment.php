<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffScopeAssignment extends BaseModel
{
    protected $fillable = [
        'company_id',
        'manager_staff_id',
        'member_staff_id',
        'effective_from',
        'effective_until',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'manager_staff_id')->withTrashed();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'member_staff_id')->withTrashed();
    }
}
