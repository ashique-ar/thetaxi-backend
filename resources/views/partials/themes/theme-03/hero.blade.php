@php
    $theme03HeroHeading = $settings['banner_heading'] ?? 'All-in-one Travel Booking.';
    $theme03HeroSubheading = $settings['banner_subheading'] ?? 'Best travel agency in world-wide & achieve "World Travel Award"';
    $heroSlides = get_hero_slides($settings);
    $theme03HeroBrand = $settings['brand_short_name'] ?? $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
    $theme03HeroCaption = $settings['site_tagline'] ?? $settings['brand_tagline'] ?? null;
@endphp

<section class="t3-hero" aria-labelledby="t3-hero-title">
    <div class="t3-hero__frame">
        <div class="t3-hero__copy">
            <span class="t3-kicker">{{ $theme03HeroBrand }}</span>
            <h1 id="t3-hero-title">{{ $theme03HeroHeading }}</h1>
            @if ($theme03HeroSubheading !== '')
                <p>{{ $theme03HeroSubheading }}</p>
            @endif

            <div class="t3-hero__folio" aria-hidden="true">
                <span>01</span>
                <i></i>
            </div>
        </div>

        <figure class="t3-hero__visual">
            <div class="t3-hero__image-frame shared-hero-slider">
                <div class="swiper shared-hero-swiper">
                    <div class="swiper-wrapper">
                        @foreach ($heroSlides as $index => $slide)
                            <div class="swiper-slide">@include('partials.hero-media', compact('slide', 'index'))</div>
                        @endforeach
                    </div>
                    @if (count($heroSlides) > 1)<div class="shared-hero-pagination" aria-label="Banner slides"></div>@endif
                </div>
            </div>
            @if (!empty($theme03HeroCaption))
                <figcaption>{{ $theme03HeroCaption }}</figcaption>
            @endif
            <span class="t3-hero__index" aria-hidden="true">03</span>
        </figure>
    </div>
</section>
