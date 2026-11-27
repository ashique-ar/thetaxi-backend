@php
    $heroSlides = get_hero_slides($settings);
    $slideCount = count($heroSlides);
    $firstSlide = $heroSlides[0] ?? null;
    $firstHighlight = $firstSlide ? $firstSlide['caption'] : '';
    $firstMeta = $firstSlide ? $firstSlide['locationLabel'] : '';
@endphp
<section class="t3-hero shared-hero-slider" aria-label="Homepage banner" aria-roledescription="carousel">
    <div class="swiper shared-hero-swiper">
        @if ($slideCount > 1)
            <button class="t3-hero__prev" type="button" data-hero-prev aria-label="Previous slide"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <button class="t3-hero__next" type="button" data-hero-next aria-label="Next slide"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        @endif
        <div class="swiper-wrapper">
            @foreach ($heroSlides as $index => $slide)
                <div class="swiper-slide"
                     role="group"
                     aria-roledescription="slide"
                     aria-label="{{ ($index + 1) . ' of ' . $slideCount }}"
                     data-slide-highlight="{{ $slide['caption'] }}"
                     data-slide-meta="{{ $slide['locationLabel'] }}">
                    <div class="t3-hero__media">
                        @include('partials.hero-media', compact('slide', 'index'))
                    </div>
                    <div class="t3-hero__frame">
                        <div class="t3-hero__copy">
                            @include('partials.hero-copy', ['slide' => $slide, 'index' => $index, 'hideBarMeta' => $slideCount > 1])
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="visually-hidden" role="status" aria-live="polite" data-hero-status></p>
        @if ($slideCount > 1)
            <div class="shared-hero-bar">
                <div class="shared-hero-bar__inner">
                    <div class="shared-hero-bar__highlight" data-hero-highlight-wrap @if ($firstHighlight === '') hidden @endif>
                        <span class="shared-hero-bar__dot" aria-hidden="true"></span>
                        <span class="shared-hero-bar__label">Slide Highlight:</span>
                        <span class="shared-hero-bar__text" data-hero-highlight>{{ $firstHighlight }}</span>
                    </div>
                    <div class="shared-hero-bar__meta" data-hero-meta-wrap @if ($firstMeta === '') hidden @endif>
                        <i class="bi bi-check2" aria-hidden="true"></i>
                        <span data-hero-meta>{{ $firstMeta }}</span>
                    </div>
                    <div class="shared-hero-bar__controls">
                        <button class="shared-hero-bar__arrow" type="button" data-hero-prev aria-label="Previous slide">
                            <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        </button>
                        <div class="shared-hero-pagination" aria-label="Banner slides"></div>
                        <button class="shared-hero-bar__arrow" type="button" data-hero-next aria-label="Next slide">
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</section>
