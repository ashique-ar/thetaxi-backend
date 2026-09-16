<?php

it('owns content type hierarchy across API and public CMS rendering', function () {
    $root = dirname(__DIR__, 2);
    $model = file_get_contents($root.'/app/Models/Website/CmsContentType.php');
    $controller = file_get_contents($root.'/app/Http/Controllers/Api/Website/CmsContentTypeController.php');
    $public = file_get_contents($root.'/app/Http/Controllers/Website/CmsController.php');
    $index = file_get_contents($root.'/resources/views/cms/index.blade.php');

    expect($model)
        ->toContain("'parent_id'")
        ->toContain('function parent()')
        ->toContain('function children()')
        ->and($controller)
        ->toContain("withCount(['contents', 'children'])")
        ->toContain('Move or delete child content types before deleting this parent.')
        ->and($public)
        ->toContain("whereIn('cms_content_type_id'")
        ->and($index)
        ->toContain('$contentType->children as $childType')
        ->toContain('$content->contentType->slug');
});
