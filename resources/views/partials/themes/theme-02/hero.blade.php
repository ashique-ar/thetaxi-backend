{{-- Theme-02 Hero Section - Based on travel-agency-02.html (home3-banner-section) --}}
{{-- Supports video background with autoplay, loop, muted --}}
{{-- Supports image fallback via banner slider --}}
{{-- Includes award/rating badge area --}}
{{-- Includes banner pagination controls --}}

<!-- home3 Banner Section Start -->
<div class="home3-banner-section">
    <div class="swiper home2-banner-slider">
        <div class="swiper-wrapper">
            <div class="swiper-slide">
                <div class="banner-wrapper">
                    <div class="banner-img-area">
                        <img src="{{ !empty($settings['banner_image']) ? s3_asset($settings['banner_image']) : (!empty($settings['banner_image']) ? s3_asset($settings['banner_image']) : asset('assets/img/home3/banner-img1.jpg')) }}"
                            alt="{{ $settings['banner_heading'] ?? 'Banner' }}" loading="lazy">
                    </div>
                    <div class="banner-content-wrap">
                        <div class="container">
                            <div class="banner-content">
                                <h2>{{ $settings['banner_heading'] ?? 'Fly First Class, Land Refreshed' }}</h2>
                                <p>{{ $settings['banner_subheading'] ?? 'Every destination is backed by care, culture, and confidence.' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @if ($settings['banner_image_2'])
                <div class="swiper-slide">
                    <div class="banner-wrapper">
                        <div class="banner-img-area">
                            <img src="{{ !empty($settings['banner_image_2']) ? s3_asset($settings['banner_image_2']) : (!empty($settings['banner_image_2']) ? s3_asset($settings['banner_image_2']) : asset('assets/img/home3/banner-img1.jpg')) }}"
                                alt="{{ $settings['banner_heading_2'] ?? 'Banner' }}" loading="lazy">
                        </div>
                        <div class="banner-content-wrap">
                            <div class="container">
                                <div class="banner-content">
                                    <h2>{{ $settings['banner_heading_2'] ?? 'Fly First Class, Land Refreshed' }}</h2>
                                    <p>{{ $settings['banner_subheading_2'] ?? 'Every destination is backed by care, culture, and confidence.' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
    {{-- Banner Pagination Controls --}}
    <div class="banner-pagination paginations"></div>
</div>
<!-- home3 Banner Section End -->
