<?php

namespace App\Models\Driver;

use App\Models\BaseModel;
use App\Models\Document;
use App\Models\User;
use App\Models\Vehicle\Vehicle;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverOnboardingApplication extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'driver_id', 'vehicle_id', 'mobile', 'access_token_hash',
        'mobile_verified_at', 'current_step', 'status', 'payload', 'review_issues',
        'review_message', 'reviewed_by', 'submitted_at', 'reviewed_at',
    ];

    protected $hidden = ['access_token_hash'];

    protected $casts = [
        'payload' => 'array', 'review_issues' => 'array', 'mobile_verified_at' => 'datetime',
        'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function driver() { return $this->belongsTo(Driver::class); }
    public function vehicle() { return $this->belongsTo(Vehicle::class); }
    public function documents() { return $this->hasMany(Document::class, 'onboarding_application_id'); }
}
