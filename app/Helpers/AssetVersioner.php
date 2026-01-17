<?php

namespace App\Helpers;

/**
 * AssetVersioner Helper
 * 
 * Automatically generate cache-busting versions for static assets based on file modification time.
 * This eliminates the need for manual version updates when CSS/JS files change.
 * 
 * Usage in Blade:
 *   {{ assetVersion('assets/css/style.css') }}
 *   {{ assetVersion('assets/js/custom.js') }}
 * 
 * Output example:
 *   /assets/css/style.css?v=1705432567
 */
class AssetVersioner
{
    /**
     * Generate versioned asset URL based on file modification time
     * 
     * @param string $assetPath The path to the asset (e.g., 'assets/css/style.css')
     * @param bool $fullPath Whether to return full URL or just path with version
     * @return string The versioned asset path/URL
     */
    public static function version($assetPath, $fullPath = false)
    {
        try {
            // Get the full file path
            $filePath = public_path($assetPath);

            // Check if file exists
            if (!file_exists($filePath)) {
                // Return asset path without version if file doesn't exist (won't break if file is added later)
                return asset($assetPath);
            }

            // Get file modification time
            $mtime = filemtime($filePath);

            // Create version based on file modification time
            $version = $mtime ? $mtime : time();

            // Build the versioned URL
            $versionedPath = asset($assetPath) . '?v=' . $version;

            return $versionedPath;
        } catch (\Exception $e) {
            // On any error, just return the asset without version
            \Log::warning('AssetVersioner error for ' . $assetPath . ': ' . $e->getMessage());
            return asset($assetPath);
        }
    }

    /**
     * Generate versioned asset URL using hash of file content
     * More reliable than modification time but slightly slower
     * 
     * @param string $assetPath The path to the asset
     * @return string The versioned asset path/URL
     */
    public static function versionByHash($assetPath)
    {
        try {
            $filePath = public_path($assetPath);

            if (!file_exists($filePath)) {
                return asset($assetPath);
            }

            // Create hash of file content (first 20KB to avoid performance hit)
            $fileHandle = fopen($filePath, 'rb');
            $content = fread($fileHandle, 20480);
            fclose($fileHandle);

            $hash = substr(md5($content), 0, 8);

            return asset($assetPath) . '?v=' . $hash;
        } catch (\Exception $e) {
            \Log::warning('AssetVersioner hash error for ' . $assetPath . ': ' . $e->getMessage());
            return asset($assetPath);
        }
    }

    /**
     * Clear all hardcoded versions and use automatic versioning
     * This is a utility to help debug versioned assets
     * 
     * @return void
     */
    public static function debugVersions()
    {
        $assetPaths = [
            'assets/css/style.css',
            'assets/css/booking-form.css',
            'assets/css/popup-modal.css',
            'assets/js/custom.js',
            'assets/js/booking-form.js',
            'assets/js/popup-display.js',
        ];

        foreach ($assetPaths as $path) {
            echo $path . ' => ' . self::version($path) . "\n";
        }
    }
}
