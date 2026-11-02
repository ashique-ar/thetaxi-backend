<?php

namespace App\Models\Tours;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TourItineraryDay extends BaseModel
{
    protected $table = 'tour_itinerary_days';

    protected $fillable = [
        'tour_itinerary_id',
        'day_number',
        'service_date',
        'timezone',
        'start_time',
        'start_destination_id',
        'end_destination_id',
        'start_location_snapshot',
        'end_location_snapshot',
        'overnight_destination_id',
        'is_locked',
    ];

    protected $casts = [
        'day_number' => 'integer',
        'service_date' => 'date:Y-m-d',
        'start_location_snapshot' => 'array',
        'end_location_snapshot' => 'array',
        'is_locked' => 'boolean',
    ];

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(TourItinerary::class, 'tour_itinerary_id');
    }

    public function overnightDestination(): BelongsTo
    {
        return $this->belongsTo(TourDestination::class, 'overnight_destination_id');
    }

    public function startDestination(): BelongsTo
    {
        return $this->belongsTo(TourDestination::class, 'start_destination_id');
    }

    public function endDestination(): BelongsTo
    {
        return $this->belongsTo(TourDestination::class, 'end_destination_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TourItineraryStop::class)->orderBy('sequence');
    }
}
