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
        'duration_hours',
        'duration_days',
        'customer_id',
        'ip_address',
    ];

    protected $casts = [
        'search_data' => 'array',
        'pickup_date' => 'datetime',
        'dropoff_date' => 'datetime',
        'estimated_distance' => 'float',
        'duration_hours' => 'integer',
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
            $this->duration_hours = $this->pickup_date->diffInHours($this->dropoff_date);
            $this->duration_days = $this->pickup_date->diffInDays($this->dropoff_date) + 1; // Calendar days
            
            if ($this->duration_days == 0) {
                $this->duration_days = 1; // Minimum 1 day
            }
        } else {
            // For services without a dropoff date (like airport transfers), set default duration
            // Airport transfers are typically point-to-point, so we set 1 hour as a reasonable estimate
            $this->duration_hours = 1;
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
        ];
    }
}
