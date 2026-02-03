{{-- Default Hero Section - Based on travel-agency-03.html (home4-banner-section) --}}
{{-- This is the default theme hero section --}}

<!-- home4 Banner Section Start-->
<div class="home4-banner-section">
    <div class="banner-video-area">
        <img src="{{ $settings['banner_image'] ? s3_asset($settings['banner_image']) : asset('assets/img/home4/home4-banner-img.jpg') }}"
            alt="" loading="lazy">
        {{-- <video autoplay loop muted playsinline preload="metadata"
                src="{{ s3_asset($settings['banner_video'] ?? 'assets/video/home4-banner-video.mp4') }}"></video> --}}
    </div>
    <div class="banner-content-wrap">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $settings['banner_heading'] ?? 'All-in-one Travel Booking.' }}</h1>
                <p>{{ $settings['banner_subheading'] ?? 'Best travel agency in world-wide & achieve "World Travel Award"' }}</p>
            </div>
        </div>
    </div>
</div>
<!-- home4 Banner Section End-->
