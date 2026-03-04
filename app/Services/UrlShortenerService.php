<?php

namespace App\Services;

use App\Models\Website\ShortenedUrl;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UrlShortenerService
{
    /**
     * Create a shortened URL
     *
     * @param string $originalUrl The full URL to shorten
     * @param int|null $expiryHours Hours until expiry (null for no expiry)
     * @param string|null $createdByType Model type that created this (e.g., 'booking')
     * @param string|null $createdById ID of the model that created this (UUID or integer)
     * @param string|null $source Source of the link (e.g., 'email', 'sms', 'web')
     * @param string|null $campaign Campaign identifier (e.g., 'payment_reminder', 'quotation_follow_up')
     * @param string|null $medium Medium type (e.g., 'notification', 'marketing', 'transactional')
     * @param array|null $metadata Additional context data
     * @return ShortenedUrl
     */
    public static function shorten(
        string $originalUrl,
        ?int $expiryHours = null,
        ?string $createdByType = null,
        ?string $createdById = null,
        ?string $source = null,
        ?string $campaign = null,
        ?string $medium = null,
        ?array $metadata = null
    ): ShortenedUrl {
        // Check if we already have a non-expired short URL for this original URL with same analytics context
        $existing = ShortenedUrl::where('original_url', $originalUrl)
            ->where('source', $source)
            ->where('campaign', $campaign)
            ->where('medium', $medium)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->first();

        if ($existing) {
            Log::info('UrlShortenerService: Reusing existing short URL', [
                'short_code' => $existing->short_code,
                'original_url' => $originalUrl,
                'source' => $source,
                'campaign' => $campaign,
            ]);
            return $existing;
        }

        // Generate new short code
        $shortCode = ShortenedUrl::generateShortCode();
        
        $expiresAt = $expiryHours ? Carbon::now()->addHours($expiryHours) : null;

        $shortenedUrl = ShortenedUrl::create([
            'short_code' => $shortCode,
            'original_url' => $originalUrl,
            'expires_at' => $expiresAt,
            'access_count' => 0,
            'created_by_type' => $createdByType,
            'created_by_id' => $createdById,
            'source' => $source,
            'campaign' => $campaign,
            'medium' => $medium,
            'metadata' => $metadata,
        ]);

        Log::info('UrlShortenerService: Created new short URL', [
            'short_code' => $shortCode,
            'original_url' => $originalUrl,
            'expires_at' => $expiresAt?->toISOString(),
            'source' => $source,
            'campaign' => $campaign,
            'medium' => $medium,
        ]);

        return $shortenedUrl;
    }

    /**
     * Get the full short URL
     *
     * @param ShortenedUrl $shortenedUrl
     * @return string
     */
    public static function getShortUrl(ShortenedUrl $shortenedUrl): string
    {
        return route('short-url.redirect', ['code' => $shortenedUrl->short_code]);
    }

    /**
     * Resolve a short code to its original URL
     *
     * @param string $shortCode
     * @return string|null
     */
    public static function resolve(string $shortCode): ?string
    {
        $shortenedUrl = ShortenedUrl::where('short_code', $shortCode)->first();

        if (!$shortenedUrl) {
            Log::warning('UrlShortenerService: Short code not found', [
                'short_code' => $shortCode,
            ]);
            return null;
        }

        if ($shortenedUrl->isExpired()) {
            Log::warning('UrlShortenerService: Short URL expired', [
                'short_code' => $shortCode,
                'expired_at' => $shortenedUrl->expires_at?->toISOString(),
            ]);
            return null;
        }

        // Record the access
        $shortenedUrl->recordAccess();

        Log::info('UrlShortenerService: Short URL accessed', [
            'short_code' => $shortCode,
            'original_url' => $shortenedUrl->original_url,
            'access_count' => $shortenedUrl->access_count,
        ]);

        return $shortenedUrl->original_url;
    }

    /**
     * Clean up expired short URLs
     *
     * @return int Number of deleted records
     */
    public static function cleanupExpired(): int
    {
        $deleted = ShortenedUrl::where('expires_at', '<', Carbon::now())->delete();
        
        if ($deleted > 0) {
            Log::info('UrlShortenerService: Cleaned up expired short URLs', [
                'deleted_count' => $deleted,
            ]);
        }

        return $deleted;
    }
}