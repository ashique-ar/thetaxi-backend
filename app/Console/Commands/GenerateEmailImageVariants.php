<?php

namespace App\Console\Commands;

use App\Models\UserMedia;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleGroup;
use App\Services\EmailImageVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class GenerateEmailImageVariants extends Command
{
    protected $signature = 'media:generate-email-variants {--force : Regenerate existing variants}';
    protected $description = 'Generate cached, email-sized logo and vehicle image variants';

    public function handle(EmailImageVariantService $variants): int
    {
        $created = 0;
        $skipped = 0;
        $force = (bool) $this->option('force');
        $processedIds = [];

        UserMedia::withInactive()
            ->where('is_image', true)
            ->where('is_thumbnail', false)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->where(function ($query) {
                $query->whereIn('category', ['branding', 'logos'])
                    ->orWhere(function ($query) {
                        $query->where('category', 'vehicles')->where('full_path', 'like', '%/thumbnails/%');
                    });
            })
            ->chunkById(100, function ($media) use ($variants, $force, &$created, &$skipped, &$processedIds) {
                foreach ($media as $source) {
                    $processedIds[$source->id] = true;
                    try {
                        $variants->createFromStored($source, $force) ? $created++ : $skipped++;
                    } catch (\Throwable $e) {
                        $this->components->error($source->full_path . ': ' . $e->getMessage());
                        $skipped++;
                    }
                }
            });

        // Also include thumbnails referenced by VehicleGroup / Vehicle regardless of upload path/category.
        $vehicleThumbPaths = collect();
        if (Schema::hasTable('vehicle_groups')) {
            $vehicleThumbPaths = $vehicleThumbPaths->merge(VehicleGroup::withInactive()->whereNotNull('thumbnail')->pluck('thumbnail'));
        }
        if (Schema::hasTable('vehicles')) {
            $vehicleThumbPaths = $vehicleThumbPaths->merge(Vehicle::withInactive()->whereNotNull('thumbnail')->pluck('thumbnail'));
        }
        $vehicleThumbPaths = $vehicleThumbPaths
            ->map(function ($raw) {
                $path = is_array($raw) ? ($raw['path'] ?? $raw['url'] ?? reset($raw)) : $raw;
                if (!is_string($path) || $path === '') {
                    return null;
                }
                $parsed = parse_url($path, PHP_URL_PATH) ?: $path;
                return ltrim(preg_replace('#^/resources/#', '', $parsed), '/');
            })
            ->filter()
            ->unique();

        foreach ($vehicleThumbPaths as $thumbPath) {
            $source = UserMedia::withInactive()
                ->where('full_path', $thumbPath)
                ->where('is_image', true)
                ->where('is_thumbnail', false)
                ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
                ->first();

            if (!$source || isset($processedIds[$source->id])) {
                continue;
            }

            $processedIds[$source->id] = true;
            try {
                $variants->createFromStored($source, $force, 'vehicle') ? $created++ : $skipped++;
            } catch (\Throwable $e) {
                $this->components->error($source->full_path . ': ' . $e->getMessage());
                $skipped++;
            }
        }

        // Regenerate standard upload thumbnails (thumb_*.webp) when --force is passed.
        if ($force) {
            UserMedia::withInactive()
                ->where('is_image', true)
                ->where('is_thumbnail', true)
                ->whereNotNull('parent_id')
                ->with(['parent' => fn ($q) => $q->withInactive()])
                ->chunkById(100, function ($thumbs) use (&$created, &$skipped) {
                    foreach ($thumbs as $thumb) {
                        if (str_starts_with((string) ($thumb->metadata['variant'] ?? ''), 'email-')) {
                            continue;
                        }
                        $parent = $thumb->parent;
                        if (!$parent || !in_array($parent->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                            $skipped++;
                            continue;
                        }
                        try {
                            $disk = Storage::disk($thumb->storage_type ?: config('filesystems.default'));
                            $bytes = $disk->get($parent->full_path);
                            if (!$bytes) {
                                $skipped++;
                                continue;
                            }
                            $img = Image::decode($bytes)
                                ->fillTransparentAreas('#ffffff')
                                ->scaleDown(width: 150, height: 150);
                            $encoded = (string) $img->encodeUsingFileExtension('webp', quality: 85);
                            $disk->put($thumb->full_path, $encoded);
                            $thumb->update([
                                'size' => strlen($encoded),
                                'width' => $img->width(),
                                'height' => $img->height(),
                            ]);
                            $created++;
                        } catch (\Throwable $e) {
                            $this->components->error($thumb->full_path . ': ' . $e->getMessage());
                            $skipped++;
                        }
                    }
                });
        }

        $this->components->info("Generated {$created} variants; skipped {$skipped}.");
        return self::SUCCESS;
    }
}
