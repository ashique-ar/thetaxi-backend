<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleInsuranceClaim extends BaseModel
{
    protected $fillable = [
        'vehicle_insurance_id', 'claim_number', 'incident_date', 'filed_date', 'status',
        'claimed_amount', 'approved_amount', 'description', 'resolution_notes',
        'created_user_id', 'updated_user_id',
    ];

    protected $casts = [
        'incident_date' => 'date',
        'filed_date' => 'date',
        'claimed_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
    ];

    public function insurance(): BelongsTo
    {
        return $this->belongsTo(VehicleInsurance::class, 'vehicle_insurance_id');
    }
}
