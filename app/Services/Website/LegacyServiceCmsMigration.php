<?php

namespace App\Services\Website;

use App\Models\InquiryServicePage;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyServiceCmsMigration
{
    /** @return array<int, array<string, string|null>> */
    public function report(): array
    {
        $type = CmsContentType::withInactive()->where('slug', 'services')->first();
        if (! $type) {
            throw new \RuntimeException('CMS content type "services" is missing.');
        }

        $contents = CmsContent::withTrashed()->withInactive()->get();
        $pages = InquiryServicePage::withInactive()->with('sections')->orderBy('slug')->get();
        $duplicateSlugs = $pages->groupBy(fn ($page) => Str::slug($page->slug))
            ->filter(fn ($group) => $group->count() > 1)->keys();

        return $pages->map(function ($page) use ($contents, $duplicateSlugs, $type) {
            $slug = Str::slug($page->slug);
            $traced = $contents->filter(fn ($content) => (string) data_get($content->custom_fields, 'legacy_inquiry_service_page_id') === (string) $page->id);
            $sameSlug = $contents->filter(fn ($content) => Str::slug($content->slug) === $slug);
            $serviceMatches = $sameSlug->where('cms_content_type_id', $type->id);

            if ($duplicateSlugs->contains($slug) || $traced->count() > 1 || $serviceMatches->count() > 1) {
                $action = 'ambiguous';
                $target = null;
            } elseif ($traced->count() === 1) {
                $target = $traced->first();
                $action = $target->cms_content_type_id === $type->id && ! $target->trashed()
                    ? 'already_migrated' : 'collision';
            } elseif ($sameSlug->count() !== $serviceMatches->count() || $sameSlug->contains(fn ($content) => $content->trashed())) {
                $action = 'collision';
                $target = null;
            } elseif ($serviceMatches->count() === 1) {
                $target = $serviceMatches->first();
                $otherLegacy = data_get($target->custom_fields, 'legacy_inquiry_service_page_id');
                $action = $otherLegacy && (string) $otherLegacy !== (string) $page->id
                    ? 'collision' : 'match';
            } else {
                $target = null;
                $action = $page->slug === $slug ? 'create_draft' : 'collision';
            }

            return [
                'legacy_id' => (string) $page->id,
                'legacy_slug' => $page->slug,
                'action' => $action,
                'cms_id' => $target?->id,
            ];
        })->all();
    }

    /** @return array<int, array<string, string|null>> */
    public function apply(): array
    {
        return DB::transaction(function () {
            // Recompute inside the transaction; rerunning never overwrites CMS-authored content.
            $rows = $this->report();
            $type = CmsContentType::withInactive()->where('slug', 'services')->firstOrFail();
            foreach ($rows as &$row) {
                if ($row['action'] === 'match') {
                    $content = CmsContent::withInactive()->findOrFail($row['cms_id']);
                    $page = InquiryServicePage::withInactive()->findOrFail($row['legacy_id']);
                    $fields = $content->custom_fields ?? [];
                    $fields['legacy_inquiry_service_page_id'] = $page->id;
                    $content->custom_fields = $fields;
                    if (! $content->inquiry_form_id) {
                        $content->inquiry_form_id = $page->inquiry_form_id;
                    }
                    $content->save();
                } elseif ($row['action'] === 'create_draft') {
                    $page = InquiryServicePage::withInactive()->with('sections')->findOrFail($row['legacy_id']);
                    $sections = $page->sections->isNotEmpty()
                        ? $page->sections->map(fn ($section) => ['type' => $section->type, 'data' => $section->data, 'sort_order' => $section->sort_order, 'is_active' => $section->is_active])->all()
                        : data_get($page->content, 'sections', []);
                    $body = collect($sections)->filter(fn ($section) => data_get($section, 'is_active', true))
                        ->map(fn ($section) => data_get($section, 'data.body', ''))
                        ->filter(fn ($value) => is_string($value) && $value !== '')->implode("\n");
                    $hero = collect($sections)->first(fn ($section) => data_get($section, 'type') === 'hero');
                    $content = CmsContent::create([
                        'cms_content_type_id' => $type->id,
                        'inquiry_form_id' => $page->inquiry_form_id,
                        'title' => $page->name,
                        'slug' => $page->slug,
                        'body' => $body ?: null,
                        'excerpt' => data_get($hero, 'data.subheading'),
                        'featured_image' => data_get($hero, 'data.banner_image'),
                        'meta_title' => $page->seo_title,
                        'meta_description' => $page->seo_description,
                        'meta_tags' => $page->seo_keywords,
                        'display_order' => $page->sort_order,
                        'status' => 'draft',
                        'is_active' => (bool) $page->is_active,
                        'custom_fields' => [
                            'legacy_inquiry_service_page_id' => $page->id,
                            'legacy_sections' => $sections,
                            'legacy_status' => $page->status,
                        ],
                    ]);
                    $row['cms_id'] = $content->id;
                }
            }
            unset($row);

            return $rows;
        });
    }
}
