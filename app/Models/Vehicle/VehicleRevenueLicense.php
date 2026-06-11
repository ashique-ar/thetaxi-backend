<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleRevenueLicense extends BaseModel
{
    protected $table = 'vehicle_revenue_licenses';

    protected $fillable = [
        'vehicle_id',
        'license_number',
        'issued_date',
        'expiry_date',
        'renewal_reminder_date',
        'renewal_date',
        'renewed_from_id',
        'authority_name',
        'document_files',
        'status',
        'notes',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'expiry_date' => 'date',
        'renewal_reminder_date' => 'date',
        'renewal_date' => 'date',
        'document_files' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }
}
