<?php

namespace App\Console\Commands;

use App\Models\UserMedia;
use App\Services\EmailImageVariantService;
use Illuminate\Console\Command;

class GenerateEmailImageVariants extends Command
{
    protected $signature = 'media:generate-email-variants';
    protected $description = 'Generate cached, email-sized logo and vehicle image variants';

    public function handle(EmailImageVariantService $variants): int
    {
        $created = 0;
        $skipped = 0;

        UserMedia::query()
            ->where('is_image', true)
            ->where('is_thumbnail', false)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->where(function ($query) {
                $query->whereIn('category', ['branding', 'logos'])
                    ->orWhere(function ($query) {
                        $query->where('category', 'vehicles')->where('full_path', 'like', '%/thumbnails/%');
                    });
            })
            ->chunkById(100, function ($media) use ($variants, &$created, &$skipped) {
                foreach ($media as $source) {
                    try {
                        $variants->createFromStored($source) ? $created++ : $skipped++;
                    } catch (\Throwable $e) {
                        $this->components->error($source->full_path . ': ' . $e->getMessage());
                        $skipped++;
                    }
                }
            });

        $this->components->info("Generated {$created} variants; skipped {$skipped}.");
        return self::SUCCESS;
    }
}
