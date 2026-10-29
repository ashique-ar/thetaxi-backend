@php($heroSlides = get_hero_slides($settings))
<section class="t3-hero shared-hero-slider" aria-label="Homepage banner">
    <div class="swiper shared-hero-swiper">
        <div class="swiper-wrapper">
            @foreach ($heroSlides as $index => $slide)
                <div class="swiper-slide">
                    <div class="t3-hero__frame">
                        <div class="t3-hero__copy">
                            @include('partials.hero-copy', compact('slide', 'index'))
                        </div>
                        <figure class="t3-hero__visual">
                            <div class="t3-hero__image-frame">
                                @include('partials.hero-media', compact('slide', 'index'))
                            </div>
                            <span class="t3-hero__index" aria-hidden="true">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span>
                        </figure>
                    </div>
                </div>
            @endforeach
        </div>
        @if (count($heroSlides) > 1)<div class="shared-hero-pagination" aria-label="Banner slides"></div>@endif
    </div>
</section>
