<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortUrlClick extends BaseModel
{
    protected $fillable = [
        'shortened_url_id',
        'ip_address',
        'user_agent',
        'referer',
        'country',
        'city',
        'device_type',
        'browser',
        'platform',
        'clicked_at',
        'response_time_ms',
        'utm_parameters',
        'additional_data',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
        'utm_parameters' => 'array',
        'additional_data' => 'array',
    ];

    /**
     * Get the shortened URL that this click belongs to
     */
    public function shortenedUrl(): BelongsTo
    {
        return $this->belongsTo(ShortenedUrl::class);
    }
}