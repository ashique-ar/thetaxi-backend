<?php

namespace App\Models\Tours;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourQuote extends BaseModel
{
    protected $table = 'tour_quotes';

    protected $fillable = [
        'tour_itinerary_id',
        'itinerary_revision',
        'status',
        'expires_at',
        'currency',
        'total_amount',
        'distance_km',
        'route_snapshot',
        'pricing_snapshot',
        'accepted_at',
        'terms_snapshot',
        'accepted_terms_hash',
        'accepted_by_user_id',
        'acceptance_channel',
    ];

    protected $casts = [
        'itinerary_revision' => 'integer',
        'expires_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'distance_km' => 'decimal:3',
        'route_snapshot' => 'array',
        'pricing_snapshot' => 'array',
        'accepted_at' => 'datetime',
        'terms_snapshot' => 'array',
    ];

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(TourItinerary::class, 'tour_itinerary_id');
    }
}
