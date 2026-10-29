@php($heroSlides = get_hero_slides($settings))
<section class="home4-banner-section shared-hero-slider" aria-label="Homepage banner">
    <div class="swiper shared-hero-swiper">
        <div class="swiper-wrapper">
            @foreach ($heroSlides as $index => $slide)
                <div class="swiper-slide banner-video-area">
                    @include('partials.hero-media', compact('slide', 'index'))
                    <div class="banner-content-wrap">
                        <div class="container banner-content">
                            <div>@include('partials.hero-copy', compact('slide', 'index'))</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @if (count($heroSlides) > 1)
        <div class="shared-hero-pagination" aria-label="Banner slides"></div>@endif
    </div>
</section>
