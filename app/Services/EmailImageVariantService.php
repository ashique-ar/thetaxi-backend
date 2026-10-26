<?php

namespace App\Services;

use App\Models\UserMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class EmailImageVariantService
{
    public function createForUpload(string $bytes, UserMedia $source): void
    {
        // shortcut: only managed raster branding and vehicle thumbnails, expand when other upload categories enter email templates.
        $variant = match (true) {
            $source->category === 'branding' || $source->category === 'logos' => 'logo',
            $source->category === 'vehicles' && str_contains($source->full_path, '/thumbnails/') => 'vehicle',
            default => null,
        };

        if ($variant) {
            try {
                $this->create($bytes, $source, $variant);
            } catch (\Throwable $e) {
                Log::warning('Could not create email image variant', ['path' => $source->full_path, 'error' => $e->getMessage()]);
            }
        }
    }

    public function createFromStored(UserMedia $source): bool
    {
        if ($source->is_thumbnail) {
            return false;
        }

        $variant = match (true) {
            in_array($source->category, ['branding', 'logos'], true) => 'logo',
            $source->category === 'vehicles' && str_contains($source->full_path, '/thumbnails/') => 'vehicle',
            default => null,
        };

        if (!$variant) {
            return false;
        }

        $path = $this->variantPath($source->full_path, $variant);
        if (Storage::disk($source->storage_type)->exists($path)) {
            return false;
        }

        $this->create(Storage::disk($source->storage_type)->get($source->full_path), $source, $variant);
        return true;
    }

    public function url(string|array|null $source, string $variant): ?string
    {
        $path = is_array($source) ? ($source['path'] ?? $source['url'] ?? null) : $source;
        if (!$path || !in_array($variant, ['logo', 'vehicle'], true)) {
            return $path ? s3_asset($path) : null;
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = ltrim(preg_replace('#^/resources/#', '', $path), '/');
        $variantPath = $this->variantPath($path, $variant);
        $sourceMedia = UserMedia::query()->where('full_path', $path)->first();
        $media = $sourceMedia
            ? UserMedia::query()->where('full_path', $variantPath)->where('storage_type', $sourceMedia->storage_type)->first()
            : null;

        if (!$media) {
            return s3_asset($source);
        }

        return $media->storage_type === 's3'
            ? s3_asset($variantPath)
            : route('resources.assets', ['path' => $variantPath]);
    }

    private function create(string $bytes, UserMedia $source, string $variant): void
    {
        $image = Image::read($bytes);
        if ($variant === 'logo') {
            $image->scaleDown(width: 600, height: 240);
            $extension = 'png';
            $mime = 'image/png';
            $encoded = $image->encodeUsingFileExtension($extension);
        } else {
            $image->cover(300, 200);
            $extension = 'jpg';
            $mime = 'image/jpeg';
            $encoded = $image->encodeUsingFileExtension($extension, quality: 82);
        }

        $path = $this->variantPath($source->full_path, $variant);
        $disk = Storage::disk($source->storage_type);
        $disk->put($path, (string) $encoded, ['CacheControl' => 'public, max-age=31536000, immutable']);

        UserMedia::query()->updateOrCreate(
            ['full_path' => $path, 'storage_type' => $source->storage_type],
            [
                'file_name' => basename($path),
                'original_name' => 'email_' . basename($source->full_path),
                'file_path' => dirname($path),
                'mime_type' => $mime,
                'size' => strlen((string) $encoded),
                'width' => $image->width(),
                'height' => $image->height(),
                'user_id' => $source->user_id,
                'category' => $source->category,
                'is_image' => true,
                'is_thumbnail' => true,
                'parent_id' => $source->id,
                'storage_type' => $source->storage_type,
                'metadata' => ['variant' => 'email-' . $variant],
            ]
        );
    }

    private function variantPath(string $path, string $variant): string
    {
        return dirname($path) . '/email/' . pathinfo($path, PATHINFO_FILENAME) . '-' . $variant . ($variant === 'logo' ? '.png' : '.jpg');
    }
}
