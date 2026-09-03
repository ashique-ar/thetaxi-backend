@php($heroSlides = get_hero_slides($settings))
<section class="home4-banner-section shared-hero-slider" aria-label="Homepage banner">
    <div class="swiper shared-hero-swiper">
        <div class="swiper-wrapper">
            @foreach ($heroSlides as $index => $slide)
                <div class="swiper-slide banner-video-area">@include('partials.hero-media', compact('slide', 'index'))</div>
            @endforeach
        </div>
        @if (count($heroSlides) > 1)<div class="shared-hero-pagination" aria-label="Banner slides"></div>@endif
    </div>
    <div class="banner-content-wrap"><div class="container"><div class="banner-content">
        <h1>{{ $settings['banner_heading'] ?? 'All-in-one Travel Booking.' }}</h1>
        <p>{{ $settings['banner_subheading'] ?? 'Best travel agency in world-wide & achieve "World Travel Award"' }}</p>
    </div></div></div>
</section>
