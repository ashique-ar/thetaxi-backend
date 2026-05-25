<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Website\CmsContent;

class NormalizeCmsBranding extends Command
{
    protected $signature = 'cms:normalize-branding 
                            {brand? : Brand text to normalize}
                            {--dry-run : Preview changes without updating data}';

    protected $description = 'Normalize and style configured brand mentions inside CMS body content';

    public function handle()
    {
        $brand = trim((string) ($this->argument('brand') ?: app(\App\Services\WebsiteSettingsService::class)->get('brand_name', 'Company')));

        if ($brand === '') {
            $this->error('A brand name is required.');
            return Command::FAILURE;
        }

        $this->info("Normalizing {$brand} branding in CMS body...");

        $dryRun = $this->option('dry-run');
        $updatedCount = 0;

        $pattern = '/\b' . preg_quote($brand, '/') . '\b/i';
        $replacement = '<span class="brand-name">' . e($brand) . '</span>';

        CmsContent::whereNotNull('body')
            ->chunkById(100, function ($contents) use (
                $pattern,
                $replacement,
                $dryRun,
                &$updatedCount
            ) {
                foreach ($contents as $content) {
                    $originalBody = $content->body;

                    // 1. Protect already-styled brand mentions
                    $protectedBody = str_replace(
                        $replacement,
                        '%%BRAND_PROTECTED%%',
                        $originalBody
                    );

                    // 2. Replace raw brand text
                    $updatedBody = preg_replace(
                        $pattern,
                        $replacement,
                        $protectedBody
                    );

                    // 3. Restore protected branding
                    $updatedBody = str_replace(
                        '%%BRAND_PROTECTED%%',
                        $replacement,
                        $updatedBody
                    );

                    if ($updatedBody !== $originalBody) {
                        $updatedCount++;

                        if (!$dryRun) {
                            $content->body = $updatedBody;
                            $content->save();
                        }
                    }
                }
            });

        if ($dryRun) {
            $this->warn("Dry run completed. {$updatedCount} records would be updated.");
        } else {
            $this->info("Brand normalization completed. {$updatedCount} records updated.");
        }

        return Command::SUCCESS;
    }
}
