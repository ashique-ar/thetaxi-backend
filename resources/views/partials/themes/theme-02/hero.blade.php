{{-- Theme-02 Hero Section - Tailwind CSS - Full viewport cinematic banner --}}

<!-- Hero Banner Section Start -->
<div class="t2-hero home3-banner-section relative overflow-hidden">
    <div class="swiper home2-banner-slider">
        <div class="swiper-wrapper">
            <div class="swiper-slide">
                <div class="banner-wrapper relative min-h-screen">
                    <div class="banner-img-area absolute inset-0">
                        <img src="{{ !empty($settings['banner_image']) ? s3_asset($settings['banner_image']) : asset('assets/img/home3/banner-img1.jpg') }}"
                            alt="{{ $settings['banner_heading'] ?? 'Banner' }}"
                            loading="lazy"
                            class="w-full h-full object-cover">
                    </div>
                    <div class="banner-content-wrap absolute inset-0 z-[2] flex items-center">
                        <div class="container">
                            <div class="banner-content max-w-2xl px-4 md:px-0">
                                <span class="inline-block bg-white/10 backdrop-blur-sm text-white text-sm font-semibold px-4 py-2 rounded-full mb-6 border border-white/20">
                                    {{ $settings['banner_badge_text'] ?? '✨ Premium Transport Service' }}
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
            @if (isset($settings['banner_image_2']) && !empty($settings['banner_image_2']))
                <div class="swiper-slide">
                    <div class="banner-wrapper relative min-h-screen">
                        <div class="banner-img-area absolute inset-0">
                            <img src="{{ s3_asset($settings['banner_image_2']) }}"
                                alt="{{ $settings['banner_heading_2'] ?? 'Banner' }}"
                                loading="lazy"
                                class="w-full h-full object-cover">
                        </div>
                        <div class="banner-content-wrap absolute inset-0 z-[2] flex items-center">
                            <div class="container">
                                <div class="banner-content max-w-2xl px-4 md:px-0">
                                    <span class="inline-block bg-white/10 backdrop-blur-sm text-white text-sm font-semibold px-4 py-2 rounded-full mb-6 border border-white/20">
                                        {{ $settings['banner_badge_text_2'] ?? '🌍 Explore With Confidence' }}
                                    </span>
                                    <h2 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-5 leading-[1.1]">
                                        {{ $settings['banner_heading_2'] ?? 'Fly First Class, Land Refreshed' }}
                                    </h2>
                                    <p class="text-base sm:text-lg md:text-xl text-white/90 leading-relaxed max-w-xl">
                                        {{ $settings['banner_subheading_2'] ?? 'Every destination is backed by care, culture, and confidence.' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
    {{-- Banner Pagination --}}
    <div class="banner-pagination paginations"></div>
</div>
<!-- Hero Banner Section End -->
