@php
    // inputs: $model (optional), $sections (optional array)
    $settings = $settings ?? (View::shared('settings') ?? []);

    // Resolve values with fallbacks: model -> cms -> site settings
    $pageTitle = '';
    $metaDescription = '';
    $metaKeywords = '';
    $ogImage = '';
    $canonical = request()->url();

    if (!empty($model)) {
        // Generic fields supported by Page/CmsContent/InquiryServicePage
        $pageTitle = $model->seo_title ?? ($model->meta_title ?? ($model->title ?? ($model->name ?? '')));
        $metaDescription = $model->seo_description ?? ($model->meta_description ?? ($model->excerpt ?? ''));
        $metaKeywords = $model->seo_keywords ?? ($model->meta_tags ?? ($model->meta_keywords ?? ''));

        // Try common image fields (prefer an explicit per-page OG image)
        $ogImage =
            $model->seo_og_image ?? ($model->featured_image ?? ($model->thumbnail ?? ($model->og_image ?? null)));

        // For CMS content, try to resolve URL via content type if available
        if (method_exists($model, 'contentType') && $model->relationLoaded('contentType')) {
            $contentTypeSlug = $model->contentType->slug ?? null;
            if ($contentTypeSlug) {
                $canonical = url("/{$contentTypeSlug}/{$model->slug}");
            }
        }

        // If model provides an explicit canonical_url, prefer it (accepts absolute or relative)
        if (!empty($model->canonical_url)) {
            $canonical = filter_var($model->canonical_url, FILTER_VALIDATE_URL)
                ? $model->canonical_url
                : url(ltrim($model->canonical_url, '/'));
        } else {
            // Inquiry pages use a /services/{slug} route
            if ($model instanceof \App\Models\InquiryServicePage) {
                $canonical = route('inquiry-services.show', $model->slug);
            }

            if ($model instanceof \App\Models\Vehicle\Vehicle) {
                // vehicle details route by id
                $canonical = route('vehicle.details', $model->id);
            }
        }
    }

    // Fall back to site settings
    if (!$pageTitle) {
        $pageTitle = $settings['site_name'] ?? config('app.name');
    }
    if (!$metaDescription) {
        $metaDescription = $settings['seo_meta_description'] ?? '';
    }
    if (!$metaKeywords) {
        $metaKeywords = $settings['seo_keywords'] ?? '';
    }
    if (!$ogImage) {
        $ogImage = $settings['seo_og_image'] ?? '';
    }

    // Support title templates with placeholders {page_title} and {year}
    $titleTemplate = $settings['seo_title_template'] ?? '';
    $computedTitle = $pageTitle;
    if ($titleTemplate) {
        $computedTitle = str_replace('{page_title}', $pageTitle, $titleTemplate);
        $computedTitle = str_replace('{year}', date('Y'), $computedTitle);
    }

    $ogImageUrl = '';
    if ($ogImage) {
        $ogImagePath = is_array($ogImage)
            ? ($ogImage['path'] ?? ($ogImage['url'] ?? ($ogImage[0] ?? null)))
            : (is_object($ogImage) ? ($ogImage->path ?? ($ogImage->url ?? null)) : $ogImage);

        if (is_string($ogImagePath) && $ogImagePath !== '') {
            $ogImageUrl = filter_var($ogImagePath, FILTER_VALIDATE_URL) ? $ogImagePath : s3_asset($ogImagePath);
        }
    }
@endphp

@if ($metaDescription)
    <meta name="description" content="{{ $metaDescription }}">
@endif
@if ($metaKeywords)
    <meta name="keywords" content="{{ $metaKeywords }}">
@endif
<link rel="canonical" href="{{ $canonical }}">

<!-- Open Graph -->
<meta property="og:title" content="{{ $computedTitle }}">
@if ($metaDescription)
    <meta property="og:description" content="{{ $metaDescription }}">
@endif
@if ($ogImageUrl)
    <meta property="og:image" content="{{ $ogImageUrl }}">
@endif
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:type" content="website">

<!-- Twitter -->
<meta name="twitter:card" content="{{ $settings['seo_twitter_card'] ?? 'summary' }}">
<meta name="twitter:title" content="{{ $computedTitle }}">
@if ($metaDescription)
    <meta name="twitter:description" content="{{ $metaDescription }}">
@endif
@if ($ogImageUrl)
    <meta name="twitter:image" content="{{ $ogImageUrl }}">
@endif

<!-- JSON-LD structured data -->
@php
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@graph' => [],
    ];

    // Organization schema
    $org = [
        '@type' => 'Organization',
        'name' => $settings['company_name'] ?? ($settings['site_name'] ?? config('app.name')),
        'url' => config('app.url'),
    ];
    if (!empty($settings['company_phone'])) {
        $org['telephone'] = $settings['company_phone'];
    }
    if (!empty($settings['company_email'])) {
        $org['email'] = $settings['company_email'];
    }
    if (!empty($settings['logo_header'])) {
        $org['logo'] = filter_var($settings['logo_header'], FILTER_VALIDATE_URL)
            ? $settings['logo_header']
            : s3_asset($settings['logo_header']);
    }
    $jsonLd['@graph'][] = $org;

    // If model is an InquiryServicePage add Service schema
    if (!empty($model) && $model instanceof \App\Models\InquiryServicePage) {
        $serviceSchema = [
            '@type' => 'Service',
            'name' => $model->name ?? $pageTitle,
            'description' => $model->seo_description ?? ($model->excerpt ?? $metaDescription),
            'url' => $canonical,
            'provider' => [
                '@type' => 'Organization',
                'name' => $settings['company_name'] ?? ($settings['site_name'] ?? config('app.name')),
                'url' => config('app.url'),
            ],
        ];
        $jsonLd['@graph'][] = $serviceSchema;

        // BreadcrumbList
        $breadcrumb = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Services',
                    'item' => route('inquiry-services.show', 'corporate-transfers'),
                ],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $model->name ?? $pageTitle, 'item' => $canonical],
            ],
        ];
        $jsonLd['@graph'][] = $breadcrumb;

        // FAQ schema from sections if available
        if (!empty($sections) && is_array($sections)) {
            foreach ($sections as $section) {
                if (($section['type'] ?? '') === 'faq' && !empty($section['items']) && is_array($section['items'])) {
                    $faqEntries = [];
                    foreach ($section['items'] as $item) {
                        $question = $item['question'] ?? null;
                        $answer = $item['answer'] ?? null;
                        if ($question && $answer) {
                            $faqEntries[] = [
                                '@type' => 'Question',
                                'name' => $question,
                                'acceptedAnswer' => [
                                    '@type' => 'Answer',
                                    'text' => strip_tags($answer),
                                ],
                            ];
                        }
                    }

                    if (!empty($faqEntries)) {
                        $jsonLd['@graph'][] = [
                            '@type' => 'FAQPage',
                            'mainEntity' => $faqEntries,
                        ];
                    }
                    break;
                }
            }
        }
    }

    $jsonLdOutput = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@endphp

<script type="application/ld+json">{!! $jsonLdOutput !!}</script>
