@php($heroSlides = get_hero_slides($settings))
<section class="t2-hero home3-banner-section relative overflow-hidden shared-hero-slider" aria-label="Homepage banner">
    <div class="swiper shared-hero-swiper home2-banner-slider">
        <div class="swiper-wrapper">
            @foreach ($heroSlides as $index => $slide)
                <div class="swiper-slide"><div class="banner-wrapper relative">
                    <div class="banner-img-area absolute inset-0">@include('partials.hero-media', compact('slide', 'index'))</div>
                    <div class="banner-content-wrap absolute inset-0 z-[2] flex items-center"><div class="container"><div class="banner-content max-w-2xl px-4 md:px-0">
                        <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-5 leading-[1.1]">{{ $settings['banner_heading'] ?? '' }}</h1>
                        <p class="text-base sm:text-lg md:text-xl text-white/90 leading-relaxed max-w-xl mb-8">{{ $settings['banner_subheading'] ?? '' }}</p>
                        @if (!empty($settings['banner_cta_text']))<a href="{{ $settings['banner_cta_url'] ?? '#booking' }}" class="inline-flex items-center gap-2 bg-[var(--primary-color1)] text-white px-8 py-4 rounded-xl font-bold text-base">{{ $settings['banner_cta_text'] }}</a>@endif
                    </div></div></div>
                </div></div>
            @endforeach
        </div>
        @if (count($heroSlides) > 1)<div class="shared-hero-pagination banner-pagination paginations" aria-label="Banner slides"></div>@endif
    </div>
</section>
