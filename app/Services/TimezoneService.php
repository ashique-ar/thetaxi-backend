<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

/**
 * Service to handle timezone conversions for user-specific operations
 * 
 * Handles converting dates between user's local timezone and UTC for storage/retrieval
 */
class TimezoneService
{
    /**
     * Get the current user's timezone preference
     * Falls back to app timezone if user not authenticated or no preference set
     */
    public static function getUserTimezone(): string
    {
        if (Auth::check() && Auth::user()->timezone) {
            return Auth::user()->timezone;
        }
        
        return config('app.timezone', 'UTC');
    }

    /**
     * Convert a date from user's timezone to UTC for storage
     * 
     * @param  string|Carbon $date The date in user's timezone
     * @return Carbon|null The date converted to UTC
     */
    public static function toUtc($date): ?Carbon
    {
        if (!$date) {
            return null;
        }

        if (is_string($date)) {
            // Parse the date string as if it's in the user's timezone
            $carbon = Carbon::parse($date, static::getUserTimezone());
        } else {
            // If it's already a Carbon instance, convert it
            $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);
        }

        // Convert to UTC
        return $carbon->setTimezone('UTC');
    }

    /**
     * Convert a date from UTC to user's timezone for display
     * 
     * @param  string|Carbon $date The date in UTC
     * @return Carbon|null The date converted to user's timezone
     */
    public static function fromUtc($date): ?Carbon
    {
        if (!$date) {
            return null;
        }

        if (is_string($date)) {
            // Parse the date as UTC first
            $carbon = Carbon::parse($date, 'UTC');
        } else {
            // If it's already a Carbon instance, treat it as UTC
            $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);
            if ($carbon->getTimezoneOffset() !== 0) {
                // If it has a timezone offset, parse it differently
                $carbon = Carbon::parse($date);
            } else {
                // Assume it's UTC
                $carbon->setTimezone('UTC');
            }
        }

        // Convert to user's timezone
        return $carbon->setTimezone(static::getUserTimezone());
    }

    /**
     * Get a date formatted for the user's timezone
     * 
     * @param  string|Carbon $date The date (assumed to be in UTC)
     * @param  string $format The format string
     * @return string The formatted date
     */
    public static function formatForUser($date, string $format = 'Y-m-d H:i:s'): string
    {
        if (!$date) {
            return '';
        }

        return static::fromUtc($date)->format($format);
    }

    /**
     * Parse a date from the user and convert to UTC
     * Useful for API endpoints that receive dates from frontend
     * 
     * @param  string $date The date string from user input
     * @return Carbon The date converted to UTC
     */
    public static function parseUserDate(string $date): Carbon
    {
        return static::toUtc($date);
    }

    /**
     * Check if a timezone string is valid
     * 
     * @param  string $timezone The timezone to check
     * @return bool True if valid
     */
    public static function isValidTimezone(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get list of available timezones
     * 
     * @return array List of timezone identifiers
     */
    public static function getAvailableTimezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    /**
     * Get a list of commonly used timezones with their offsets
     * 
     * @return array Array of timezones with offset information
     */
    public static function getCommonTimezones(): array
    {
        return [
            'UTC' => 'UTC',
            'Asia/Kolkata' => 'India (IST, UTC+5:30)',
            'Asia/Colombo' => 'Sri Lanka (UTC+5:30)',
            'America/New_York' => 'Eastern Time (UTC-5/-4)',
            'America/Chicago' => 'Central Time (UTC-6/-5)',
            'America/Denver' => 'Mountain Time (UTC-7/-6)',
            'America/Los_Angeles' => 'Pacific Time (UTC-8/-7)',
            'Europe/London' => 'London (UTC+0/+1)',
            'Europe/Paris' => 'Paris (UTC+1/+2)',
            'Australia/Sydney' => 'Sydney (UTC+10/+11)',
            'Asia/Singapore' => 'Singapore (UTC+8)',
            'Asia/Bangkok' => 'Bangkok (UTC+7)',
        ];
    }
}
