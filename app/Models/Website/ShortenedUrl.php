<?php

namespace App\Models\Website;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShortenedUrl extends Model
{
    use HasFactory;

    protected $fillable = [
        'short_code',
        'original_url',
        'expires_at',
        'access_count',
        'last_accessed_at',
        'created_by_type',
        'created_by_id',
        'source',
        'campaign',
        'medium',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Generate a unique short code
     */
    public static function generateShortCode(int $length = 6): string
    {
        do {
            $code = Str::random($length);
        } while (self::where('short_code', $code)->exists());

        return $code;
    }

    /**
     * Increment access count and update last accessed time
     */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }

    /**
     * Check if the shortened URL is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get all clicks for this shortened URL
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(ShortUrlClick::class);
    }

    /**
     * Get analytics summary for this URL
     */
    public function getAnalyticsSummary(): array
    {
        return [
            'total_clicks' => $this->access_count,
            'unique_ips' => $this->clicks()->distinct('ip_address')->count(),
            'countries' => $this->clicks()->whereNotNull('country')->distinct('country')->pluck('country'),
            'devices' => $this->clicks()->whereNotNull('device_type')->groupBy('device_type')->selectRaw('device_type, count(*) as count')->pluck('count', 'device_type'),
            'browsers' => $this->clicks()->whereNotNull('browser')->groupBy('browser')->selectRaw('browser, count(*) as count')->pluck('count', 'browser'),
            'first_click' => $this->clicks()->min('clicked_at'),
            'last_click' => $this->clicks()->max('clicked_at'),
        ];
    }
}