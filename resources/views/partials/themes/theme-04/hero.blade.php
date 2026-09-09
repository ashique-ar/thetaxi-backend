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
                                <span class="t4-kicker">{{ $theme04HeroBrand }}</span>
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
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if (count($heroSlides) > 1)<div class="shared-hero-pagination" aria-label="Banner slides"></div>@endif
        </div>
    </div>
</section>
