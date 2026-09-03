@php
    $theme04HeroHeading = $settings['banner_heading'] ?? 'All-in-one Travel Booking.';
    $theme04HeroSubheading = $settings['banner_subheading'] ?? 'Best travel agency in world-wide & achieve "World Travel Award"';
    $heroSlides = get_hero_slides($settings);
    $theme04HeroBrand = $settings['brand_short_name'] ?? $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
    $theme04HeroCaption = $settings['site_tagline'] ?? $settings['brand_tagline'] ?? null;
@endphp

<section class="t4-hero" aria-labelledby="t4-hero-title">
    <div class="t4-hero__media shared-hero-slider">
        <div class="swiper shared-hero-swiper">
            <div class="swiper-wrapper">
                @foreach ($heroSlides as $index => $slide)
                    <div class="swiper-slide">@include('partials.hero-media', compact('slide', 'index'))</div>
                @endforeach
            </div>
            @if (count($heroSlides) > 1)<div class="shared-hero-pagination" aria-label="Banner slides"></div>@endif
        </div>
    </div>
    <div class="container t4-hero__frame">
        <div class="t4-hero__copy">
            <span class="t4-kicker">{{ $theme04HeroBrand }}</span>
            <h1 id="t4-hero-title">{{ $theme04HeroHeading }}</h1>
            @if ($theme04HeroSubheading !== '')
                <p>{{ $theme04HeroSubheading }}</p>
            @endif
            @if (!empty($theme04HeroCaption))
                <small>{{ $theme04HeroCaption }}</small>
            @endif
        </div>
    </div>
</section>
