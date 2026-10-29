@php($heroSlides = get_hero_slides($settings))
<section class="t2-hero home3-banner-section relative overflow-hidden shared-hero-slider" aria-label="Homepage banner">
    <div class="swiper shared-hero-swiper home2-banner-slider">
        <div class="swiper-wrapper">
            @foreach ($heroSlides as $index => $slide)
                <div class="swiper-slide"><div class="banner-wrapper relative">
                    <div class="banner-img-area absolute inset-0">@include('partials.hero-media', compact('slide', 'index'))</div>
                    <div class="banner-content-wrap absolute inset-0 z-[2] flex items-center"><div class="container"><div class="banner-content max-w-2xl px-4 md:px-0">
                        @include('partials.hero-copy', compact('slide', 'index'))
                    </div></div></div>
                </div></div>
            @endforeach
        </div>
        @if (count($heroSlides) > 1)<div class="shared-hero-pagination banner-pagination paginations" aria-label="Banner slides"></div>@endif
    </div>
</section>
