<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Website\CmsContent;
use Illuminate\Support\Facades\DB;

class UpdateCmsBranding extends Command
{
    protected $signature = 'cms:update-branding 
                            {--dry-run : Preview changes without updating data}';

    protected $description = 'Replace legacy branding with configured branding across CMS contents';

    public function handle()
    {
        $this->info('Starting CMS branding update...');

        $legacyTerms = array_filter(array_map('trim', explode(',', env('CMS_LEGACY_BRAND_TERMS', ''))));
        $searchPatterns = array_map(
            static fn (string $term): string => '/' . preg_quote($term, '/') . '/i',
            $legacyTerms
        );

        if ($searchPatterns === []) {
            $this->warn('No legacy brand terms configured. Set CMS_LEGACY_BRAND_TERMS to run replacements.');
            return Command::SUCCESS;
        }


        $brandName = app(\App\Services\WebsiteSettingsService::class)->get('brand_name', 'Company');
        $website = app(\App\Services\WebsiteSettingsService::class)->get('company_website', config('app.url'));

        $replaceWith = array_map(
            static fn (string $term): string => str_contains($term, '.') ? $website : $brandName,
            $legacyTerms
        );

        $fieldsToUpdate = [
            'title',
            'slug',
            'body',
            'excerpt',
            'meta_title',
            'meta_description',
            'meta_tags',
            'url',
            'custom_fields',
            'author',
        ];

        $dryRun = $this->option('dry-run');
        $updatedCount = 0;

        CmsContent::chunkById(100, function ($contents) use (
            $searchPatterns,
            $replaceWith,
            $fieldsToUpdate,
            $dryRun,
            &$updatedCount
        ) {
            foreach ($contents as $content) {
                $hasChanges = false;

                foreach ($fieldsToUpdate as $field) {
                    if (!empty($content->{$field}) && is_string($content->{$field})) {
                        $updatedValue = preg_replace(
                            $searchPatterns,
                            $replaceWith,
                            $content->{$field}
                        );

                        if ($updatedValue !== $content->{$field}) {
                            $hasChanges = true;

                            if (!$dryRun) {
                                $content->{$field} = $updatedValue;
                            }
                        }
                    }
                }

                if ($hasChanges) {
                    $updatedCount++;

                    if (!$dryRun) {
                        $content->save();
                    }
                }
            }
        });

        if ($dryRun) {
            $this->warn("Dry run completed. {$updatedCount} records would be updated.");
        } else {
            $this->info("Branding update completed. {$updatedCount} records updated.");
        }

        return Command::SUCCESS;
    }
}
