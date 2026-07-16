<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Booking Search Model
 * 
 * Stores customer search queries for vehicle bookings
 * 
 * @property string $id
 * @property string $session_id
 * @property string $service_type
 * @property array $search_data
 * @property \Carbon\Carbon $pickup_date
 * @property \Carbon\Carbon|null $dropoff_date
 * @property string|null $pickup_location
 * @property string|null $dropoff_location
 * @property float|null $estimated_distance
 * @property int|null $duration_hours
 * @property int|null $duration_minutes
 * @property int|null $duration_days
 * @property string|null $customer_id
 * @property string|null $ip_address
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class BookingSearch extends BaseModel
{
    protected $table = 'booking_searches';

    protected $fillable = [
        'session_id',
        'service_type',
        'search_data',
        'pickup_date',
        'dropoff_date',
        'pickup_location',
        'dropoff_location',
        'estimated_distance',
        'is_return_trip',
        'outbound_distance_km',
        'return_distance_km',
        'outbound_duration_seconds',
        'return_duration_seconds',
        'duration_hours',
        'duration_minutes',
        'duration_days',
        'customer_id',
        'ip_address',
    ];

    protected $casts = [
        'search_data' => 'array',
        'pickup_date' => 'datetime',
        'dropoff_date' => 'datetime',
        'estimated_distance' => 'float',
        'is_return_trip' => 'boolean',
        'outbound_distance_km' => 'float',
        'return_distance_km' => 'float',
        'outbound_duration_seconds' => 'integer',
        'return_duration_seconds' => 'integer',
        'duration_hours' => 'integer',
        'duration_minutes' => 'integer',
        'duration_days' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the service type for this search
     * Now uses UUID (id) instead of code for proper foreign key relationship
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type', 'id');
    }

    /**
     * Calculate duration in hours from dates
     */
    public function calculateDuration(): void
    {
        if ($this->pickup_date && $this->dropoff_date) {
            if ($this->dropoff_date->lessThan($this->pickup_date)) {
                throw new \InvalidArgumentException('Drop-off date/time must be after pickup date/time.');
            }

            $this->duration_minutes = (int) ceil($this->pickup_date->diffInSeconds($this->dropoff_date) / 60);
            $this->duration_hours = (int) ceil($this->duration_minutes / 60);
            $this->duration_days = $this->pickup_date->diffInDays($this->dropoff_date) + 1; // Calendar days

            if ($this->duration_days == 0) {
                $this->duration_days = 1; // Minimum 1 day
            }
        } else {
            // For services without a dropoff date (like airport transfers), set default duration
            // Airport transfers are typically point-to-point, so we set 1 hour as a reasonable estimate
            $this->duration_hours = 1;
            $this->duration_minutes = 60;
            $this->duration_days = 1;
        }
    }

    /**
     * Get formatted search summary
     */
    public function getSearchSummary(): array
    {
        return [
            'service_type' => $this->service_type,
            'pickup_date' => $this->pickup_date?->format('d M Y'),
            'pickup_time' => $this->search_data['time'] ?? null,
            'dropoff_date' => $this->dropoff_date?->format('d M Y'),
            'pickup_location' => $this->pickup_location,
            'dropoff_location' => $this->dropoff_location,
            'duration_days' => $this->duration_days,
            'duration_hours' => $this->duration_hours,
            'duration_minutes' => $this->duration_minutes,
        ];
    }

    /**
     * Get total distance including return trip if applicable
     */
    public function getTotalDistanceKm(): float
    {
        if ($this->is_return_trip && $this->return_distance_km) {
            return ($this->outbound_distance_km ?? 0) + $this->return_distance_km;
        }
        
        return $this->outbound_distance_km ?? $this->estimated_distance ?? 0;
    }

    /**
     * Get total duration including return trip if applicable
     */
    public function getTotalDurationSeconds(): int
    {
        if ($this->is_return_trip && $this->return_duration_seconds) {
            return ($this->outbound_duration_seconds ?? 0) + $this->return_duration_seconds;
        }
        
        return $this->outbound_duration_seconds ?? 0;
    }

    /**
     * Check if this search has return trip data
     */
    public function hasReturnTrip(): bool
    {
        return $this->is_return_trip && $this->return_distance_km > 0;
    }
}
