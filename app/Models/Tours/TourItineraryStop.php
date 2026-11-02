<?php

namespace App\Models\Tours;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourItineraryStop extends BaseModel
{
    protected $table = 'tour_itinerary_stops';

    protected $fillable = [
        'tour_itinerary_day_id',
        'sequence',
        'kind',
        'tour_destination_id',
        'tour_activity_id',
        'location_snapshot',
        'visit_duration_minutes',
        'is_locked',
        'arrival_time',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'location_snapshot' => 'array',
        'visit_duration_minutes' => 'integer',
        'is_locked' => 'boolean',
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(TourItineraryDay::class, 'tour_itinerary_day_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TourDestination::class, 'tour_destination_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(TourActivity::class, 'tour_activity_id');
    }
}
