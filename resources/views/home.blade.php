@extends('layouts.app')
@push('meta')
@include('partials.seo')
@endpush
@push('styles')
<link rel="stylesheet" href="{{ assetVersion('assets/css/homepage-vehicle-sections.css') }}">
<link rel="stylesheet" href="{{ assetVersion('assets/css/homepage-cms-sections.css') }}">
@endpush

@section('content')
<!-- Popup Page Identifier for Popup Display Engine -->
<div data-popup-page="homepage"></div>

{{-- Theme-aware Hero Section --}}
@include(theme_partial('hero'))

<!-- Booking Form Section (Separate from Hero) -->
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.booking-form')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.booking-form')
@else
    <div class="home-booking-form-section mb-5" id="home-booking">
        <div class="container">
            @include('components.booking-form')
        </div>
    </div>
@endif
<!-- End Booking Form Section -->

@if (isset($partners) && $partners->count() > 0)
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.partner-register')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.partner-register')
@else
<!-- home4 partner area Section Start-->
<div class="partner-section mb-100">
    <div class="container">
        <div class="partner-title wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
            <h5>{{ $settings['partner_section_title'] ?? 'Those Company You Can Easily Trust!' }}</h5>
        </div>
        <div class="partner-wrap">
            <div class="marquee">
                <div class="marquee__group">

                    @foreach ($partners as $partner)
                    <a href="{{ $partner->link ?? '#' }}">
                        <img src="{{ $partner->image ? s3_asset($partner->image) : s3_asset($settings['partner_logo_1']) }}"
                            alt="{{ $partner->title }}" loading="lazy">
                    </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endif
@endif
<!-- home4 partner area Section End-->

@if ($cmsSections['managed'])
    @foreach ($cmsSections['sections'] as $section)
        @include('partials.homepage-cms-section', ['section' => $section, 'sectionIndex' => $loop->index])
    @endforeach
@else
@if ($inspirations->count() > 0)
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.services')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.services')
@else
<x-cms-section :title="$settings['inspirations_section_title'] ?? 'Our Services'" :description="$settings['inspirations_section_description'] ??
            'Professional transportation and travel services designed to meet your unique needs'" :items="$inspirations" type="services" :showPrice="true"
    :showDuration="false" :showRating="false" viewAllText="View All Services" sectionId="services-section"
    :limit="6" customTemplate="blog-card2" />
@endif
@endif

@if ($destinations->count() > 0)
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.destinations')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.destinations')
@else
<x-cms-section :title="$settings['destinations_section_title'] ?? 'Top Destinations'" :description="$settings['destinations_section_description'] ??
            'Discover the most spectacular destinations Sri Lanka has to offer'" :items="$destinations" type="taxi" :showPrice="false"
    :showDuration="false" :showRating="true" viewAllText="View All Destinations" sectionId="destinations-section"
    :limit="6" />
@endif
@endif

@if ($packages->count() > 0)
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.packages')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.packages')
@else
<x-cms-section :title="$settings['packages_section_title'] ?? 'Things to Do'" :description="$settings['packages_section_description'] ??
            'Discover exciting activities and experiences Sri Lanka has to offer'" :items="$packages" type="things-to-do" :showPrice="true"
    :showDuration="true" :showRating="true" viewAllText="View All Activities" sectionId="things-to-do-section"
    :limit="6" />
@endif
@endif

@endif

@foreach ($vehicleSections as $section)
    @include('partials.homepage-vehicle-section', ['section' => $section, 'sectionIndex' => $loop->index])
@endforeach
@if (count($vehicleSections))
<script>
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-vehicle-category]');
    if (!button) return;
    const section = button.closest('[data-vehicle-section]');
    section.querySelectorAll('[data-vehicle-category]').forEach(function (filter) {
        const active = filter === button;
        filter.classList.toggle('is-active', active);
        filter.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    section.querySelectorAll('[data-category]').forEach(function (card) {
        card.hidden = !!button.dataset.vehicleCategory && card.dataset.category !== button.dataset.vehicleCategory;
    });
});
</script>
@endif

@if ($settings['offer_slider_img_1'])
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.offer-slider')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.offer-slider')
@else
<div class="home4-offer-slider-section mb-100">
    <div class="container">
        <div class="row mb-40">
            <div class="col-lg-12">
                <div class="swiper home4-offer-slider">
                    <div class="swiper-wrapper">
                        <div class="swiper-slide">
                            <a href="{{ $settings['offer_slider_link_1'] ?? route('booking.search') }}"><img
                                    src="{{ s3_asset($settings['offer_slider_img_1'] ?? 'assets/img/home4/home4-offer-slider-img1.jpg') }}"
                                    alt=""></a>
                        </div>
                        <div class="swiper-slide">
                            <a href="{{ $settings['offer_slider_link_2'] ?? route('booking.search') }}"><img
                                    src="{{ s3_asset($settings['offer_slider_img_2'] ?? 'assets/img/home4/home4-offer-slider-img2.jpg') }}"
                                    alt=""></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-lg-12 d-flex justify-content-center">
                <div class="swiper-pagination2 paginations two"></div>
            </div>
        </div>
    </div>
</div>
@endif
@endif
<!-- home4 Offer Slider Section Start-->

<!-- home4 Offer Slider Section End-->

@if ($settings['why_video_image'])
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.why-choose-us')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.why-choose-us')
@else
<!-- home4 Why Choose Us Section Start-->
<div class="home4-why-choose-us-section">
    <div class="container">
        <div class="row g-4 justify-content-between align-items-end mb-60 wow animate fadeInDown"
            data-wow-delay="200ms" data-wow-duration="1500ms">
            <div class="col-xxl-5 col-xl-6 col-lg-7">
                <div class="section-title">
                    <h2>{{ $settings['why_section_title'] ?? 'Why Choose Us' }}</h2>
                    <p>{{ $settings['offer_section_description'] ?? 'A curated list of the most popular travel packages based on different destinations.' }}
                    </p>
                </div>
            </div>
            <div class="col-lg-3 d-flex justify-content-lg-end">
                <a href="https://www.tripadvisor.com/" class="single-rating">
                    <strong>4.5</strong>
                    <div class="tripadvisor-rating">
                        <img src="{{ s3_asset($settings['tripadvisor_logo'] ?? 'assets/img/home1/icon/tripadvisor-logo.svg') }}"
                            alt="">
                        <div class="rating-area">
                            <span>Reviews</span>
                            <img src="{{ s3_asset($settings['tripadvisor_stars'] ?? 'assets/img/home1/icon/tripadvisor-start.svg') }}"
                                alt="">
                        </div>
                    </div>
                </a>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-3 col-md-4 col-sm-6 wow animate fadeInUp" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="single-feature">
                    <div class="icon">
                        <img src="{{ s3_asset($settings['why_feature_icon_1'] ?? 'assets/img/home3/icon/destination-feature-icon1.svg') }}"
                            alt="">
                    </div>
                    <h5>{{ $settings['why_feature_1'] ?? 'Customizable Package.' }}</h5>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6 wow animate fadeInUp" data-wow-delay="400ms"
                data-wow-duration="1500ms">
                <div class="single-feature">
                    <div class="icon">
                        <img src="{{ s3_asset($settings['why_feature_icon_2'] ?? 'assets/img/home3/icon/destination-feature-icon2.svg') }}"
                            alt="">
                    </div>
                    <h5>{{ $settings['why_feature_2'] ?? '24/7 Support' }}</h5>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6 wow animate fadeInUp" data-wow-delay="600ms"
                data-wow-duration="1500ms">
                <div class="single-feature">
                    <div class="icon">
                        <img src="{{ s3_asset($settings['why_feature_icon_3'] ?? 'assets/img/home3/icon/destination-feature-icon3.svg') }}"
                            alt="">
                    </div>
                    <h5>{{ $settings['why_feature_3'] ?? 'Trusted by Thousands' }}</h5>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6 wow animate fadeInUp" data-wow-delay="800ms"
                data-wow-duration="1500ms">
                <div class="single-feature">
                    <div class="icon">
                        <img src="{{ s3_asset($settings['why_feature_icon_4'] ?? 'assets/img/home3/icon/destination-feature-icon4.svg') }}"
                            alt="">
                    </div>
                    <h5>{{ $settings['why_feature_4'] ?? 'Local Expertise' }}</h5>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="why-choose-video-area mb-100">
    <div class="container">
        <div class="why-choose-video-wrap">
            <img src="{{ $settings['why_video_image'] ? s3_asset($settings['why_video_image']) : asset('assets/img/home4/why-choose-video-img.jpg') }}"
                alt="" loading="lazy">
            <a data-fancybox="video-player" href="https://www.youtube.com/watch?v=u31qwQUeGuM" class="play-btn">
                <i class="bi bi-play-fill"></i>
                <div class="waves-block">
                    <div class="waves wave-1"></div>
                    <div class="waves wave-2"></div>
                    <div class="waves wave-3"></div>
                </div>
            </a>
            <div class="contact-wrap">
                <div class="contact-area">
                    <h6>{{ $settings['why_help_text'] ?? 'Need help? Our transport team is ready to assist.' }}</h6>
                    <div class="single-contact">
                        <div class="icon">
                            <svg width="16" height="16" viewBox="0 0 16 16"
                                xmlns="http://www.w3.org/2000/svg">
                                <g>
                                    <path
                                        d="M15.5646 11.7424L13.3317 9.50954C12.5343 8.7121 11.1786 9.03111 10.8596 10.0678C10.6204 10.7855 9.82296 11.1842 9.10526 11.0247C7.51037 10.626 5.35726 8.55261 4.95854 6.87797C4.71931 6.16024 5.19778 5.36279 5.91548 5.12359C6.95216 4.80461 7.27113 3.44895 6.47369 2.65151L4.24084 0.418659C3.60288 -0.139553 2.64595 -0.139553 2.08774 0.418659L0.572591 1.93381C-0.942555 3.5287 0.73208 7.75516 4.48007 11.5032C8.22807 15.2512 12.4545 17.0056 14.0494 15.4106L15.5646 13.8955C16.1228 13.2575 16.1228 12.3006 15.5646 11.7424Z" />
                                </g>
                            </svg>
                        </div>
                        <div class="content">
                            <span>{{ $settings['header_help_label'] ?? 'Need Help?' }}</span>
                            <a
                                href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '') }}">{{ $settings['company_phone'] ?? 'Call us' }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- home4 Why Choose Us Section End-->
@endif
@endif
<!-- home4 Testimonial Section Start-->
@if ($testimonials && $testimonials->count() > 0)
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.testimonials')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.testimonials')
@else
<div class="home4-testimonial-section mb-100">
    <div class="container">
        <div class="testimonial-wrap">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-6 col-lg-8">
                    <div class="section-title text-center">
                        <h2>{{ $settings['testimonials_section_title'] ?? 'Hear It from Travelers' }}</h2>
                        <p>{{ $settings['testimonials_section_description'] ?? 'We go beyond just booking trips—we create unforgettable travel experiences that match your dreams!' }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="testimonial-slider-area mb-40">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="swiper home4-testimonial-slider">
                            <div class="swiper-wrapper">

                                @foreach ($testimonials as $testimonial)
                                <div class="swiper-slide">
                                    <div class="testimonial-card five">
                                        <ul class="rating-area">
                                            @for ($i = 1; $i <= 5; $i++)
                                                @if ($i <=$testimonial->rating)
                                                <li><i class="bi bi-circle-fill"></i></li>
                                                @else
                                                <li><i class="bi bi-circle-half"></i></li>
                                                @endif
                                                @endfor
                                        </ul>
                                        <h5>{{ $testimonial->position ?? 'Customer Review' }}</h5>
                                        <p>{{ $testimonial->content }}</p>
                                        <div class="author-area">
                                            <div class="author-info">
                                                <h5>{{ $testimonial->name }}</h5>
                                                <span>{{ $testimonial->company ?? $testimonial->location }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @endforeach


                            </div>
                        </div>
                    </div>
                </div>
                <div class="slider-btn-grp">
                    <div class="slider-btn testimonial-slider-prev">
                        <svg width="14" height="14" viewBox="0 0 14 14"
                            xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M11.002 13.0005C10.002 10.5005 5.00195 8.00049 2.00195 7.00049C5.00195 6.00049 9.50195 4.50049 11.002 1.00049"
                                    stroke-width="1.5" stroke-linecap="round" />
                            </g>
                        </svg>
                    </div>
                    <div class="slider-btn testimonial-slider-next">
                        <svg width="14" height="14" viewBox="0 0 14 14"
                            xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M2.99805 13.0005C3.99805 10.5005 8.99805 8.00049 11.998 7.00049C8.99805 6.00049 4.49805 4.50049 2.99805 1.00049"
                                    stroke-width="1.5" stroke-linecap="round" />
                            </g>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="swiper home4-testimonial-img-slider">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <div class="testimonial-author-img">
                                    <img src="{{ s3_asset($settings['testimonial_author_img_1'] ?? 'assets/img/home4/testimonial-author-img1.png') }}"
                                        alt="">
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testimonial-author-img">
                                    <img src="{{ s3_asset($settings['testimonial_author_img_2'] ?? 'assets/img/home4/testimonial-author-img2.png') }}"
                                        alt="">
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testimonial-author-img">
                                    <img src="{{ s3_asset($settings['testimonial_author_img_3'] ?? 'assets/img/home4/testimonial-author-img3.png') }}"
                                        alt="">
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testimonial-author-img">
                                    <img src="{{ s3_asset($settings['testimonial_author_img_4'] ?? 'assets/img/home4/testimonial-author-img4.png') }}"
                                        alt="">
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testimonial-author-img">
                                    <img src="{{ s3_asset($settings['testimonial_author_img_5'] ?? 'assets/img/home4/testimonial-author-img5.png') }}"
                                        alt="">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <img src="{{ s3_asset($settings['testimonial_vector'] ?? 'assets/img/home4/vector/home4-testimonial-vector.png') }}"
        alt="" class="vector" loading="lazy">
</div>
@endif
@endif
<!-- home4 Testimonial Section End-->
@if ($blogs->count() > 0)
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.blog-editorial')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.blog-editorial')
@else
<x-cms-section :title="$settings['blog_section_title'] ?? 'Travel Stories & Inspiration'" :description="$settings['blog_section_description'] ??
            'Discover inspiring travel stories, destination guides, and insider tips for your next adventure'" :items="$blogs" type="blogs" :showPrice="false"
    :showDuration="false" :showRating="false" viewAllText="View All Stories" sectionId="travel-blog-section"
    :limit="3" customTemplate="blog-card2" />
@endif
@endif

<!-- home4 faq Section Start-->
@if ($faqs->count() > 0)
@if (is_theme('theme-03'))
    @include('partials.themes.theme-03.faq')
@elseif (is_theme('theme-04'))
    @include('partials.themes.theme-04.faq')
@else
<div class="home4-faq-section mb-100">
    <div class="container">
        <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
            data-wow-duration="1500ms">
            <div class="col-xl-6 col-lg-8">
                <div class="section-title text-center">
                    <h2>{{ $settings['faq_section_title'] ?? 'Questions & Answer' }}</h2>
                    <p>We’re committed to offering more than just products—we provide exceptional experiences.
                    </p>
                </div>
            </div>
        </div>
        <div class="row justify-content-center">
            <div class="col-xl-8 col-lg-10">
                <div class="faq-wrap two">
                    <div class="accordion accordion-flush" id="accordionFlushExample">

                        @foreach ($faqs as $index => $faq)
                        <div class="accordion-item wow animate fadeInDown"
                            data-wow-delay="{{ ($index + 1) * 200 }}ms" data-wow-duration="1500ms">
                            <h5 class="accordion-header" id="flush-heading{{ \Str::camel($faq->id) }}">
                                <button class="accordion-button {{ $index === 0 ? '' : 'collapsed' }}"
                                    type="button" data-bs-toggle="collapse"
                                    data-bs-target="#flush-collapse{{ \Str::camel($faq->id) }}"
                                    aria-expanded="{{ $index === 0 ? 'true' : 'false' }}"
                                    aria-controls="flush-collapse{{ \Str::camel($faq->id) }}">{{ $faq->question }}</button>
                            </h5>
                            <div id="flush-collapse{{ \Str::camel($faq->id) }}"
                                class="accordion-collapse collapse {{ $index === 0 ? 'show' : '' }}"
                                aria-labelledby="flush-heading{{ \Str::camel($faq->id) }}"
                                data-bs-parent="#accordionFlushExample">
                                <div class="accordion-body">
                                    {!! $faq->answer !!}
                                </div>
                            </div>
                        </div>
                        @endforeach




                    </div>
                </div>
            </div>
        </div>
    </div>
    <img src="{{ s3_asset($settings['faq_section_vector'] ?? 'assets/img/home4/vector/faq-section-vector.svg') }}"
        alt="" class="vector" loading="lazy">
</div>
@endif
@endif
<!-- home4 faq Section End-->
@endsection

@push('styles')
@if (!is_theme('theme-03') && !is_theme('theme-04'))
<style>
    /* Home Booking Form Section - Separate from Hero */
    .home-booking-form-section {
        position: relative;
        margin-top: -240px;
        /* Overlap hero slightly for visual continuity */
        z-index: 10;
    }

    @media (max-width: 991px) {
        .home-booking-form-section {
            margin-top: -240px;
        }
    }

    @media (max-width: 767px) {
        .home-booking-form-section {
            margin-top: -240px;
        }
    }

    /* Update hero to have proper bottom padding */
    .home4-banner-section {
        padding-bottom: 0;
        margin-bottom: 0;
    }

    @media (max-width: 576px) {
        .home4-banner-section {
            padding-bottom: 0;
            margin-bottom: 0;
        }
    }

    @media (max-width: 1199px) {
        .home4-banner-section {
            padding-bottom: 0;
            margin-bottom: 0;
        }
    }

    @media (max-width: 1399px) {
        .home4-banner-section {
            padding-bottom: 0;
            margin-bottom: 0;
        }
    }

    @media (max-width: 1699px) {
        .home4-banner-section {
            padding-bottom: 0;
            margin-bottom: 0;
        }
    }

    /* Currency Formatting */
    .currency-symbol,
    .currency-code {
        font-size: 0.8em;
        font-weight: normal;
        opacity: 0.8;
        margin-right: 0.25rem;
    }

    .cart-summary-float .currency-symbol {
        font-size: 0.75em;
        margin-right: 0.2rem;
    }

    /* Featured Vehicles Section Styling */
    .featured-vehicles-section {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        padding: 60px 0;
        position: relative;
    }

    .featured-vehicles-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent 0%, #BF2629 50%, transparent 100%);
    }

    /* Featured Vehicles Carousel */
    .featured-vehicles-slider {
        overflow: hidden;
        position: relative;
    }

    .featured-vehicles-slider .swiper-slide {
        height: auto;
        display: flex;
        align-items: stretch;
    }

    .featured-vehicles-slider .swiper-slide .row {
        width: 100%;
        margin: 0;
    }

    /* Pagination Styling */
    .featured-vehicles-pagination {
        position: static !important;
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 30px;
    }

    .featured-vehicles-pagination .swiper-pagination-bullet {
        width: 12px;
        height: 12px;
        background: rgba(191, 38, 41, 0.3);
        border-radius: 50%;
        opacity: 1;
        transition: all 0.3s ease;
    }

    .featured-vehicles-pagination .swiper-pagination-bullet-active {
        background: #BF2629;
        transform: scale(1.2);
    }

    /* Navigation Arrows (if needed) */
    .featured-vehicles-nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(191, 38, 41, 0.2);
        border-radius: 50%;
        width: 50px;
        height: 50px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        z-index: 10;
    }

    .featured-vehicles-nav:hover {
        background: #BF2629;
        color: white;
        transform: translateY(-50%) scale(1.1);
    }

    .featured-vehicles-prev {
        left: -25px;
    }

    .featured-vehicles-next {
        right: -25px;
    }

    /* Vehicle Cards in Carousel - Maintain height */
    .featured-vehicles-slider .vehicle-card {
        height: 100%;
        min-height: 400px;
    }

    .featured-vehicles-btn {
        background: linear-gradient(135deg, #BF2629 0%, #d32f33 100%);
        border: none;
        padding: 15px 30px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-radius: 50px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(191, 38, 41, 0.3);
    }

    .featured-vehicles-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(191, 38, 41, 0.4);
        background: linear-gradient(135deg, #a01f22 0%, #BF2629 100%);
    }

    .featured-vehicles-btn svg {
        margin-left: 8px;
        transition: transform 0.3s ease;
    }

    .featured-vehicles-btn:hover svg {
        transform: translateX(3px);
    }

    /* Cart Summary Float (Reuse from search-results) */
    .cart-summary-float {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 350px;
        background: var(--primary-color, #BF2629);
        border-radius: 16px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        z-index: 1050;
        animation: slideInUp 0.4s ease-out;
    }

    @keyframes slideInUp {
        from {
            transform: translateY(100px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .cart-float-header {
        padding: 16px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
    }

    .cart-float-header h6 {
        margin: 0;
        font-weight: 700;
        color: white;
    }

    .cart-float-body {
        padding: 16px 20px;
        max-height: 300px;
        overflow-y: auto;
        color: white;
    }

    .cart-float-item {
        padding: 12px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .cart-float-item:last-child {
        border-bottom: none;
    }

    .cart-float-footer {
        padding: 16px 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.2);
    }

    .cart-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
        font-size: 16px;
    }

    .cart-total strong {
        font-size: 20px;
        font-weight: 800;
    }

    .currency-symbol {
        font-weight: 400 !important;
        opacity: 0.9;
    }

    .cart-breakdown>div {
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
        font-size: 14px;
        margin-bottom: 4px;
    }

    .cart-breakdown>div span:first-child {
        opacity: 0.9;
    }

    /* Mobile responsiveness */
    @media (max-width: 1199px) {
        .featured-vehicles-prev {
            left: -15px;
        }

        .featured-vehicles-next {
            right: -15px;
        }
    }

    @media (max-width: 991px) {
        .featured-vehicles-nav {
            display: none;
            /* Hide navigation arrows on mobile */
        }

        .featured-vehicles-slider .vehicle-card {
            min-height: 350px;
        }
    }

    @media (max-width: 767px) {
        .cart-summary-float {
            width: calc(100% - 40px);
            right: 20px;
            left: 20px;
        }

        .featured-vehicles-section {
            padding: 40px 0;
        }

        /* Stack 2 cards per slide on mobile */
        .featured-vehicles-slider .swiper-slide .row .col-lg-3 {
            flex: 0 0 50%;
            max-width: 50%;
        }
    }

    @media (max-width: 575px) {

        /* Single card per slide on very small screens */
        .featured-vehicles-slider .swiper-slide .row .col-lg-3 {
            flex: 0 0 100%;
            max-width: 100%;
        }
    }

    /* Smooth slide transitions */
    .featured-vehicles-slider .swiper-slide-active {
        opacity: 1;
    }

    .featured-vehicles-slider .swiper-slide-next,
    .featured-vehicles-slider .swiper-slide-prev {
        opacity: 0.7;
    }
</style>
@endif
@endpush

@push('scripts')
<script>
    // Cart management for featured vehicles
    let cart = [];
    let cartCurrencySymbol = '{{ getCurrencySymbol() }}';

    $(document).ready(function() {
        // Load existing cart
        loadCart();

        // Add to cart functionality for featured vehicles
        $('.add-to-cart-btn').on('click', function() {
            const btn = $(this);
            const groupId = btn.data('group-id');
            const searchId = btn.data('search-id');
            const groupName = btn.data('group-name');
            const basePrice = parseFloat(btn.data('base-price'));
            const currency = btn.data('currency');

            // Default booking parameters for featured vehicles
            const searchData = {
                search_id: searchId,
                    from_date: '{{ date('Y-m-d') }}',
                    to_date: '{{ date('Y-m-d', strtotime('+1 day')) }}',
                from_time: '09:00',
                to_time: '18:00',
                service_type: 'ride_now',
                pickup_location: null,
                dropoff_location: null,
                duration_days: 1
            };

            addToCart({
                group_id: groupId,
                group_name: groupName,
                base_price: basePrice,
                currency: currency,
                quantity: 1,
                ...searchData
            });

            // Visual feedback
            btn.html('<i class="bi bi-check-circle-fill"></i> Added!');
            btn.prop('disabled', true);

            setTimeout(() => {
                btn.html('<i class="bi bi-cart-plus"></i> Add to Cart');
                btn.prop('disabled', false);
            }, 2000);
        });

        // Book now functionality
        $('.book-now-btn').on('click', function() {
            const btn = $(this);
            const groupId = btn.data('group-id');
            const searchId = btn.data('search-id');
            const groupName = btn.data('group-name');
            const basePrice = parseFloat(btn.data('base-price'));
            const currency = btn.data('currency');

            // Add to cart first
            const searchData = {
                search_id: searchId,
                    from_date: '{{ date('Y-m-d') }}',
                    to_date: '{{ date('Y-m-d', strtotime('+1 day')) }}',
                from_time: '09:00',
                to_time: '18:00',
                service_type: 'ride_now',
                pickup_location: null,
                dropoff_location: null,
                duration_days: 1
            };

            addToCart({
                group_id: groupId,
                group_name: groupName,
                base_price: basePrice,
                currency: currency,
                quantity: 1,
                ...searchData
            });

            // Redirect to checkout
            setTimeout(() => {
                    window.location.href = '{{ route('checkout') }}';
            }, 500);
        });

        // Close cart float
        $(document).on('click', '#closeCartFloat', function() {
            $('#cartSummaryFloat').fadeOut();
        });

        // Initialize Featured Vehicles Slider
        if ($('.featured-vehicles-slider').length > 0) {
            var featuredVehiclesSwiper = new Swiper(".featured-vehicles-slider", {
                slidesPerView: 1,
                speed: 1200,
                spaceBetween: 24,
                autoplay: {
                    delay: 4000,
                    disableOnInteraction: false,
                },
                pagination: {
                    el: ".featured-vehicles-pagination",
                    clickable: true,
                },
                navigation: {
                    nextEl: ".featured-vehicles-next",
                    prevEl: ".featured-vehicles-prev",
                },
                breakpoints: {
                    320: {
                        slidesPerView: 1,
                        spaceBetween: 20,
                    },
                    768: {
                        slidesPerView: 1,
                        spaceBetween: 24,
                    },
                    1024: {
                        slidesPerView: 1,
                        spaceBetween: 24,
                    }
                },
                on: {
                    init: function() {
                        // Re-bind cart events after slider initialization
                        bindVehicleCardEvents();
                    },
                    slideChange: function() {
                        // Re-bind cart events after slide change
                        bindVehicleCardEvents();
                    }
                }
            });
        }

        function bindVehicleCardEvents() {
            // Re-bind add to cart events for vehicle cards in carousel
            $('.featured-vehicles-slider .add-to-cart-btn').off('click').on('click', function() {
                const btn = $(this);
                const groupId = btn.data('group-id');
                const searchId = btn.data('search-id');
                const groupName = btn.data('group-name');
                const basePrice = parseFloat(btn.data('base-price'));
                const currency = btn.data('currency');

                const searchData = {
                    search_id: searchId,
                        from_date: '{{ date('Y-m-d') }}',
                        to_date: '{{ date('Y-m-d', strtotime('+1 day')) }}',
                    from_time: '09:00',
                    to_time: '18:00',
                    service_type: 'ride_now',
                    pickup_location: null,
                    dropoff_location: null,
                    duration_days: 1
                };

                addToCart({
                    group_id: groupId,
                    group_name: groupName,
                    base_price: basePrice,
                    currency: currency,
                    quantity: 1,
                    ...searchData
                });

                btn.html('<i class="bi bi-check-circle-fill"></i> Added!');
                btn.prop('disabled', true);

                setTimeout(() => {
                    btn.html('<i class="bi bi-cart-plus"></i> Add to Cart');
                    btn.prop('disabled', false);
                }, 2000);
            });

            // Re-bind book now events
            $('.featured-vehicles-slider .book-now-btn').off('click').on('click', function() {
                const btn = $(this);
                const groupId = btn.data('group-id');
                const searchId = btn.data('search-id');
                const groupName = btn.data('group-name');
                const basePrice = parseFloat(btn.data('base-price'));
                const currency = btn.data('currency');

                const searchData = {
                    search_id: searchId,
                        from_date: '{{ date('Y-m-d') }}',
                        to_date: '{{ date('Y-m-d', strtotime('+1 day')) }}',
                    from_time: '09:00',
                    to_time: '18:00',
                    service_type: 'ride_now',
                    pickup_location: null,
                    dropoff_location: null,
                    duration_days: 1
                };

                addToCart({
                    group_id: groupId,
                    group_name: groupName,
                    base_price: basePrice,
                    currency: currency,
                    quantity: 1,
                    ...searchData
                });

                setTimeout(() => {
                        window.location.href = '{{ route('checkout') }}';
                }, 500);
            });
        }
    });

    function addToCart(item) {
        // Add to cart via AJAX to use database
        $.ajax({
                url: '{{ route('cart.add') }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                vehicle_id: item.group_id,
                name: item.group_name,
                price: item.base_price,
                days: item.duration_days || 1,
                search_data: item
            },
            success: function(response) {
                if (response.success) {
                    // Load updated cart from server
                    loadCartFromServer();
                    showCartFloat();
                    // Dispatch cart updated event
                    window.dispatchEvent(new CustomEvent('cartUpdated'));
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function(xhr) {
                console.error('Error adding to cart:', xhr);
                alert('Error adding item to cart. Please try again.');
            }
        });
    }

    function removeFromCart(cartKey) {
        $.ajax({
                url: '{{ route('cart.remove') }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                cart_key: cartKey
            },
            success: function(response) {
                if (response.success) {
                    loadCartFromServer();
                    if (cart.length === 0) {
                        $('#cartSummaryFloat').fadeOut();
                    }
                    // Dispatch cart updated event
                    window.dispatchEvent(new CustomEvent('cartUpdated'));
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function(xhr) {
                console.error('Error removing from cart:', xhr);
                alert('Error removing item. Please try again.');
            }
        });
    }

    function loadCartFromServer() {
        $.ajax({
                url: '{{ route('cart.get') }}',
            method: 'GET',
            success: function(response) {
                if (response.success) {
                    cart = response.items || [];
                    cartCurrencySymbol = response.currency_symbol || (response.totals && response.totals.currency_symbol) || '{{ getCurrencySymbol() }}';
                    updateCartDisplay();
                    if (cart.length > 0) {
                        showCartFloat();
                    }
                }
            },
            error: function(xhr) {
                console.error('Error loading cart:', xhr);
            }
        });
    }

    function loadCart() {
        // Load cart from server instead of localStorage
        loadCartFromServer();
    }

    function updateCartDisplay() {
        const $cartItems = $('#cartFloatItems');
        const $cartTotal = $('#cartTotalPrice');

        // Create cart float if it doesn't exist
        if ($('#cartSummaryFloat').length === 0) {
            $('body').append(`
                <div id="cartSummaryFloat" class="cart-summary-float" style="display: none;">
                    <div class="cart-float-content">
                        <div class="cart-float-header">
                            <h6><i class="bi bi-cart-fill"></i> Cart</h6>
                            <button type="button" class="btn-close btn-close-white" id="closeCartFloat"></button>
                        </div>
                        <div class="cart-float-body" id="cartFloatItems">
                            <!-- Cart items will be dynamically added here -->
                        </div>
                        <div class="cart-float-footer">
                            <div class="cart-breakdown">
                                <div class="cart-subtotal mb-2">
                                    <span>Subtotal:</span>
                                    <span id="cartSubtotalPrice"><span class="currency-symbol">{{ getCurrencySymbol() }}</span> <span class="amount">0</span></span>
                                </div>
                                <div class="cart-addon-charges mb-1" style="display: none;">
                                    <span>Addon Charges:</span>
                                    <span id="cartAddonCharges"><span class="currency-symbol">{{ getCurrencySymbol() }}</span> <span class="amount">0</span></span>
                                </div>
                                <div class="cart-extra-km-charges mb-1" style="display: none;">
                                    <span>Extra KM Charges:</span>
                                    <span id="cartExtraKmCharges"><span class="currency-symbol">{{ getCurrencySymbol() }}</span> <span class="amount">0</span></span>
                                </div>
                                <div class="cart-service-fee mb-1" style="display: none;">
                                    <span>Service Fee:</span>
                                    <span id="cartServiceFee"><span class="currency-symbol">{{ getCurrencySymbol() }}</span> <span class="amount">0</span></span>
                                </div>
                                <div class="cart-tax mb-1" style="display: none;">
                                    <span>Tax:</span>
                                    <span id="cartTax"><span class="currency-symbol">{{ getCurrencySymbol() }}</span> <span class="amount">0</span></span>
                                </div>
                                <div class="cart-vat mb-1" style="display: none;">
                                    <span>VAT:</span>
                                    <span id="cartVat"><span class="currency-symbol">{{ getCurrencySymbol() }}</span> <span class="amount">0</span></span>
                                </div>
                                <div class="cart-total mb-2 mt-2" style="border-top: 1px solid rgba(255,255,255,0.2); padding-top: 8px;">
                                    <span><strong>Total:</strong></span>
                                    <strong id="cartTotalPrice"><span class="currency-symbol">{{ getCurrencySymbol() }}</span> <span class="amount">0</span></strong>
                                </div>
                            </div>
                            <a href="{{ route('checkout') }}" class="btn btn-light w-100">
                                <i class="bi bi-cart-check"></i> Review & Checkout
                            </a>
                        </div>
                    </div>
                </div>
            `);
        }

        $('#cartFloatItems').empty();
        $('#cartSummaryFloat .currency-symbol').text(cartCurrencySymbol);

        // Helper function to get pricing label based on service type
        function getPricingLabel(serviceType) {
            const labels = {
                'ride_now': 'Rate',
                'airport_transfers': 'Transfer Rate',
                'point_to_point': 'Trip Rate',
                'corporate': 'Per Day',
                'day_rental': 'Per Day'
            };
            return labels[serviceType] || 'Rate';
        }

        // Helper function to get duration label based on service type
        function getDurationLabel(serviceType, days) {
            const fixedRateServices = ['ride_now', 'airport_transfers', 'point_to_point'];
            if (fixedRateServices.includes(serviceType)) {
                if (serviceType === 'ride_now') return 'One-time trip';
                if (serviceType === 'airport_transfers') return 'Airport transfer';
                return 'Trip';
            }
            return days === 1 ? '1 day' : `${days} days`;
        }

        // Helper function to check if service is fixed-rate
        function isFixedRate(serviceType) {
            return ['ride_now', 'airport_transfers', 'point_to_point'].includes(serviceType);
        }

        let total = 0;
        Object.keys(cart).forEach((key, index) => {
            const item = cart[key];
            const days = item.days || item.duration_days || item.quantity || 1;
            const price = item.price || item.base_price || 0;
            const itemTotal = price * days;
            total += itemTotal;

            const serviceType = item.service_type || '';
            const pricingLabel = getPricingLabel(serviceType);
            const durationLabel = getDurationLabel(serviceType, days);
            const fixedRate = isFixedRate(serviceType);
            const currencySymbol = cartCurrencySymbol;

            // Build pricing display based on service type
            let pricingHtml = '';
            if (fixedRate) {
                pricingHtml =
                    `<span>${pricingLabel}: <small class="currency-symbol">${currencySymbol}</small> ${Math.floor(Math.max(0, itemTotal)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</span>`;
            } else {
                pricingHtml = `
                        <span>${pricingLabel}: <small class="currency-symbol">${currencySymbol}</small> ${Math.floor(Math.max(0, price)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</span>
                        <strong><small class="currency-symbol">${currencySymbol}</small> ${Math.floor(Math.max(0, itemTotal)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</strong>
                    `;
            }

            console.log(item);

            $('#cartFloatItems').append(`
                <div class="cart-float-item">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="flex-grow-1">
                            <strong>${item.name || item.group_name || 'Vehicle Rental'}</strong>
                            <div class="small">${durationLabel}</div>
                            <div class="small">${item.pickup_date || item.from_date || ''} to ${item.return_date || item.to_date || ''}</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-white p-0 ms-2" onclick="removeFromCart('${key}')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="d-flex justify-content-between">
                        ${pricingHtml}
                    </div>
                </div>
            `);
        });

        // For home page, we calculate from individual cart items
        $('#cartTotalPrice .amount').text(total.toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }));

        // Set subtotal to the calculated base total (before any server-side charges)
        $('#cartSubtotalPrice .amount').text(total.toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }));

        // Hide all breakdown items for now since we don't have the server data on home page
        $('.cart-addon-charges, .cart-extra-km-charges, .cart-service-fee, .cart-tax, .cart-vat').hide();
    }

    function showCartFloat() {
        $('#cartSummaryFloat').fadeIn();
    }
</script>
@endpush
