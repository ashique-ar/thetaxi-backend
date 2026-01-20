<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

if (!function_exists('getUserfromReq')) {
    function getUserfromReq(Request $request)
    {
        $primaryUser = auth()->user();
        $user = auth()->user();

        return [
            'error' => false,
            'user' => $user,
        ];
    }
}

if (!function_exists('assetVersion')) {
    /**
     * Generate a versioned URL for an asset based on file modification time
     * 
     * @param string $path The path to the asset relative to public directory
     * @return string The versioned asset URL
     */
    function assetVersion($path)
    {
        return \App\Helpers\AssetVersioner::version($path);
    }
}


if (!function_exists('image_upload')) {
    function image_upload($destinationPath, $file, $sizex = null, $sizey = null, $imageName = null, $format = null)
    {
        $format = $format ?? 'webp';
        $imageName = $imageName ?? uniqid(time()) . '.' . $format;
        $fullPath = public_path($destinationPath);

        createFileIfNotExist($fullPath);
        Image::read($file)
            ->encodeByExtension($format, 90)
            ->save($fullPath . '/' . $imageName);

        Storage::disk('s3')->put($destinationPath . '/' . $imageName, file_get_contents($fullPath . '/' . $imageName));

        unlink($fullPath . '/' . $imageName);

        return $imageName;
    }
}

if (!function_exists('file_upload')) {
    function file_upload($destinationPath, $file, $fileName = null)
    {
        $extension = $file->getClientOriginalExtension();
        $fileName = ($fileName ?? uniqid(time())) . '.' . $extension;

        $fullPath = public_path($destinationPath);

        $file->move($fullPath, $fileName);

        Storage::disk('s3')->put($destinationPath . '/' . $fileName, file_get_contents($fullPath . '/' . $fileName));

        unlink($fullPath . '/' . $fileName);

        return $fileName;
    }
}

if (!function_exists('s3_asset')) {
    function s3_asset($path, $secure = null)
    {
        // CRITICAL FIX: Build S3 URL directly without calling Storage::url() every time
        // Cache the URL for 24 hours to avoid 200ms+ overhead per call
        $cacheKey = 'asset_url_' . md5($path);

        return Cache::remember($cacheKey, 86400, function () use ($path) {
            try {
                // Build S3 URL directly using config
                $bucket = config('filesystems.disks.s3.bucket');
                $region = config('filesystems.disks.s3.region');
                $url = config('filesystems.disks.s3.url');

                // Use environment-configured URL if available
                if ($url) {
                    return rtrim($url, '/') . '/' . ltrim($path, '/');
                }

                // Otherwise build standard S3 URL
                return "https://{$bucket}.s3.{$region}.amazonaws.com/" . ltrim($path, '/');
            } catch (\Exception $e) {
                // Fallback to local asset if anything fails
                \Log::warning("S3 asset failed for: {$path}", ['error' => $e->getMessage()]);
                return asset('assets/' . $path);
            }
        });
    }
}

if (!function_exists('check_s3_asset')) {
    function check_s3_asset($path, $secure = null)
    {
        return Storage::disk('s3')->exists($path);
    }
}

if (!function_exists('static_asset')) {
    function static_asset($path, $secure = null)
    {
        return app('url')->asset('public/' . $path, $secure);
    }
}

/**
 * Get pricing label based on service type
 * 
 * @param string|null $serviceType The service type code
 * @return string The appropriate pricing label
 */
if (!function_exists('getServicePricingLabel')) {
    function getServicePricingLabel(?string $serviceType): string
    {
        return match ($serviceType) {
            'ride_now' => 'Rate',
            'airport_transfers' => 'Transfer Rate',
            'point_to_point' => 'Trip Rate',
            'corporate' => 'Per Day',
            'day_rental' => 'Per Day',
            default => 'Rate',
        };
    }
}

/**
 * Get duration label based on service type
 * 
 * @param string|null $serviceType The service type code
 * @param int $days Number of days/duration
 * @return string The appropriate duration label
 */
if (!function_exists('getServiceDurationLabel')) {
    function getServiceDurationLabel(?string $serviceType, int $days = 1): string
    {
        return match ($serviceType) {
            'ride_now' => 'One-time trip',
            'airport_transfers' => 'Airport transfer',
            'point_to_point' => 'Trip',
            'corporate', 'day_rental' => $days === 1 ? '1 day' : "{$days} days",
            default => $days === 1 ? '1 day' : "{$days} days",
        };
    }
}

/**
 * Check if service type uses duration-based pricing
 * 
 * @param string|null $serviceType The service type code
 * @return bool Whether the service uses duration-based pricing
 */
if (!function_exists('isServiceDurationBased')) {
    function isServiceDurationBased(?string $serviceType): bool
    {
        return in_array($serviceType, ['corporate', 'day_rental']);
    }
}

/**
 * Check if service type is a fixed-rate (trip-based) service
 * 
 * @param string|null $serviceType The service type code
 * @return bool Whether the service is fixed-rate
 */
if (!function_exists('isServiceFixedRate')) {
    function isServiceFixedRate(?string $serviceType): bool
    {
        return in_array($serviceType, ['ride_now', 'airport_transfers', 'point_to_point']);
    }
}