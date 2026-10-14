<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class VehicleShareLink extends BaseModel
{
    protected $fillable = [
        'vehicle_id',
        'token',
        'expires_at',
        'accessed_at',
        'access_count',
        'created_user_id',
        'updated_user_id',
        'is_active',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accessed_at' => 'datetime',
        'access_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function createdUser()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->forceFill(['accessed_at' => Carbon::now()])->saveQuietly();
    }

    public static function createForVehicle(Vehicle $vehicle, ?string $userId = null): self
    {
        $token = Str::random(40);

        return self::create([
            'vehicle_id' => $vehicle->id,
            'token' => $token,
            'expires_at' => Carbon::now()->addMonths(6),
            'access_count' => 0,
            'created_user_id' => $userId,
            'is_active' => true,
        ]);
    }
}
