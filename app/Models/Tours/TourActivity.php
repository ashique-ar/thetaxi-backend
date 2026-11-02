<?php

namespace App\Models\Tours;

use App\Models\BaseModel;
use App\Models\PredefinedLocation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourActivity extends BaseModel
{
    protected $table = 'tour_activities';

    protected $fillable = [
        'tour_destination_id',
        'predefined_location_id',
        'code',
        'name',
        'description',
        'visit_duration_minutes',
        'opens_at',
        'closes_at',
        'sort_order',
        'is_published',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'visit_duration_minutes' => 'integer',
        'sort_order' => 'integer',
        'is_published' => 'boolean',
    ];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TourDestination::class, 'tour_destination_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(PredefinedLocation::class, 'predefined_location_id');
    }
}
