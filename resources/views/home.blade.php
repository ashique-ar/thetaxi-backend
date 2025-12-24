@extends('layouts.app')

@section('title', 'TheTaxi - Your Reliable Taxi Service')

@section('content')
    <!-- home4 Banner Section Start-->
    <div class="home4-banner-section mb-100">
        <div class="banner-video-area">
            <video autoplay loop muted playsinline preload="metadata"
                src="{{ s3_asset($settings['banner_image'] ?? 'assets/video/home4-banner-video.mp4') }}"></video>
        </div>
        <div class="banner-content-wrap">
            <div class="container">
                <div class="banner-content">
                    <h1>{{ $settings['banner_heading'] ?? 'All-in-one Travel Booking.' }}</h1>
                    <p>{{ $settings['banner_subheading'] ?? 'Best travel agency in world-wide & achieve “World
                            Travel Award”' }}</p>
                    @include('components.booking-form')
                </div>
            </div>
        </div>
    </div>
    <!-- home4 Banner Section End-->

    @if (isset($partners) && $partners->count() > 0)
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
    <!-- home4 partner area Section End-->

    <!-- Featured Vehicles Section Start -->
    @if (isset($featuredVehicles) && count($featuredVehicles['data']) > 0)
        <div class="featured-vehicles-section home4-offer-slider-section mb-100">
            <div class="container">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="section-title text-center mb-60 wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                            <h2>{{ $settings['featured_vehicles_title'] ?? 'Featured Rental Vehicles' }}</h2>
                            <p>{{ $settings['featured_vehicles_description'] ?? 'Choose from our premium selection of vehicles for your rental needs. All vehicles come with flexible rental options and competitive pricing.' }}</p>
                        </div>
                    </div>
                </div>

                <!-- Vehicle Carousel -->
                <div class="row mb-40">
                    <div class="col-lg-12">
                        <div class="swiper featured-vehicles-slider">
                            <div class="swiper-wrapper">
                                @php
                                    $chunks = array_chunk($featuredVehicles['data'], 4); // 4 vehicles per slide
                                @endphp
                                
                                @foreach($chunks as $vehicleChunk)
                                    <div class="swiper-slide">
                                        <div class="row g-4">
                                            @foreach($vehicleChunk as $vehicle)
                                                @php
                                                    $pricing = $vehicle['pricing_info'] ?? ['base_amount' => 0, 'currency' => 'LKR'];
                                                    $enhancedPricing = $vehicle['enhanced_pricing'] ?? [];
                                                    $serviceFeatures = $vehicle['service_features'] ?? [];
                                                    $availability = [
                                                        'available' => $vehicle['available_count'] ?? 0,
                                                        'total' => $vehicle['total_count'] ?? 0
                                                    ];
                                                    $isRecommended = $vehicle['recommended'] ?? false;
                                                @endphp

                                                <div class="col-lg-3 col-md-6 col-sm-6">
                                                    <x-vehicle-card
                                                        :vehicle="$vehicle"
                                                        :pricing="$pricing"
                                                        :enhancedPricing="$enhancedPricing"
                                                        :serviceFeatures="$serviceFeatures"
                                                        :availability="$availability"
                                                        :searchId="$featuredVehicleSearch['id']"
                                                        :isRecommended="$isRecommended"
                                                        :showBookNow="true"
                                                        :showViewDetails="false"
                                                    />
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            
                            <!-- Navigation arrows -->
                            <div class="featured-vehicles-prev featured-vehicles-nav">
                                <i class="bi bi-chevron-left"></i>
                            </div>
                            <div class="featured-vehicles-next featured-vehicles-nav">
                                <i class="bi bi-chevron-right"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation and Pagination -->
                <div class="row">
                    <div class="col-lg-12 d-flex justify-content-center">
                        <div class="featured-vehicles-pagination swiper-pagination2 paginations"></div>
                    </div>
                </div>

                <!-- View All Button -->
                <div class="text-center mt-40 wow animate fadeInUp" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <a href="{{ route('cms.index', ['type' => 'ride_now']) }}" class="btn btn-primary featured-vehicles-btn">
                        <i class="bi bi-car-front-fill me-2"></i>
                        {{ $settings['vehicles_view_all_text'] ?? 'View All Rental Vehicles' }}
                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                stroke-width="1.5" stroke-linecap="round"></path>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    @endif
    <!-- Featured Vehicles Section End -->

    
    @if ($destinations->count() > 0)
        <x-cms-section :title="$settings['destinations_section_title'] ?? 'Top Destinations'" :description="$settings['destinations_section_description'] ??
            'Discover the most spectacular destinations Sri Lanka has to offer'" :items="$destinations" type="taxi" :showPrice="false"
            :showDuration="false" :showRating="true" viewAllText="View All Destinations" sectionId="destinations-section"
            :limit="6" />
    @endif

    @if ($packages->count() > 0)
        <x-cms-section :title="$settings['packages_section_title'] ?? 'Things to Do'" :description="$settings['packages_section_description'] ??
            'Discover exciting activities and experiences Sri Lanka has to offer'" :items="$packages" type="things-to-do" :showPrice="true"
            :showDuration="true" :showRating="true" viewAllText="View All Activities" sectionId="things-to-do-section"
            :limit="6" />
    @endif

    <!-- home4 Offer Slider Section Start-->
    <div class="home4-offer-slider-section mb-100">
        <div class="container">
            <div class="row mb-40">
                <div class="col-lg-12">
                    <div class="swiper home4-offer-slider">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <a href="travel-package-details.html"><img
                                        src="{{ s3_asset($settings['offer_slider_img_1'] ?? 'assets/img/home4/home4-offer-slider-img1.jpg') }}"
                                        alt=""></a>
                            </div>
                            <div class="swiper-slide">
                                <a href="travel-package-details.html"><img
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
    <!-- home4 Offer Slider Section End-->

    <!-- home4 Why Choose Us Section Start-->
    <div class="home4-why-choose-us-section">
        <div class="container">
            <div class="row g-4 justify-content-between align-items-end mb-60 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xxl-5 col-xl-6 col-lg-7">
                    <div class="section-title">
                        <h2>Why We’re Best Agency</h2>
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
                        <h5>{{ $settings['why_feature_4'] ?? 'Local Experties' }}</h5>
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
                        <h6>Need to Help? Don’t Hesitate Friendly Collaboarte with Experties</h6>
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
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '+1234567890') }}">{{ $settings['company_phone'] ?? '+1 234 567 890' }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- home4 Why Choose Us Section End-->

    <!-- home4 Testimonial Section Start-->
    @if ($testimonials && $testimonials->count() > 0)
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
                                                            @if ($i <= $testimonial->rating)
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
    <!-- home4 Testimonial Section End-->

    @if ($inspirations->count() > 0)
        <x-cms-section :title="$settings['inspirations_section_title'] ?? 'Our Services'" :description="$settings['inspirations_section_description'] ??
            'Professional transportation and travel services designed to meet your unique needs'" :items="$inspirations" type="services" :showPrice="true"
            :showDuration="false" :showRating="false" viewAllText="View All Services" sectionId="services-section"
            :limit="3" customTemplate="blog-card2" />
    @endif

    @if ($blogs->count() > 0)
        <x-cms-section :title="$settings['blog_section_title'] ?? 'Travel Stories & Inspiration'" :description="$settings['blog_section_description'] ??
            'Discover inspiring travel stories, destination guides, and insider tips for your next adventure'" :items="$blogs" type="blogs" :showPrice="false"
            :showDuration="false" :showRating="false" viewAllText="View All Stories" sectionId="travel-blog-section"
            :limit="3" customTemplate="blog-card2" />
    @endif

    <!-- home4 faq Section Start-->
    @if ($faqs->count() > 0)
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
    <!-- home4 faq Section End-->
@endsection

@push('styles')
<style>
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
        box-shadow: 0 8px 32px rgba(0,0,0,0.3);
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
        border-bottom: 1px solid rgba(255,255,255,0.2);
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
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .cart-float-item:last-child {
        border-bottom: none;
    }

    .cart-float-footer {
        padding: 16px 20px;
        border-top: 1px solid rgba(255,255,255,0.2);
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
            display: none; /* Hide navigation arrows on mobile */
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
@endpush

@push('scripts')
<script>
    // Cart management for featured vehicles
    let cart = [];

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
                from_date: '{{ date("Y-m-d") }}',
                to_date: '{{ date("Y-m-d", strtotime("+1 day")) }}',
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
                from_date: '{{ date("Y-m-d") }}',
                to_date: '{{ date("Y-m-d", strtotime("+1 day")) }}',
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
                window.location.href = '{{ route("cart") }}';
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
                    init: function () {
                        // Re-bind cart events after slider initialization
                        bindVehicleCardEvents();
                    },
                    slideChange: function () {
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
                    from_date: '{{ date("Y-m-d") }}',
                    to_date: '{{ date("Y-m-d", strtotime("+1 day")) }}',
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
                    from_date: '{{ date("Y-m-d") }}',
                    to_date: '{{ date("Y-m-d", strtotime("+1 day")) }}',
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
                    window.location.href = '{{ route("cart") }}';
                }, 500);
            });
        }
    });

    function addToCart(item) {
        // Add to cart via AJAX to use database
        $.ajax({
            url: '{{ route("cart.add") }}',
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
            url: '{{ route("cart.remove") }}',
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
            url: '{{ route("cart.get") }}',
            method: 'GET',
            success: function(response) {
                if (response.success) {
                    cart = response.items || [];
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
                            <div class="cart-total mb-2">
                                <span>Total:</span>
                                <strong id="cartTotalPrice">LKR 0.00</strong>
                            </div>
                            <a href="{{ route('cart') }}" class="btn btn-light w-100">
                                <i class="bi bi-cart-check"></i> View Cart & Checkout
                            </a>
                        </div>
                    </div>
                </div>
            `);
        }
        
        $('#cartFloatItems').empty();
        
        let total = 0;
        Object.keys(cart).forEach((key, index) => {
            const item = cart[key];
            const itemTotal = (item.price || item.base_price || 0) * (item.days || item.quantity || 1);
            total += itemTotal;
            
            $('#cartFloatItems').append(`
                <div class="cart-float-item">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="flex-grow-1">
                            <strong>${item.name || item.group_name || 'Vehicle Rental'}</strong>
                            <div class="small">${item.days || item.duration_days || 1} day(s)</div>
                            <div class="small">${item.pickup_date || item.from_date || ''} to ${item.return_date || item.to_date || ''}</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-white p-0 ms-2" onclick="removeFromCart('${key}')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Days: ${item.days || item.quantity || 1}</span>
                        <strong>$${itemTotal.toFixed(2)}</strong>
                    </div>
                </div>
            `);
        });
        
        $('#cartTotalPrice').text('$' + total.toFixed(2));
    }

    function showCartFloat() {
        $('#cartSummaryFloat').fadeIn();
    }
</script>
@endpush

