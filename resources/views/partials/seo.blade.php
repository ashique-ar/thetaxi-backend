@php
    // Inputs: $model (optional), $sections (optional array), $pageTitle (optional),
    // $managedSeo (optional). Model/CMS metadata remains owned by its editor.
    $settings = $settings ?? (View::shared('settings') ?? []);
    $managedSeo = (bool) ($managedSeo ?? !empty($model));
    $pageTitle = trim((string) ($pageTitle ?? $__env->yieldContent('title')));
    $metaDescription = $metaDescription ?? '';
    $metaKeywords = $metaKeywords ?? '';
    $ogImage = $ogImage ?? '';
    $canonical = request()->url();

    if (!empty($model)) {
        $pageTitle = $model->seo_title ?: ($model->meta_title ?: ($model->title ?: ($model->name ?: $pageTitle)));
        $metaDescription = $model->seo_description ?: ($model->meta_description ?: ($model->excerpt ?: ''));
        $metaKeywords = $model->seo_keywords ?: ($model->meta_tags ?: ($model->meta_keywords ?: ''));
        $ogImage = $model->seo_og_image ?: ($model->featured_image ?: ($model->thumbnail ?: ($model->og_image ?: null)));

        if (method_exists($model, 'contentType') && $model->relationLoaded('contentType')) {
            $contentTypeSlug = $model->contentType->slug ?? null;
            if ($contentTypeSlug) {
                $canonical = url("/{$contentTypeSlug}/{$model->slug}");
            }
        }

        if (!empty($model->canonical_url)) {
            $canonical = filter_var($model->canonical_url, FILTER_VALIDATE_URL)
                ? $model->canonical_url
                : url(ltrim($model->canonical_url, '/'));
        } elseif ($model instanceof \App\Models\InquiryServicePage) {
            $canonical = route('inquiry-services.show', $model->slug);
        } elseif ($model instanceof \App\Models\Vehicle\Vehicle) {
            $canonical = route('vehicle.details', $model->id);
        }
    }

    $siteName = $settings['site_name'] ?? ($settings['brand_name'] ?? config('app.name'));
    $companyName = $settings['company_name'] ?? $siteName;
    $pageTitle = $pageTitle ?: $siteName;
    if (!$managedSeo) {
        $metaDescription = $metaDescription ?: ($settings['seo_meta_description'] ?? '');
        $metaKeywords = $metaKeywords ?: ($settings['seo_keywords'] ?? '');
        $ogImage = $ogImage ?: ($settings['seo_og_image'] ?? '');
    }

    $replaceSeoTokens = static function ($value) use ($pageTitle, $siteName, $companyName, $settings) {
        return strtr((string) $value, [
            '{page_title}' => $pageTitle,
            '{site_name}' => $siteName,
            '{company_name}' => $companyName,
            '{tagline}' => $settings['site_tagline'] ?? ($settings['brand_tagline'] ?? ''),
            '{year}' => date('Y'),
        ]);
    };

    $titleTemplate = $settings['seo_title_template'] ?? '';
    $computedTitle = $pageTitle;
    if (!$managedSeo && $titleTemplate) {
        $computedTitle = $replaceSeoTokens($titleTemplate);
    }
    if (!$managedSeo && !empty($settings['seo_title_custom'])) {
        $computedTitle = $replaceSeoTokens($settings['seo_title_custom']);
    }
    $metaDescription = $replaceSeoTokens($metaDescription);

    $ogImageUrl = '';
    if ($ogImage) {
        $ogImagePath = is_array($ogImage)
            ? ($ogImage['path'] ?? ($ogImage['url'] ?? ($ogImage[0] ?? null)))
            : (is_object($ogImage) ? ($ogImage->path ?? ($ogImage->url ?? null)) : $ogImage);
        if (is_string($ogImagePath) && $ogImagePath !== '') {
            $ogImageUrl = filter_var($ogImagePath, FILTER_VALIDATE_URL) ? $ogImagePath : s3_asset($ogImagePath);
        }
    }

    $enabled = static fn ($value, bool $default = true): bool => filter_var($value ?? $default, FILTER_VALIDATE_BOOLEAN);
    $canonicalEnabled = $enabled($settings['seo_canonical_enabled'] ?? true);
    $schemaEnabled = $enabled($settings['seo_schema_enabled'] ?? true);
    $robots = $settings['seo_robots'] ?? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $locale = $settings['seo_default_locale'] ?? 'en_LK';
    $organizationSummary = $replaceSeoTokens($settings['seo_ai_summary'] ?? '');
    $topicSource = $managedSeo ? $metaKeywords : ($settings['seo_ai_topics'] ?? $metaKeywords);
    $topics = array_values(array_filter(array_map('trim', explode(',', (string) $topicSource))));
@endphp

@if ($metaDescription)<meta name="description" content="{{ $metaDescription }}">@endif
@if ($metaKeywords)<meta name="keywords" content="{{ $metaKeywords }}">@endif
<meta name="robots" content="{{ $robots }}">
@if ($canonicalEnabled)<link rel="canonical" href="{{ $canonical }}">@endif
@if (!empty($settings['google_site_verification']))
    <meta name="google-site-verification" content="{{ preg_replace('/^google-site-verification=/', '', $settings['google_site_verification']) }}">
@endif
@if (!empty($settings['meta_verification_code']))
    <meta name="facebook-domain-verification" content="{{ $settings['meta_verification_code'] }}">
@endif

<meta property="og:title" content="{{ $computedTitle }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:type" content="website">
<meta property="og:locale" content="{{ $locale }}">
@if ($metaDescription)<meta property="og:description" content="{{ $metaDescription }}">@endif
@if ($ogImageUrl)
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta property="og:image:alt" content="{{ $computedTitle }}">
@endif

<meta name="twitter:card" content="{{ $settings['seo_twitter_card'] ?? 'summary_large_image' }}">
<meta name="twitter:title" content="{{ $computedTitle }}">
@if ($metaDescription)<meta name="twitter:description" content="{{ $metaDescription }}">@endif
@if ($ogImageUrl)<meta name="twitter:image" content="{{ $ogImageUrl }}">@endif

@if ($schemaEnabled)
    @php
        $allowedBusinessTypes = ['Organization', 'LocalBusiness', 'TaxiService', 'TravelAgency', 'AutomotiveBusiness'];
        $businessType = in_array($settings['seo_business_type'] ?? '', $allowedBusinessTypes, true)
            ? $settings['seo_business_type']
            : 'TaxiService';
        $siteUrl = url('/');
        $organizationId = $siteUrl . '#organization';
        $websiteId = $siteUrl . '#website';
        $organization = array_filter([
            '@type' => $businessType,
            '@id' => $organizationId,
            'name' => $companyName,
            'url' => $siteUrl,
            'description' => $organizationSummary ?: null,
            'telephone' => $settings['company_phone'] ?? null,
            'email' => $settings['company_email'] ?? null,
            'address' => $settings['company_address'] ?? null,
            'areaServed' => $settings['seo_service_area'] ?? null,
            'priceRange' => $settings['seo_price_range'] ?? null,
            'logo' => !empty($settings['logo_header'])
                ? (filter_var($settings['logo_header'], FILTER_VALIDATE_URL) ? $settings['logo_header'] : s3_asset($settings['logo_header']))
                : null,
            'sameAs' => array_values(array_filter([
                $settings['social_facebook'] ?? null,
                $settings['social_twitter'] ?? null,
                $settings['social_instagram'] ?? null,
                $settings['social_linkedin'] ?? null,
                $settings['social_youtube'] ?? null,
                $settings['social_tiktok'] ?? null,
            ], static fn ($url) => filter_var($url, FILTER_VALIDATE_URL))),
            'knowsAbout' => $topics ?: null,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@graph' => [
                $organization,
                array_filter([
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'url' => $siteUrl,
                    'name' => $siteName,
                    'description' => $metaDescription,
                    'abstract' => $organizationSummary ?: null,
                    'inLanguage' => str_replace('_', '-', $locale),
                    'publisher' => ['@id' => $organizationId],
                    'keywords' => $topics ? implode(', ', $topics) : null,
                ], static fn ($value) => $value !== null && $value !== ''),
                array_filter([
                    '@type' => 'WebPage',
                    '@id' => $canonical . '#webpage',
                    'url' => $canonical,
                    'name' => $computedTitle,
                    'description' => $metaDescription,
                    'isPartOf' => ['@id' => $websiteId],
                    'about' => ['@id' => $organizationId],
                    'inLanguage' => str_replace('_', '-', $locale),
                ], static fn ($value) => $value !== null && $value !== ''),
            ],
        ];

        if (!empty($model) && $model instanceof \App\Models\InquiryServicePage) {
            $jsonLd['@graph'][] = [
                '@type' => 'Service',
                'name' => $model->name ?? $pageTitle,
                'description' => $model->seo_description ?? ($model->excerpt ?? $metaDescription),
                'url' => $canonical,
                'areaServed' => $settings['seo_service_area'] ?? null,
                'provider' => ['@id' => $organizationId],
            ];
            $jsonLd['@graph'][] = [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Services', 'item' => route('cms.index', 'services')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $model->name ?? $pageTitle, 'item' => $canonical],
                ],
            ];

            if (!empty($sections) && is_array($sections)) {
                foreach ($sections as $section) {
                    if (($section['type'] ?? '') !== 'faq' || empty($section['items']) || !is_array($section['items'])) continue;
                    $faqEntries = [];
                    foreach ($section['items'] as $item) {
                        if (!empty($item['question']) && !empty($item['answer'])) {
                            $faqEntries[] = [
                                '@type' => 'Question',
                                'name' => $item['question'],
                                'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($item['answer'])],
                            ];
                        }
                    }
                    if ($faqEntries) $jsonLd['@graph'][] = ['@type' => 'FAQPage', 'mainEntity' => $faqEntries];
                    break;
                }
            }
        }

        if (!empty($model) && $model instanceof \App\Models\Website\CmsContent) {
            $jsonLd['@graph'][] = array_filter([
                '@type' => 'Article',
                'headline' => $model->title ?? $pageTitle,
                'description' => $metaDescription,
                'url' => $canonical,
                'image' => $ogImageUrl ?: null,
                'datePublished' => optional($model->published_at)->toIso8601String(),
                'dateModified' => optional($model->updated_at)->toIso8601String(),
                'author' => !empty($model->author)
                    ? ['@type' => 'Person', 'name' => $model->author]
                    : ['@id' => $organizationId],
                'publisher' => ['@id' => $organizationId],
                'mainEntityOfPage' => ['@id' => $canonical . '#webpage'],
            ], static fn ($value) => $value !== null && $value !== '');
        }
    @endphp
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
