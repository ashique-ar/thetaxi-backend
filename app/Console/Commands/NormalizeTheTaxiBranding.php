<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Website\CmsContent;

class NormalizeTheTaxiBranding extends Command
{
    protected $signature = 'cms:normalize-thetaxi-branding 
                            {--dry-run : Preview changes without updating data}';

    protected $description = 'Normalize and style TheTaxi brand mentions inside CMS body content';

    public function handle()
    {
        $this->info('Normalizing TheTaxi branding in CMS body...');

        $dryRun = $this->option('dry-run');
        $updatedCount = 0;

        // Match ONLY "thetaxi" in any case
        $pattern = '/\bthetaxi\b/i';
        $replacement = '<span class="brand-thetaxi">TheTaxi</span>';

        CmsContent::whereNotNull('body')
            ->chunkById(100, function ($contents) use (
                $pattern,
                $replacement,
                $dryRun,
                &$updatedCount
            ) {
                foreach ($contents as $content) {
                    $originalBody = $content->body;

                    // 1. Protect already-styled TheTaxi
                    $protectedBody = str_replace(
                        '<span class="brand-thetaxi">TheTaxi</span>',
                        '%%THETAXI_PROTECTED%%',
                        $originalBody
                    );

                    // 2. Replace raw "thetaxi"
                    $updatedBody = preg_replace(
                        $pattern,
                        $replacement,
                        $protectedBody
                    );

                    // 3. Restore protected branding
                    $updatedBody = str_replace(
                        '%%THETAXI_PROTECTED%%',
                        '<span class="brand-thetaxi">TheTaxi</span>',
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