<?php

namespace App\Models\Tours;

use App\Models\BaseModel;
use App\Models\PredefinedLocation;
use App\Models\Service\ServiceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TourDestination extends BaseModel
{
    protected $table = 'tour_destinations';

    protected $fillable = [
        'service_type_id',
        'predefined_location_id',
        'code',
        'name',
        'description',
        'timezone',
        'sort_order',
        'is_published',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_published' => 'boolean',
    ];

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(PredefinedLocation::class, 'predefined_location_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TourActivity::class)->orderBy('sort_order')->orderBy('name');
    }
}
