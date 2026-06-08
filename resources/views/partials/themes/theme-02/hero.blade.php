{{-- Theme-02 Hero Section - Premium cinematic banner --}}
@php
    $theme02Slides = [];
    $theme02SliderImages = $settings['theme_02_slider_images'] ?? null;

    if (!empty($theme02SliderImages)) {
        $decodedSlides = is_string($theme02SliderImages) ? json_decode($theme02SliderImages, true) : $theme02SliderImages;

        if (is_array($decodedSlides)) {
            foreach ($decodedSlides as $index => $slide) {
                $desktopImage = $slide['desktop'] ?? ($slide['image'] ?? null);
                $mobileImage = $slide['mobile'] ?? ($slide['mobile_image'] ?? null);

                if (!empty($desktopImage)) {
                    $theme02Slides[] = [
                        'desktop' => $desktopImage,
                        'mobile' => $mobileImage,
                        'alt' => trim(($settings['banner_heading'] ?? 'Banner') . ' slide ' . ($index + 1)),
                    ];
                }
            }
        }
    }

    if (empty($theme02Slides) && !empty($settings['banner_image'])) {
        $theme02Slides[] = [
            'desktop' => $settings['banner_image'],
            'mobile' => null,
            'alt' => $settings['banner_heading'] ?? 'Banner',
        ];
    }

    if (empty($theme02Slides)) {
        $theme02Slides[] = [
            'desktop' => 'assets/img/home3/banner-img1.jpg',
            'mobile' => null,
            'alt' => $settings['banner_heading'] ?? 'Banner',
        ];
    }
@endphp

<!-- Hero Banner Section Start -->
<div class="t2-hero home3-banner-section relative overflow-hidden">
    <div class="swiper home2-banner-slider">
        <div class="swiper-wrapper">
            @foreach ($theme02Slides as $slide)
                <div class="swiper-slide">
                    <div class="banner-wrapper relative">
                        <div class="banner-img-area absolute inset-0">
                            <picture>
                                @if (!empty($slide['mobile']))
                                    <source media="(max-width: 767px)" srcset="{{ s3_asset($slide['mobile']) }}">
                                @endif
                                <img src="{{ s3_asset($slide['desktop']) }}"
                                alt="{{ $slide['alt'] }}"
                                loading="lazy"
                                class="w-full h-full object-cover">
                            </picture>
                        </div>
                        <div class="banner-content-wrap absolute inset-0 z-[2] flex items-center">
                            <div class="container">
                                <div class="banner-content max-w-2xl px-4 md:px-0">
                                    <span class="t2-hero-badge">
                                        {{ $settings['banner_badge_text'] ?? 'Premium Transport Service' }}
                                    </span>
                                    <h2 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-5 leading-[1.1]">
                                        {{ $settings['banner_heading'] ?? '' }}
                                    </h2>
                                    <p class="text-base sm:text-lg md:text-xl text-white/90 leading-relaxed max-w-xl mb-8">
                                        {{ $settings['banner_subheading'] ?? '' }}
                                    </p>
                                    @if(!empty($settings['banner_cta_text']))
                                    <a href="{{ $settings['banner_cta_url'] ?? '#booking' }}"
                                       class="inline-flex items-center gap-2 bg-[var(--primary-color1)] text-white px-8 py-4 rounded-xl font-bold text-base hover:brightness-110 transition-all duration-300 shadow-lg hover:shadow-xl hover:-translate-y-0.5">
                                        {{ $settings['banner_cta_text'] }}
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                                    </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    {{-- Banner Pagination --}}
    <div class="banner-pagination paginations"></div>
</div>
<!-- Hero Banner Section End -->
