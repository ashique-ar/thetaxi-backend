<?php

namespace App\Services\Website;

use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;

class PublishedCmsContentResolver
{
    public function contentType(string $slug): CmsContentType
    {
        return CmsContentType::where('slug', $slug)
            ->where('is_active', true)
            ->whereDoesntHave('parent', fn ($query) => $query->where('is_active', false))
            ->firstOrFail();
    }

    public function find(string $type, string $slug): ?CmsContent
    {
        $relations = ['contentType'];
        if ($type === 'services') {
            $relations[] = 'inquiryForm.fields';
        }

        return CmsContent::published()
            ->byType($type)
            ->where('slug', $slug)
            ->with($relations)
            ->first();
    }
}
