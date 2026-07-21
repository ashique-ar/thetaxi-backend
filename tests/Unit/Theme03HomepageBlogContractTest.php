<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->journal = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/blog-editorial.blade.php');
    $this->cmsCard = file_get_contents($projectRoot . '/resources/views/components/cms-card.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->homeController = file_get_contents($projectRoot . '/app/Http/Controllers/Website/HomeController.php');
    $this->settingsComposer = file_get_contents($projectRoot . '/app/Http/ViewComposers/SettingsViewComposer.php');
    $this->settingsService = file_get_contents($projectRoot . '/app/Services/WebsiteSettingsService.php');
});

it('selects a Theme 03 journal behind the existing non-empty blog condition', function () {
    expect($this->home)
        ->toContain('@if ($blogs->count() > 0)')
        ->toContain("@include('partials.themes.theme-03.blog-editorial')")
        ->toContain('<x-cms-section :title="$settings[\'blog_section_title\']')
        ->toContain('type="blogs"')
        ->toContain(':limit="3" customTemplate="blog-card2"');
});

it('keeps the controller collection, section settings, type and three-item limit unchanged', function () {
    expect($this->journal)
        ->toContain("\$settings['blog_section_title']")
        ->toContain("\$settings['blog_section_description']")
        ->toContain('$blogs->take(3)')
        ->toContain('type="blogs"')
        ->toContain(':showPrice="false"')
        ->toContain(':showDuration="false"')
        ->toContain(':showRating="false"')
        ->toContain('template="theme-03-editorial-story"')
        ->and($this->homeController)
        ->toContain("'blogs' => collect(\$allCmsContent->get('blogs', []))->take(3)")
        ->not->toContain("\$allCmsContent->get('news'");
});

it('uses the existing normalized CMS card fields and routes', function () {
    expect($this->cmsCard)
        ->toContain("\$template === 'theme-03-editorial-story'")
        ->toContain('class="t3-journal__story')
        ->toContain('{{ $imageUrl }}')
        ->toContain('{{ $detailLink }}')
        ->toContain('{{ $categoryLink }}')
        ->toContain('{{ $location }}')
        ->toContain('{{ $category }}')
        ->toContain('{{ $title }}')
        ->toContain('{{ $excerpt }}')
        ->toContain('$isSpecialOffer && $discount > 0')
        ->toContain('width="1400"')
        ->toContain('height="900"')
        ->toContain('loading="lazy"');
});

it('preserves the existing blogs index and detail actions without adding behavior', function () {
    expect($this->journal)
        ->toContain("route('cms.index', ['contentType' => 'blogs'])")
        ->toContain('View All Stories')
        ->toContain('id="travel-blog-section"')
        ->not->toContain('<form')
        ->not->toContain('<script')
        ->and($this->cmsCard)
        ->toContain("route('cms.show', ['contentType' => \$type, 'content' => \$slug])")
        ->toContain("route('cms.index', ['contentType' => \$type]) . '?category='");
});

it('keeps blog copy and fallback media owned by existing settings', function () {
    expect($this->settingsComposer)
        ->toContain("'blog_section_title'")
        ->toContain("'blog_section_description'")
        ->toContain("'cms_content_placeholder_image'")
        ->and($this->settingsService)
        ->toContain("'blog_section_title'")
        ->toContain("'blog_section_description'")
        ->toContain("'cms_content_placeholder_image'")
        ->and($this->cmsCard)
        ->toContain("\$settings['cms_content_placeholder_image']")
        ->toContain("'assets/img/default-blog.jpg'");
});

it('provides a responsive lead-story and story-index composition scoped to Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-journal')
        ->toContain('body.theme-theme-03 .t3-journal__heading')
        ->toContain('body.theme-theme-03 .t3-journal__stories')
        ->toContain('body.theme-theme-03 .t3-journal__story--lead')
        ->toContain('body.theme-theme-03 .t3-journal__media')
        ->toContain('body.theme-theme-03 .t3-journal__content')
        ->toContain('body.theme-theme-03 .t3-journal__meta')
        ->toContain('body.theme-theme-03 .t3-journal__all')
        ->toContain('@media (max-width: 991px)')
        ->toContain('@media (max-width: 767px)');
});
