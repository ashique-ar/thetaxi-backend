<?php

namespace App\Services;

use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;

class HomepageCmsSections
{
    public function __construct(private WebsiteSettingsService $settings) {}

    public function load(): array
    {
        $saved = $this->settings->get('homepage_cms_sections');
        if ($saved === null) {
            return ['managed' => false, 'sections' => []];
        }
        $configured = is_string($saved) ? json_decode($saved, true) : $saved;
        if (!is_array($configured)) {
            return ['managed' => false, 'sections' => []];
        }

        $configured = array_values(array_filter($configured, fn ($section) =>
            is_array($section) && !empty($section['enabled']) && !empty($section['content_type_id'])
        ));
        if (!$configured) {
            return ['managed' => true, 'sections' => []];
        }

        $types = CmsContentType::query()->where('is_active', true)
            ->whereDoesntHave('parent', fn ($query) => $query->where('is_active', false))
            ->whereIn('id', array_column($configured, 'content_type_id'))
            ->get()->keyBy('id');
        $sections = [];

        foreach ($configured as $section) {
            $type = $types->get($section['content_type_id']);
            if (!$type) {
                continue;
            }
            $query = CmsContent::published()->where('cms_content_type_id', $type->id);
            $mode = $section['mode'] ?? 'featured';
            if ($mode === 'manual') {
                $ids = array_values(array_unique(array_filter($section['content_ids'] ?? [])));
                if (!$ids) {
                    continue;
                }
                $items = $query->whereIn('id', $ids)->get()
                    ->sortBy(fn ($item) => array_search($item->id, $ids, true))->values();
            } else {
                if ($mode === 'featured') {
                    $query->where('is_featured', true);
                }
                $items = $query->orderByDesc('published_at')->orderByDesc('created_at')
                    ->limit(max(1, min(24, (int) ($section['limit'] ?? 7))))->get();
            }
            if ($items->isEmpty()) {
                continue;
            }
            $sections[] = [
                'type' => $type,
                'title' => trim((string) ($section['title'] ?? '')) ?: $type->title,
                'eyebrow' => trim((string) ($section['eyebrow'] ?? '')),
                'description' => trim((string) ($section['description'] ?? '')),
                'link_text' => trim((string) ($section['link_text'] ?? '')) ?: 'View All ' . $type->title,
                'items' => $items,
            ];
        }

        return ['managed' => true, 'sections' => $sections];
    }
}
