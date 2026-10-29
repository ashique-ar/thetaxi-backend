@php
    $heroSlides = get_hero_slides($settings);
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
                                @include('partials.hero-copy', compact('slide', 'index'))
                            </div>
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
