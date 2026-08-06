<?php

namespace Tests\Unit;

use App\Http\Resources\Website\CmsContentResource;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Http\Request;
use Tests\TestCase;

class CmsContentPublicUrlContractTest extends TestCase
{
    public function test_it_exposes_the_generic_cms_route_for_publicly_available_content(): void
    {
        $content = $this->content('blogs');

        $this->assertSame(
            route('cms.show', ['contentType' => 'blogs', 'content' => 'airport-guide']),
            $this->resolve($content)['full_url']
        );
    }

    public function test_it_does_not_expose_a_url_that_is_owned_by_inquiry_service_pages(): void
    {
        $content = $this->content('services');

        $this->assertNull($this->resolve($content)['full_url']);
    }

    public function test_it_does_not_expose_unavailable_content_as_a_public_page(): void
    {
        $draft = $this->content('blogs', ['status' => 'draft']);
        $hidden = $this->content('blogs', ['is_active' => false]);
        $scheduled = $this->content('blogs', ['published_at' => now()->addDay()]);

        $this->assertNull($this->resolve($draft)['full_url']);
        $this->assertNull($this->resolve($hidden)['full_url']);
        $this->assertNull($this->resolve($scheduled)['full_url']);
    }

    private function content(string $contentTypeSlug, array $attributes = []): CmsContent
    {
        $content = new CmsContent(array_merge([
            'title' => 'Airport Guide',
            'slug' => 'airport-guide',
            'status' => 'published',
            'is_active' => true,
            'published_at' => now()->subMinute(),
        ], $attributes));

        $content->setRelation('contentType', new CmsContentType([
            'title' => ucfirst($contentTypeSlug),
            'slug' => $contentTypeSlug,
            'is_active' => true,
        ]));

        return $content;
    }

    private function resolve(CmsContent $content): array
    {
        return (new CmsContentResource($content))->resolve(Request::create('/'));
    }
}
