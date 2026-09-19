@php
    $heroSlides = get_hero_slides($settings);
    $theme04HeroBrand = $settings['brand_short_name'] ?? $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
@endphp

<section class="t4-hero" aria-label="Homepage highlights">
    <div class="t4-hero__media shared-hero-slider">
        <div class="swiper shared-hero-swiper">
            <div class="swiper-wrapper">
                @foreach ($heroSlides as $index => $slide)
                    <div class="swiper-slide">
                        @include('partials.hero-media', compact('slide', 'index'))
                        <div class="container t4-hero__frame">
                            <div class="t4-hero__copy">
                                <span class="t4-kicker">{{ !empty($slide['eyebrow']) ? $slide['eyebrow'] : $theme04HeroBrand }}</span>
                                @if ($index === 0)
                                    <h1>{{ $slide['heading'] ?: 'All-in-one Travel Booking.' }}</h1>
                                @else
                                    <h2>{{ $slide['heading'] ?: 'All-in-one Travel Booking.' }}</h2>
                                @endif
                                @if ($slide['subheading'] !== '')
                                    <p>{{ $slide['subheading'] }}</p>
                                @endif
                                @if ($slide['caption'] !== '')
                                    <small>{{ $slide['caption'] }}</small>
                                @endif
                                @if (($slide['ctaText'] ?? 'Discover Our Services') !== '')
                                    <a class="t4-hero__action" href="{{ $slide['ctaUrl'] ?? '/services' }}">{{ $slide['ctaText'] ?? 'Discover Our Services' }} <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                @endif
                            </div>
                            @if (!empty($slide['locationLabel']))
                                <span class="t4-hero__location"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $slide['locationLabel'] }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if (count($heroSlides) > 1)
                <button class="t4-hero__prev" type="button" aria-label="Previous banner"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                <button class="t4-hero__next" type="button" aria-label="Next banner"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                <div class="shared-hero-pagination" aria-label="Banner slides"></div>
            @endif
        </div>
    </div>
</section>
