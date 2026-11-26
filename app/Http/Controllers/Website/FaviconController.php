<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\UserMedia;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class FaviconController extends Controller
{
    public function ico(): Response
    {
        return $this->serve('ico', 48, 'image/x-icon');
    }

    public function png(): Response
    {
        return $this->serve('png', 192, 'image/png');
    }

    public function appleTouchIcon(): Response
    {
        return $this->serve('png', 180, 'image/png');
    }

    private function serve(string $format, int $size, string $contentType): Response
    {
        $favicon = trim((string) (app(WebsiteSettingsService::class)->getBrandingSettings()['brand_favicon'] ?? ''));
        if ($favicon === '') {
            return response('', 404);
        }

        $cacheKey = 'website_favicon:' . $format . ':' . $size . ':' . md5($favicon);
        $bytes = Cache::remember($cacheKey, now()->addDays(7), function () use ($favicon, $format, $size): ?string {
            $raw = $this->loadRawBytes($favicon);
            if ($raw === null || $raw === '') {
                return null;
            }

            // Preserve valid ICO files as-is when /favicon.ico is requested.
            if ($format === 'ico' && str_starts_with($raw, "\x00\x00\x01\x00")) {
                return $raw;
            }

            $png = $this->renderSquarePng($raw, $size);
            if ($png === null) {
                return null;
            }

            return $format === 'ico' ? $this->wrapPngAsIco($png, $size) : $png;
        });

        if ($bytes === null) {
            return response('', 404);
        }

        return response($bytes, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function loadRawBytes(string $favicon): ?string
    {
        $path = ltrim($favicon, '/');
        if (str_starts_with($path, 'assets/')) {
            $localFile = public_path($path);
            return is_file($localFile) ? (file_get_contents($localFile) ?: null) : null;
        }

        $path = preg_replace('#^resources/#', '', $path) ?? $path;
        try {
            $media = UserMedia::query()->where('full_path', $path)->first();
        } catch (\Throwable) {
            $media = null;
        }
        $diskName = $media?->storage_type ?: (config('filesystems.disks.s3') ? 's3' : config('filesystems.default'));

        if ($diskName && config('filesystems.disks.' . $diskName)) {
            $disk = Storage::disk($diskName);
            if ($disk->exists($path)) {
                return $disk->get($path);
            }
        }

        $defaultDisk = config('filesystems.default');
        if ($defaultDisk && $defaultDisk !== $diskName && Storage::disk($defaultDisk)->exists($path)) {
            return Storage::disk($defaultDisk)->get($path);
        }

        return null;
    }

    private function renderSquarePng(string $raw, int $size): ?string
    {
        $source = @imagecreatefromstring($raw);
        if (!$source) {
            return null;
        }

        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $size, $size, $srcWidth, $srcHeight);

        ob_start();
        imagepng($canvas, null, 6);
        $png = ob_get_clean();

        return is_string($png) && $png !== '' ? $png : null;
    }

    private function wrapPngAsIco(string $png, int $size): string
    {
        $dim = $size >= 256 ? 0 : $size;
        $header = pack('vvv', 0, 1, 1);
        $entry = pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, strlen($png), 22);

        return $header . $entry . $png;
    }
}
