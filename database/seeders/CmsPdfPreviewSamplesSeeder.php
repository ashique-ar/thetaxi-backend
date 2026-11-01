<?php

namespace Database\Seeders;

use App\Models\UserMedia;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class CmsPdfPreviewSamplesSeeder extends Seeder
{
    public function run(): void
    {
        $contentType = CmsContentType::updateOrCreate(['slug' => 'pdf-preview-demo'], [
            'title' => 'PDF Preview Demo',
            'description' => 'Temporary CMS PDF preview samples.',
            'is_active' => true,
        ]);
        $disk = config('fileupload.default_disk', 'local');
        $token = 'cms-demo';
        $path = "media/cms/content-pdfs/pdf-preview-sample-{$token}.pdf";
        $pages = collect(range(1, 3))->map(fn ($page) => "<h1>PDF Preview Sample — Page {$page}</h1><p>This page confirms that uploaded PDFs render in the public CMS.</p>" . ($page < 3 ? '<div style="page-break-after: always"></div>' : ''))->implode('');
        $bytes = Pdf::loadHTML('<html><body style="font-family: sans-serif; padding: 48px">' . $pages . '</body></html>')->setPaper('a4')->output();
        Storage::disk($disk)->put($path, $bytes);

        UserMedia::updateOrCreate(['full_path' => $path], [
            'file_name' => basename($path),
            'original_name' => 'PDF Preview Sample.pdf',
            'file_path' => dirname($path),
            'full_path' => $path,
            'mime_type' => 'application/pdf',
            'size' => strlen($bytes),
            'category' => 'sections',
            'is_image' => false,
            'storage_type' => $disk,
            'metadata' => ['cms_pdf_preview_sample' => true],
        ]);

        foreach (['pages', 'carousel', 'magazine'] as $preview) {
            $slug = "pdf-preview-{$preview}-{$token}";
            $content = CmsContent::updateOrCreate(['slug' => $slug], [
                'cms_content_type_id' => $contentType->id,
                'title' => "PDF Preview Demo — " . ucfirst($preview) . " — {$token}",
                'slug' => $slug,
                'author' => 'CMS Preview Demo',
                'excerpt' => "Temporary sample for testing the {$preview} PDF preview.",
                'body' => '',
                'status' => 'published',
                'is_active' => true,
                'published_at' => now(),
                'custom_fields' => ['pdf_file' => $path, 'pdf_preview' => $preview],
            ]);

            $this->command?->line(url("/{$contentType->slug}/{$content->slug}"));
        }
    }
}
