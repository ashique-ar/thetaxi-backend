<?php

use Illuminate\Support\Facades\Storage;

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
        Image::decode($file)
            ->save($fullPath . '/' . $imageName, quality: 90);

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
        // Normalize common media payload shapes (array/object) to a string path.
        if (is_array($path)) {
            $path = $path['path'] ?? ($path['url'] ?? ($path[0] ?? null));
        } elseif (is_object($path)) {
            $path = $path->path ?? ($path->url ?? null);
        }

        // Return early for empty paths
        if (!$path || !is_string($path)) {
            return null;
        }

        $path = trim($path);

        // Masked media URLs stay domain-independent and are served by resources.assets.
        if (str_starts_with($path, '/resources/')) {
            return $path;
        }

        if (preg_match('/^https?:\/\//', $path)) {
            $asset = parse_url($path);
            $host = strtolower($asset['host'] ?? '');
            $diskUrl = config('filesystems.disks.s3.url');
            $configuredHost = strtolower(parse_url($diskUrl ?: '', PHP_URL_HOST) ?: '');
            $isS3Host = str_ends_with($host, '.amazonaws.com') &&
                (str_contains($host, '.s3.') || str_starts_with($host, 's3.'));

            if ($configuredHost && $host === $configuredHost) {
                $path = ltrim($asset['path'] ?? '', '/');
                $prefix = trim(parse_url($diskUrl, PHP_URL_PATH) ?: '', '/');
                if ($prefix && str_starts_with($path, $prefix . '/')) {
                    $path = substr($path, strlen($prefix) + 1);
                }
            } elseif ($isS3Host) {
                $path = ltrim($asset['path'] ?? '', '/');
                if (str_starts_with($host, 's3.') || str_starts_with($host, 's3-')) {
                    $path = preg_replace('/^[^\/]+\//', '', $path);
                }
            } else {
                return $path;
            }
        }

        // If path points to local assets folder, use asset() helper
        if (str_starts_with($path, 'assets/') || str_starts_with($path, '/assets/')) {
            return app('url')->asset(ltrim($path, '/'), $secure);
        }

        return resource_proxy_url($path, 's3', now()->addYears(10));
    }
}

if (!function_exists('resource_proxy_url')) {
    function resource_proxy_url(string $path, string $disk = 's3', ?\DateTimeInterface $expiresAt = null): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'resources.assets',
            $expiresAt ?? now()->addMinutes(15),
            ['path' => ltrim($path, '/'), 'disk' => $disk]
        );
    }
}

if (!function_exists('proxy_s3_urls')) {
    function proxy_s3_urls($value)
    {
        if (is_array($value)) {
            return array_map('proxy_s3_urls', $value);
        }

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/https?:\/\/[^\s"\'<>]+/i', function ($match) {
            $url = rtrim($match[0], '.,;)]');
            return s3_asset($url) . substr($match[0], strlen($url));
        }, $value);
    }
}

if (!function_exists('check_s3_asset')) {
    function check_s3_asset($path, $secure = null)
    {
        if (!$path) {
            return false;
        }
        return Storage::disk('s3')->exists(ltrim($path, '/'));
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
    function isServiceDurationBased(?string $serviceType, ?string $pricingMode = null): bool
    {
        return $pricingMode !== null
            ? $pricingMode === 'day'
            : in_array($serviceType, ['corporate', 'day_rental', 'self_drive', 'with_driver']);
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

/**
 * Extract concise location name from address, array, object, or JSON string.
 *
 * @param mixed $location Location payload or string
 * @param string $default Default fallback if empty
 * @return string Concise location name
 */
if (!function_exists('formatLocationName')) {
    function formatLocationName(mixed $location, string $default = ''): string
    {
        if (empty($location)) {
            return $default;
        }

        if (is_string($location)) {
            $trimmed = trim($location);
            if ($trimmed === '') {
                return $default;
            }
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                    $location = $decoded;
                }
            }
        }

        if (is_array($location)) {
            if (!empty($location['name'])) {
                return trim((string) $location['name']);
            }
            $location = $location['address'] ?? ($location['display_name'] ?? '');
        } elseif (is_object($location)) {
            if (!empty($location->name)) {
                return trim((string) $location->name);
            }
            $location = $location->address ?? ($location->display_name ?? '');
        }

        $str = trim((string) $location);
        if ($str === '') {
            return $default;
        }

        $parts = explode(',', $str);
        $name = trim($parts[0]);

        return $name !== '' ? $name : $default;
    }
}

