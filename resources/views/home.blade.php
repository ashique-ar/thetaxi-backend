@extends('layouts.app')

@section('title', 'TheTaxi - Your Reliable Taxi Service')

@section('content')
    <!-- home4 Banner Section Start-->
    <div class="home4-banner-section mb-100">
        <div class="banner-video-area">
            <video autoplay loop muted playsinline
                src="{{ $settings['banner_video'] ? Storage::url($settings['banner_video']) : asset('assets/video/home4-banner-video.mp4') }}"></video>
        </div>
        <div class="banner-content-wrap">
            <div class="container">
                <div class="banner-content">
                    <h1>{{ $settings['banner_heading'] ?? 'All-in-one Travel Booking.' }}</h1>
                    <p>{{ $settings['banner_subheading'] ?? 'Best travel agency in world-wide & achieve' }} <span>“World
                            Travel Award”</span></p>
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
                                    <img src="{{ $partner->image ? Storage::url($partner->image) : Storage::url($settings['partner_logo_1']) }}"
                                        alt="{{ $partner->title }}">
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
                    <a href="{{ route('services', ['type' => 'ride_now']) }}" class="btn btn-primary featured-vehicles-btn">
                        <i class="bi bi-car-front-fill me-2"></i>
                        View All Rental Vehicles
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

    <!-- home4 Feature Section Start-->
    {{-- <div class="home4-feature-section mb-100">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="feature-card">
                        <div class="icon">
                            <img src="{{ $settings['feature_1_icon'] ? Storage::url($settings['feature_1_icon']) : asset('assets/img/home4/icon/feature-icon1.svg') }}"
                                alt="">
                        </div>
                        <h4>{{ $settings['feature_1_title'] ?? 'One Click Booking.' }}</h4>
                        <p>{{ $settings['feature_1_description'] ?? 'You can hassle-free and fast tour & travel package booking by TheTaxi.' }}
                        </p>
                        <img src="{{ $settings['feature_card_vector'] ? Storage::url($settings['feature_card_vector']) : asset('assets/img/home4/vector/feature-card-vector.svg') }}"
                            alt="" class="vector">
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <div class="feature-card two">
                        <div class="icon">
                            <img src="{{ $settings['feature_2_icon'] ? Storage::url($settings['feature_2_icon']) : asset('assets/img/home4/icon/feature-icon2.svg') }}"
                                alt="">
                        </div>
                        <h4>{{ $settings['feature_2_title'] ?? 'Discount & Offer.' }}</h4>
                        <p>{{ $settings['feature_2_description'] ?? 'Agencies have special discounts on flights, hotels, & packages.' }}
                        </p>
                        <img src="{{ $settings['feature_card_vector'] ? Storage::url($settings['feature_card_vector']) : asset('assets/img/home4/vector/feature-card-vector.svg') }}"
                            alt="" class="vector">
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="600ms" data-wow-duration="1500ms">
                    <div class="feature-card three">
                        <div class="icon">
                            <img src="{{ $settings['feature_3_icon'] ? Storage::url($settings['feature_3_icon']) : asset('assets/img/home4/icon/feature-icon3.svg') }}"
                                alt="">
                        </div>
                        <h4>{{ $settings['feature_3_title'] ?? 'Local Experties.' }}</h4>
                        <p>{{ $settings['feature_3_description'] ?? 'You can hassle-free and fast tour & travel package booking by TheTaxi.' }}
                        </p>
                        <img src="{{ $settings['feature_card_vector'] ? Storage::url($settings['feature_card_vector']) : asset('assets/img/home4/vector/feature-card-vector.svg') }}"
                            alt="" class="vector">
                    </div>
                </div>
            </div>
            <div class="bottom-area d-flex justify-content-center wow animate fadeInUp" data-wow-delay="200ms"
                data-wow-duration="1500ms" style="visibility: visible; animation-duration: 1500ms; animation-delay: 200ms;">
                <div class="batch">
                    <span>You’ve Customize Your Travel Package by One Click.</span>
                </div>
                <div class="batch two">
                    <a href="{{ $settings['feature_cta_link'] ?? 'contact.html' }}">{{ $settings['feature_cta_text'] ?? 'Customize Package' }}
                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                stroke-width="1.5" stroke-linecap="round"></path>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
        <img src="{{ $settings['feature_section_vector1'] ? Storage::url($settings['feature_section_vector1']) : asset('assets/img/home4/feature-section-vector.png') }}"
            alt="" class="section-vector">
        <img src="{{ $settings['feature_section_vector2'] ? Storage::url($settings['feature_section_vector2']) : asset('assets/img/home4/vector/feature-section-vector2.svg') }}"
            alt="" class="section-vector2">
    </div> --}}
    <!-- home4 Feature Section End-->

    <!-- Destinations Section - Enhanced with Unified CMS Cards -->
    @if ($destinations->count() > 0)
        <x-cms-section :title="$settings['destinations_section_title'] ?? 'Top Destinations'" :description="$settings['destinations_section_description'] ??
            'Discover the most spectacular destinations Sri Lanka has to offer'" :items="$destinations" type="destinations" :showPrice="false"
            :showDuration="false" :showRating="true" viewAllText="View All Destinations" sectionId="destinations-section"
            :limit="6" />
    @endif

    <!-- Packages/Things to Do Section - Enhanced with Unified CMS Cards -->

    <!-- home4 About Section Start-->
    {{-- <div class="home4-about-section mb-100">
        <div class="container">
            <div class="about-wrapper">
                <div class="row justify-content-between">
                    <div class="col-xl-5 col-lg-6 wow animate fadeInLeft" data-wow-delay="200ms" data-wow-duration="1500ms">
                        <div class="about-content">
                            <div class="section-title">
                                <h2>We’re Best Travel Agency Ever.</h2>
                                <p>{{ $settings['about_section_description'] ?? 'We provides information on flight bookings, hotel reservations, and other travel-related services. For more detailed information about their offerings, you can visit their official website.' }}
                                </p>
                            </div>
                            <ul>
                                <li>
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M6.24999 16.2334C6.18965 16.2334 6.13035 16.2177 6.07799 16.1877C6.02563 16.1577 5.98201 16.1146 5.95146 16.0625C4.65758 13.8582 1.20971 9.16675 1.17503 9.1196C1.12576 9.05264 1.10224 8.97019 1.10876 8.88731C1.11528 8.80444 1.15141 8.72668 1.21054 8.66825L2.2704 7.62096C2.32798 7.56406 2.40368 7.52914 2.48433 7.52227C2.56499 7.51541 2.64551 7.53702 2.71188 7.58337L6.17781 10.0035C8.48209 7.04337 10.6235 5.00047 12.0309 3.79676C13.6085 2.44735 14.6115 1.84099 14.6535 1.81572C14.7073 1.78342 14.7688 1.76636 14.8316 1.76636H16.5462C16.6163 1.76635 16.6849 1.78767 16.7426 1.82749C16.8004 1.86731 16.8447 1.92376 16.8697 1.98934C16.8947 2.05493 16.8991 2.12656 16.8825 2.19473C16.8658 2.2629 16.8288 2.32439 16.7764 2.37105C14.2345 4.6349 11.5919 8.23189 9.82257 10.8506C7.89924 13.6972 6.56405 16.0353 6.55079 16.0586C6.52074 16.1114 6.47733 16.1553 6.42494 16.186C6.37254 16.2167 6.31299 16.233 6.25227 16.2334L6.24999 16.2334Z" />
                                    </svg>
                                    {{ $settings['about_feature_1'] ?? 'Affordable Travel' }}
                                </li>
                                <li>
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M6.24999 16.2334C6.18965 16.2334 6.13035 16.2177 6.07799 16.1877C6.02563 16.1577 5.98201 16.1146 5.95146 16.0625C4.65758 13.8582 1.20971 9.16675 1.17503 9.1196C1.12576 9.05264 1.10224 8.97019 1.10876 8.88731C1.11528 8.80444 1.15141 8.72668 1.21054 8.66825L2.2704 7.62096C2.32798 7.56406 2.40368 7.52914 2.48433 7.52227C2.56499 7.51541 2.64551 7.53702 2.71188 7.58337L6.17781 10.0035C8.48209 7.04337 10.6235 5.00047 12.0309 3.79676C13.6085 2.44735 14.6115 1.84099 14.6535 1.81572C14.7073 1.78342 14.7688 1.76636 14.8316 1.76636H16.5462C16.6163 1.76635 16.6849 1.78767 16.7426 1.82749C16.8004 1.86731 16.8447 1.92376 16.8697 1.98934C16.8947 2.05493 16.8991 2.12656 16.8825 2.19473C16.8658 2.2629 16.8288 2.32439 16.7764 2.37105C14.2345 4.6349 11.5919 8.23189 9.82257 10.8506C7.89924 13.6972 6.56405 16.0353 6.55079 16.0586C6.52074 16.1114 6.47733 16.1553 6.42494 16.186C6.37254 16.2167 6.31299 16.233 6.25227 16.2334L6.24999 16.2334Z" />
                                    </svg>
                                    {{ $settings['about_feature_2'] ?? 'Trusted Experience' }}
                                </li>
                                <li>
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M6.24999 16.2334C6.18965 16.2334 6.13035 16.2177 6.07799 16.1877C6.02563 16.1577 5.98201 16.1146 5.95146 16.0625C4.65758 13.8582 1.20971 9.16675 1.17503 9.1196C1.12576 9.05264 1.10224 8.97019 1.10876 8.88731C1.11528 8.80444 1.15141 8.72668 1.21054 8.66825L2.2704 7.62096C2.32798 7.56406 2.40368 7.52914 2.48433 7.52227C2.56499 7.51541 2.64551 7.53702 2.71188 7.58337L6.17781 10.0035C8.48209 7.04337 10.6235 5.00047 12.0309 3.79676C13.6085 2.44735 14.6115 1.84099 14.6535 1.81572C14.7073 1.78342 14.7688 1.76636 14.8316 1.76636H16.5462C16.6163 1.76635 16.6849 1.78767 16.7426 1.82749C16.8004 1.86731 16.8447 1.92376 16.8697 1.98934C16.8947 2.05493 16.8991 2.12656 16.8825 2.19473C16.8658 2.2629 16.8288 2.32439 16.7764 2.37105C14.2345 4.6349 11.5919 8.23189 9.82257 10.8506C7.89924 13.6972 6.56405 16.0353 6.55079 16.0586C6.52074 16.1114 6.47733 16.1553 6.42494 16.186C6.37254 16.2167 6.31299 16.233 6.25227 16.2334L6.24999 16.2334Z" />
                                    </svg>
                                    {{ $settings['about_feature_3'] ?? 'Effortless Booking Process' }}
                                </li>
                            </ul>
                            <div class="counter-wrapper">
                                <div class="single-counter">
                                    <h2><strong
                                            class="counter">{{ $settings['about_years_experience'] ?? '12' }}</strong><sup>+</sup>
                                    </h2>
                                    <span>{{ $settings['about_years_label'] ?? 'Years <br> of Experience' }}</span>
                                </div>
                                <div class="counter-area">
                                    <ul class="counter-img-grp">
                                        <li><img src="{{ $settings['counter_people_img_1'] ? Storage::url($settings['counter_people_img_1']) : asset('assets/img/home3/counter-people-img1.png') }}"
                                                alt=""></li>
                                        <li><img src="{{ $settings['counter_people_img_2'] ? Storage::url($settings['counter_people_img_2']) : asset('assets/img/home3/counter-people-img2.png') }}"
                                                alt=""></li>
                                        <li><img src="{{ $settings['counter_people_img_3'] ? Storage::url($settings['counter_people_img_3']) : asset('assets/img/home3/counter-people-img3.png') }}"
                                                alt=""></li>
                                        <li><img src="{{ $settings['counter_people_img_4'] ? Storage::url($settings['counter_people_img_4']) : asset('assets/img/home3/counter-people-img4.png') }}"
                                                alt=""></li>
                                    </ul>
                                    <h6> <strong><span
                                                class="counter">{{ $settings['about_customers_count'] ?? '25' }}</span>k+</strong>
                                        {{ $settings['about_customers_label'] ?? 'Customer <br> in Worldwide.' }}
                                    </h6>
                                </div>
                            </div>
                            <svg class="divider" width="536" height="6" viewBox="0 0 536 6"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M5 2.5L0 0.113249V5.88675L5 3.5V2.5ZM531 3.5L536 5.88675V0.113249L531 2.5V3.5ZM4.5 3.5H531.5V2.5H4.5V3.5Z" />
                            </svg>
                            <div class="btn-area">
                                <a href="about.html" class="about-btn">
                                    {{ $settings['about_button_text'] ?? 'About More TheTaxi' }}
                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                            stroke-width="1.5" stroke-linecap="round" />
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 d-lg-flex d-none justify-content-lg-end wow animate fadeInRight"
                        data-wow-delay="200ms" data-wow-duration="1500ms">
                        <div class="about-img-grp">
                            <div class="single-grp">
                                <div class="counter-wrapper">
                                    <div class="counter-area">
                                        <h2><strong>{{ $settings['about_tours_completed'] ?? '26' }}</strong>K+</h2>
                                        <span>{{ $settings['about_tours_label'] ?? 'Tour Completed' }}</span>
                                    </div>
                                    <svg class="vector" width="55" height="55" viewBox="0 0 55 55"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd"
                                            d="M48.3953 19.3234C46.5928 21.0856 49.1783 24.8855 45.1628 27.3609C42.8491 28.7873 46.4538 31.6364 45.1628 34.9688C44.9762 35.4504 44.938 35.9362 45.2063 36.4593C42.0435 38.8936 38.0818 40.3413 33.7819 40.3413C32.6391 40.3413 31.5202 40.2389 30.4337 40.043L30.0167 41.4955C31.2582 41.7286 32.5188 41.8457 33.782 41.8452C44.9663 41.8452 54.0332 32.7783 54.0332 21.5941C54.0332 10.4098 44.9663 1.34277 33.7819 1.34277C29.4125 1.34277 25.1885 2.74141 21.6888 5.35111C24.3124 9.39308 24.2598 14.705 21.5556 18.6946C22.5728 20.4768 24.035 20.5296 24.6011 20.7501C26.7583 21.591 28.3803 26.5747 29.7306 28.1757C31.6603 27.0616 33.7557 26.7059 35.9213 27.385C35.5639 25.6287 33.5082 23.6465 32.8831 23.0953C30.6562 21.132 31.5914 20.176 33.345 19.6904C35.3627 19.1317 38.4639 19.1959 39.0785 19.0651C40.5177 18.7589 40.9324 17.7467 39.8315 16.7925C38.5393 15.6725 36.2766 14.4403 35.7499 13.4377C35.0217 12.0512 35.5506 11.6375 36.4901 11.347C38.3992 10.7566 42.0025 10.6734 40.2002 3.97439C46.4804 6.26248 51.1061 11.765 52.2518 18.3652C50.1517 18.3532 49.0145 18.718 48.3953 19.3234ZM7.92247 45.6491L1.71875 40.5901L3.98342 39.0886L10.0496 41.2748L30.9044 29.2343C34.3621 27.238 41.2161 29.9589 34.6547 33.7471L29.8863 36.5002L25.452 51.9436L22.4839 53.6572L22.6915 40.5937C22.6915 40.5937 10.2446 47.4544 7.92247 45.6491ZM11.5466 1.34277C17.3893 1.34277 22.1263 6.07965 22.1263 11.9225C22.1263 17.7654 17.3895 22.5023 11.5466 22.5023C5.70378 22.5023 0.966797 17.7653 0.966797 11.9225C0.966797 6.07976 5.70378 1.34277 11.5466 1.34277ZM5.09448 18.3054C5.5376 15.1383 8.25752 12.7006 11.5467 12.7006C14.8359 12.7006 17.5554 15.1382 17.9985 18.3054C19.6206 16.666 20.6223 14.4113 20.6223 11.9225C20.6223 6.90991 16.5591 2.84668 11.5465 2.84668C6.53394 2.84668 2.4707 6.90991 2.4707 11.9225C2.4707 14.4113 3.47252 16.6659 5.09448 18.3054ZM8.53531 7.95373C8.53531 9.61694 9.88356 10.9652 11.5468 10.9652C13.21 10.9652 14.5582 9.61694 14.5582 7.95373C14.5582 6.29095 13.21 4.9427 11.5468 4.9427C9.88356 4.9428 8.53531 6.29095 8.53531 7.95373ZM9.88088 31.828L12.8488 30.1144L22.298 32.4668L16.7678 35.6596L9.88088 31.828ZM16.8012 29.5485C15.9014 27.6309 15.3353 25.5737 15.1273 23.4657C14.6436 23.6154 14.1511 23.7346 13.6525 23.8224C13.8498 25.6299 14.2911 27.4023 14.9644 29.0912L16.8012 29.5485Z" />
                                    </svg>
                                </div>
                                <div class="single-img">
                                    <img src="{{ $settings['about_image_1'] ? Storage::url($settings['about_image_1']) : asset('assets/img/home4/about-img1.jpg') }}"
                                        alt="">
                                </div>
                            </div>
                            <div class="single-grp">
                                <div class="single-img two">
                                    <img src="{{ $settings['about_image_2'] ? Storage::url($settings['about_image_2']) : asset('assets/img/home4/about-img2.jpg') }}"
                                        alt="">
                                </div>
                                <div class="single-img three">
                                    <img src="{{ $settings['about_image_3'] ? Storage::url($settings['about_image_3']) : asset('assets/img/home4/about-img3.jpg') }}"
                                        alt="">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> --}}
    <!-- home4 About Section End-->

    @if ($packages->count() > 0)
        <!-- Things to Do Section - Enhanced with Unified CMS Cards -->
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
                                        src="{{ $settings['offer_slider_img_1'] ? Storage::url($settings['offer_slider_img_1']) : asset('assets/img/home4/home4-offer-slider-img1.jpg') }}"
                                        alt=""></a>
                            </div>
                            <div class="swiper-slide">
                                <a href="travel-package-details.html"><img
                                        src="{{ $settings['offer_slider_img_2'] ? Storage::url($settings['offer_slider_img_2']) : asset('assets/img/home4/home4-offer-slider-img2.jpg') }}"
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
                            <img src="{{ $settings['tripadvisor_logo'] ? Storage::url($settings['tripadvisor_logo']) : asset('assets/img/home1/icon/tripadvisor-logo.svg') }}"
                                alt="">
                            <div class="rating-area">
                                <span>Reviews</span>
                                <img src="{{ $settings['tripadvisor_stars'] ? Storage::url($settings['tripadvisor_stars']) : asset('assets/img/home1/icon/tripadvisor-start.svg') }}"
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
                            <img src="{{ $settings['why_feature_icon_1'] ? Storage::url($settings['why_feature_icon_1']) : asset('assets/img/home3/icon/destination-feature-icon1.svg') }}"
                                alt="">
                        </div>
                        <h5>{{ $settings['why_feature_1'] ?? 'Customizable Package.' }}</h5>
                    </div>
                </div>
                <div class="col-lg-3 col-md-4 col-sm-6 wow animate fadeInUp" data-wow-delay="400ms"
                    data-wow-duration="1500ms">
                    <div class="single-feature">
                        <div class="icon">
                            <img src="{{ $settings['why_feature_icon_2'] ? Storage::url($settings['why_feature_icon_2']) : asset('assets/img/home3/icon/destination-feature-icon2.svg') }}"
                                alt="">
                        </div>
                        <h5>{{ $settings['why_feature_2'] ?? '24/7 Support' }}</h5>
                    </div>
                </div>
                <div class="col-lg-3 col-md-4 col-sm-6 wow animate fadeInUp" data-wow-delay="600ms"
                    data-wow-duration="1500ms">
                    <div class="single-feature">
                        <div class="icon">
                            <img src="{{ $settings['why_feature_icon_3'] ? Storage::url($settings['why_feature_icon_3']) : asset('assets/img/home3/icon/destination-feature-icon3.svg') }}"
                                alt="">
                        </div>
                        <h5>{{ $settings['why_feature_3'] ?? 'Trusted by Thousands' }}</h5>
                    </div>
                </div>
                <div class="col-lg-3 col-md-4 col-sm-6 wow animate fadeInUp" data-wow-delay="800ms"
                    data-wow-duration="1500ms">
                    <div class="single-feature">
                        <div class="icon">
                            <img src="{{ $settings['why_feature_icon_4'] ? Storage::url($settings['why_feature_icon_4']) : asset('assets/img/home3/icon/destination-feature-icon4.svg') }}"
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
                <img src="{{ $settings['why_video_image'] ? Storage::url($settings['why_video_image']) : asset('assets/img/home4/why-choose-video-img.jpg') }}"
                    alt="">
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
                                <span>Need Help?</span>
                                <a href="tel:91345533865">+91 345 533 865</a>
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
                                            <img src="{{ $settings['testimonial_author_img_1'] ? Storage::url($settings['testimonial_author_img_1']) : asset('assets/img/home4/testimonial-author-img1.png') }}"
                                                alt="">
                                        </div>
                                    </div>
                                    <div class="swiper-slide">
                                        <div class="testimonial-author-img">
                                            <img src="{{ $settings['testimonial_author_img_2'] ? Storage::url($settings['testimonial_author_img_2']) : asset('assets/img/home4/testimonial-author-img2.png') }}"
                                                alt="">
                                        </div>
                                    </div>
                                    <div class="swiper-slide">
                                        <div class="testimonial-author-img">
                                            <img src="{{ $settings['testimonial_author_img_3'] ? Storage::url($settings['testimonial_author_img_3']) : asset('assets/img/home4/testimonial-author-img3.png') }}"
                                                alt="">
                                        </div>
                                    </div>
                                    <div class="swiper-slide">
                                        <div class="testimonial-author-img">
                                            <img src="{{ $settings['testimonial_author_img_4'] ? Storage::url($settings['testimonial_author_img_4']) : asset('assets/img/home4/testimonial-author-img4.png') }}"
                                                alt="">
                                        </div>
                                    </div>
                                    <div class="swiper-slide">
                                        <div class="testimonial-author-img">
                                            <img src="{{ $settings['testimonial_author_img_5'] ? Storage::url($settings['testimonial_author_img_5']) : asset('assets/img/home4/testimonial-author-img5.png') }}"
                                                alt="">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <img src="{{ $settings['testimonial_vector'] ? Storage::url($settings['testimonial_vector']) : asset('assets/img/home4/vector/home4-testimonial-vector.png') }}"
                alt="" class="vector">
        </div>
    @endif
    <!-- home4 Testimonial Section End-->

    <!-- home4 Counter Section Start-->
    {{-- <div class="home4-counter-section mb-100">
        <div class="container">
            <div class="counter-wrapper">
                <div class="single-counter">
                    <div class="content">
                        <svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M39.5961 15.81C38.1214 17.2519 40.2368 20.3608 36.9514 22.3862C35.0583 23.5533 38.0077 25.8843 36.9514 28.6109C36.7987 29.0049 36.7675 29.4023 36.987 29.8303C34.3992 31.822 31.1578 33.0065 27.6398 33.0065C26.7047 33.0065 25.7892 32.9228 24.9003 32.7625L24.5591 33.9508C25.5749 34.1416 26.6063 34.2374 27.6398 34.237C36.7906 34.237 44.209 26.8186 44.209 17.6679C44.209 8.51713 36.7906 1.09863 27.6398 1.09863C24.0648 1.09863 20.6088 2.24297 17.7454 4.37818C19.8919 7.68524 19.849 12.0313 17.6364 15.2956C18.4686 16.7537 19.665 16.797 20.1282 16.9774C21.8931 17.6654 23.2203 21.7429 24.3251 23.0528C25.9039 22.1413 27.6183 21.8503 29.3902 22.4059C29.0978 20.9689 27.4158 19.3471 26.9044 18.8961C25.0823 17.2898 25.8475 16.5076 27.2823 16.1104C28.9332 15.6532 31.4705 15.7057 31.9733 15.5987C33.1508 15.3482 33.4901 14.5201 32.5894 13.7393C31.5322 12.823 29.6808 11.8148 29.2499 10.9945C28.6541 9.8601 29.0869 9.52163 29.8556 9.28389C31.4176 8.80084 34.3657 8.73281 32.891 3.25178C38.0294 5.12385 41.8141 9.62587 42.7515 15.026C41.0332 15.0163 40.1027 15.3148 39.5961 15.81ZM6.48202 37.3493L1.40625 33.2101L3.25916 31.9816L8.22243 33.7703L25.2854 23.919C28.1145 22.2856 33.7222 24.5118 28.3539 27.6113L24.4524 29.8638L20.8244 42.4993L18.3959 43.9014L18.5658 33.213C18.5658 33.213 8.38195 38.8263 6.48202 37.3493ZM9.44719 1.09863C14.2276 1.09863 18.1034 4.97426 18.1034 9.7548C18.1034 14.5354 14.2277 18.411 9.44719 18.411C4.66673 18.411 0.791016 14.5353 0.791016 9.7548C0.791016 4.97435 4.66673 1.09863 9.44719 1.09863ZM4.16821 14.9772C4.53076 12.3859 6.75615 10.3914 9.44728 10.3914C12.1385 10.3914 14.3635 12.3858 14.7261 14.9772C16.0532 13.6358 16.8728 11.7911 16.8728 9.7548C16.8728 5.65356 13.5483 2.3291 9.4471 2.3291C5.34595 2.3291 2.02148 5.65356 2.02148 9.7548C2.02148 11.7911 2.84115 13.6357 4.16821 14.9772ZM6.98344 6.5076C6.98344 7.86841 8.08655 8.97152 9.44736 8.97152C10.8082 8.97152 11.9113 7.86841 11.9113 6.5076C11.9113 5.14714 10.8082 4.04402 9.44736 4.04402C8.08655 4.04411 6.98344 5.14714 6.98344 6.5076ZM8.08436 26.0411L10.5127 24.6391L18.2438 26.5637L13.7191 29.176L8.08436 26.0411ZM13.7464 24.1761C13.0102 22.6071 12.547 20.924 12.3768 19.1992C11.9812 19.3217 11.5781 19.4192 11.1702 19.4911C11.3316 20.9699 11.6927 22.4201 12.2436 23.8019L13.7464 24.1761Z" />
                        </svg>
                        <span>Tour Completed</span>
                        <h2><strong class="counter">26</strong>K+</h2>
                    </div>
                </div>
                <div class="single-counter two">
                    <div class="content">
                        <svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M35.1512 26.1785V22.8616H35.6609C37.2742 22.8616 38.5842 21.5516 38.5842 19.9449C38.5842 18.3317 37.2742 17.0216 35.6609 17.0216H30.3436V11.9947H37.0613C37.4227 11.9947 37.7066 11.7108 37.7066 11.3495C37.7069 11.2646 37.6904 11.1806 37.6581 11.1022C37.6257 11.0237 37.5782 10.9525 37.5182 10.8925C37.4583 10.8325 37.387 10.785 37.3086 10.7527C37.2302 10.7204 37.1461 10.7039 37.0613 10.7042H31.957C31.6214 5.76761 27.5108 1.85059 22.4839 1.85059C18.296 1.85059 14.6436 4.53503 13.4045 8.5359C13.0367 9.7233 13.5464 10.9945 14.6435 11.6269V17.0216H9.32619C8.94205 17.0208 8.56153 17.0958 8.20647 17.2424C7.85141 17.389 7.5288 17.6043 7.25718 17.8759C6.98555 18.1475 6.77026 18.4701 6.62367 18.8252C6.47707 19.1802 6.40206 19.5608 6.40294 19.9449C6.40294 21.5517 7.71287 22.8616 9.32619 22.8616H9.83604V26.1785C6.40303 27.8885 4.20251 31.4055 4.20251 35.2708V39.6008C4.20251 41.5561 5.79641 43.15 7.75171 43.15H37.2485C39.2038 43.15 40.7977 41.5561 40.7977 39.6008V35.2708C40.7976 31.4055 38.5907 27.8885 35.1512 26.1785ZM15.9341 11.9947H29.0531V17.2669C29.0531 19.0543 27.1688 21.5516 23.8778 24.12C23.4832 24.4304 22.9957 24.5991 22.4936 24.5991C21.9915 24.5991 21.504 24.4304 21.1094 24.12C20.335 23.5198 19.3607 22.6938 18.4572 21.7646L18.4507 21.7582C17.102 20.3643 15.9341 18.7382 15.9341 17.2669V11.9947ZM25.0393 24.8492V26.8109C25.0393 28.2177 23.897 29.3598 22.4968 29.3598C21.0577 29.3598 19.9479 28.1918 19.9479 26.8109V24.8428C21.7482 26.2882 23.3744 26.185 25.0393 24.8492ZM11.7461 41.8594H7.75163C6.50622 41.8594 5.4931 40.8463 5.4931 39.6008V35.2708C5.4931 31.5152 7.90007 27.9854 11.7461 26.798V41.8594ZM31.9505 41.8594H13.0367V26.5076C13.4691 26.4431 13.9143 26.3979 14.366 26.3979H18.6573V26.8109C18.6573 28.9017 20.3415 30.6505 22.4969 30.6505C24.6134 30.6505 26.3299 28.9275 26.3299 26.8109V26.3979H30.6276C31.0794 26.3979 31.5181 26.4431 31.9505 26.5076V41.8594ZM39.507 39.6008C39.507 40.8463 38.4938 41.8594 37.2484 41.8594H33.2411V26.798C37.0484 27.9725 39.507 31.4636 39.507 35.2708V39.6008Z" />
                        </svg>
                        <span>{{ $settings['counter_travel_experience_label'] ?? 'Travel Experience' }}</span>
                        <h2><strong class="counter">12</strong>+</h2>
                    </div>
                </div>
                <div class="single-counter three">
                    <div class="content">
                        <svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M38.0333 16.4395C38.3412 16.4394 38.6319 16.2974 38.8214 16.0547C39.0107 15.8121 39.0776 15.4959 39.003 15.1973L37.5909 9.54883C37.4796 9.10368 37.08 8.79105 36.6212 8.79102H8.37899C7.92011 8.79102 7.52055 9.10366 7.40926 9.54883L5.99715 15.1973C5.92256 15.4959 5.98936 15.812 6.17879 16.0547C6.3683 16.2974 6.65894 16.4395 6.96688 16.4395H38.0333ZM9.15926 10.791H35.8399L36.752 14.4395H8.24715L9.15926 10.791Z">
                                </path>
                                <g>
                                    <path
                                        d="M36.6209 10.791C36.9289 10.791 37.2195 10.649 37.409 10.4062C37.5984 10.1636 37.6652 9.84747 37.5907 9.54883V9.54688C37.5904 9.5458 37.5902 9.5441 37.5897 9.54199C37.5886 9.53763 37.5869 9.53098 37.5848 9.52246C37.5805 9.50533 37.5735 9.48005 37.5653 9.44727C37.5488 9.38137 37.525 9.28445 37.495 9.16406L36.6024 5.59375V5.59277C36.4298 4.90407 36.069 4.27658 35.5604 3.78125C35.0518 3.28608 34.4154 2.94228 33.7225 2.78809L23.9491 0.616211C23.5687 0.531687 23.2606 0.463331 23.0477 0.416016C22.9414 0.3924 22.8586 0.373787 22.8026 0.361328C22.7745 0.355098 22.7525 0.349873 22.7381 0.34668C22.7313 0.345176 22.7261 0.344541 22.7225 0.34375C22.721 0.343414 22.7195 0.342971 22.7186 0.342773H22.7166C22.5738 0.311048 22.4258 0.310103 22.283 0.341797V0.342773H22.2811C22.2802 0.342971 22.2787 0.343411 22.2772 0.34375C22.2736 0.34454 22.2683 0.345175 22.2616 0.34668C22.2472 0.349873 22.2251 0.355098 22.1971 0.361328C22.141 0.373787 22.0583 0.392397 21.952 0.416016C21.739 0.46333 21.431 0.531686 21.0506 0.616211L11.2772 2.78809C10.5843 2.94228 9.94787 3.28608 9.43929 3.78125C8.93063 4.27658 8.56983 4.90407 8.3973 5.59277V5.59375L7.50472 9.16406C7.47463 9.28445 7.45088 9.38137 7.43441 9.44727C7.42621 9.48005 7.41916 9.50533 7.41487 9.52246C7.41274 9.53098 7.41108 9.53763 7.40999 9.54199C7.40946 9.5441 7.40928 9.5458 7.40901 9.54688V9.54883C7.33443 9.84748 7.40123 10.1636 7.59066 10.4062C7.78016 10.649 8.07081 10.791 8.37874 10.791H36.6209ZM9.68734 8.67969C9.88241 7.89919 10.1299 6.91098 10.3377 6.0791L10.3719 5.95703C10.4633 5.67629 10.6224 5.42171 10.8348 5.21484C11.0775 4.97846 11.381 4.81382 11.7117 4.74023C13.444 4.35511 16.1954 3.74364 18.5135 3.22852C19.6725 2.97097 20.7232 2.73746 21.4842 2.56836C21.8645 2.48385 22.1726 2.41548 22.3856 2.36816C22.4272 2.35891 22.4656 2.35038 22.4998 2.34277C22.534 2.35037 22.5724 2.35891 22.6141 2.36816C22.827 2.41548 23.1352 2.48386 23.5155 2.56836C24.2765 2.73746 25.3272 2.97097 26.4862 3.22852C28.8043 3.74365 31.5557 4.35511 33.2879 4.74023L33.411 4.77246C33.6938 4.85644 33.9526 5.00811 34.1649 5.21484C34.4076 5.45122 34.5796 5.75046 34.6619 6.0791C34.8698 6.91098 35.1173 7.89919 35.3123 8.67969C35.3217 8.71716 35.3306 8.75454 35.3397 8.79102H9.65999C9.66911 8.75454 9.67797 8.71716 9.68734 8.67969Z">
                                    </path>
                                    <path
                                        d="M33.0908 24.9121C35.9799 24.9121 37.4763 24.1179 38.8564 23.3818C40.124 22.7058 41.2754 22.0879 43.6816 22.0879C44.086 22.0878 44.4506 21.8443 44.6054 21.4707C44.7602 21.0971 44.6746 20.6669 44.3886 20.3809L38.7402 14.7324C38.5527 14.5449 38.2983 14.4395 38.0332 14.4395H6.96676C6.70155 14.4395 6.44726 14.5449 6.25973 14.7324L0.611293 20.3809C0.325315 20.6669 0.23972 21.097 0.394496 21.4707C0.549318 21.8443 0.913924 22.0879 1.31832 22.0879C3.72449 22.0879 4.87591 22.7058 6.14352 23.3818C7.52358 24.1179 9.02 24.9121 11.9091 24.9121C14.7983 24.9121 16.2947 24.1179 17.6748 23.3818C18.9424 22.7058 20.0938 22.0879 22.5 22.0879C24.9061 22.0879 26.0576 22.7058 27.3252 23.3818C28.7052 24.1179 30.2017 24.9121 33.0908 24.9121ZM33.0908 22.9121C30.6846 22.9121 29.5332 22.2933 28.2656 21.6172C26.8856 20.8812 25.3889 20.0879 22.5 20.0879C19.6111 20.0879 18.1143 20.8812 16.7343 21.6172C15.4667 22.2933 14.3153 22.9121 11.9091 22.9121C9.50298 22.9121 8.35156 22.2933 7.08395 21.6172C6.11129 21.0984 5.08017 20.5533 3.54489 20.2754L7.38082 16.4395H37.6191L41.4541 20.2754C39.919 20.5534 38.8876 21.0984 37.915 21.6172C36.6475 22.2932 35.4968 22.9121 33.0908 22.9121Z">
                                    </path>
                                    <path
                                        d="M22.4994 44.6807C23.2757 44.6808 24.0347 44.4517 24.6801 44.0205C25.3255 43.5893 25.8285 42.976 26.1254 42.2588C26.4225 41.5417 26.5005 40.7525 26.349 39.9912C26.1976 39.2301 25.8236 38.5312 25.2748 37.9824L23.9135 36.6221L36.144 24.3916C36.5345 24.0011 36.5345 23.3671 36.144 22.9766C35.7535 22.5864 35.1204 22.5864 34.7299 22.9766L22.4994 35.208L10.2699 22.9766C9.87949 22.5864 9.24634 22.5864 8.85588 22.9766C8.46536 23.3671 8.46536 24.0011 8.85588 24.3916L21.0854 36.6221L19.725 37.9824C19.1762 38.5312 18.8023 39.2301 18.6508 39.9912C18.4994 40.7524 18.5765 41.5418 18.8735 42.2588L18.9946 42.5225C19.2986 43.1263 19.7549 43.6431 20.3197 44.0205C20.965 44.4515 21.7235 44.6807 22.4994 44.6807ZM22.4994 42.6816C22.1189 42.6817 21.7465 42.5688 21.4301 42.3574C21.1139 42.1461 20.8677 41.8455 20.7221 41.4941V41.4932C20.5765 41.1416 20.5376 40.755 20.6117 40.3818C20.686 40.0085 20.8699 39.6656 21.1391 39.3965L22.4994 38.0361L23.8608 39.3965C24.1299 39.6656 24.3129 40.0085 24.3871 40.3818C24.4613 40.755 24.4234 41.1416 24.2778 41.4932V41.4941C24.1321 41.8456 23.8851 42.146 23.5688 42.3574C23.2525 42.5687 22.8808 42.6817 22.5004 42.6816H22.4994Z">
                                    </path>
                                </g>
                            </g>
                        </svg>
                        <span>{{ $settings['counter_happy_traveler_label'] ?? 'Happy Traveler' }}</span>
                        <h2><strong class="counter">20</strong>K+</h2>
                    </div>
                </div>
                <div class="single-counter four">
                    <div class="content">
                        <svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M18.9117 14.3766C18.9382 14.3657 19.5657 14.1034 19.8841 14.4911C19.9487 14.5704 20.0284 14.6362 20.1186 14.6845C20.2088 14.7329 20.3077 14.7629 20.4096 14.7728C20.5114 14.7827 20.6142 14.7723 20.712 14.7423C20.8099 14.7122 20.9008 14.6631 20.9795 14.5977C21.139 14.4667 21.2398 14.2777 21.26 14.0723C21.2801 13.8669 21.2179 13.6619 21.0869 13.5023C20.2259 12.4536 18.8712 12.6849 18.2772 12.9542C18.0907 13.0387 17.9452 13.1936 17.8726 13.385C17.7999 13.5764 17.806 13.7887 17.8894 13.9757C18.0607 14.3681 18.5201 14.5448 18.9117 14.3766ZM24.5141 14.776C24.6292 14.7762 24.7429 14.7508 24.847 14.7016C24.9511 14.6525 25.0429 14.5808 25.1159 14.4919C25.4312 14.108 26.0463 14.3595 26.0876 14.3774C26.2748 14.4545 26.4847 14.4557 26.6727 14.3806C26.8608 14.3056 27.0122 14.1602 27.0949 13.9754C27.1775 13.7905 27.1849 13.5807 27.1154 13.3905C27.046 13.2003 26.9052 13.0447 26.7229 12.9566C26.128 12.6872 24.7741 12.456 23.9131 13.5047C23.8196 13.6185 23.7603 13.7566 23.7422 13.9029C23.7242 14.0491 23.748 14.1975 23.811 14.3307C23.874 14.4639 23.9735 14.5765 24.098 14.6553C24.2224 14.7342 24.3668 14.776 24.5141 14.776ZM19.7798 20.38C19.7074 20.4523 19.65 20.5381 19.6108 20.6325C19.5717 20.727 19.5515 20.8282 19.5515 20.9305C19.5515 21.0327 19.5717 21.134 19.6108 21.2284C19.65 21.3229 19.7074 21.4087 19.7798 21.4809C20.5295 22.2306 21.5151 22.6059 22.5 22.6059C23.4849 22.6059 24.4705 22.2306 25.2203 21.4809C25.2925 21.4086 25.3499 21.3228 25.389 21.2284C25.4281 21.1339 25.4483 21.0327 25.4483 20.9305C25.4483 20.8282 25.4281 20.727 25.389 20.6326C25.3499 20.5381 25.2925 20.4523 25.2203 20.38C25.148 20.3077 25.0622 20.2504 24.9677 20.2113C24.8733 20.1722 24.772 20.152 24.6698 20.152C24.5676 20.152 24.4664 20.1722 24.3719 20.2113C24.2775 20.2504 24.1917 20.3077 24.1194 20.38C23.6894 20.8085 23.1071 21.049 22.5 21.049C21.893 21.049 21.3107 20.8085 20.8806 20.38C20.8084 20.3077 20.7226 20.2503 20.6281 20.2111C20.5337 20.1719 20.4324 20.1518 20.3302 20.1518C20.2279 20.1518 20.1267 20.1719 20.0322 20.2111C19.9378 20.2503 19.852 20.3077 19.7798 20.38Z" />
                            <path
                                d="M40.9905 21.1917C40.5794 20.9148 40.106 20.7443 39.6127 20.6954C39.1195 20.6464 38.6218 20.7207 38.1643 20.9114L33.1349 22.9823C32.2085 22.2894 31.0562 21.9079 29.7093 21.8378C30.0285 21.0827 30.2076 20.2496 30.2309 19.3854C30.2777 19.3932 30.3166 19.3932 30.3633 19.3932C31.2431 19.3932 32.6912 19.0351 33.1739 16.7929C34.5363 16.1856 36.0078 15.189 36.1791 13.7176C36.3114 12.4875 35.5095 11.2574 33.7811 10.0584C33.6643 9.98834 33.5787 9.91049 33.5008 9.81706C33.3685 9.67692 33.2673 9.46671 33.1972 9.20979L32.3642 5.57397C31.9126 2.91134 27.5216 1.67344 23.9169 1.1051C22.9904 0.964965 22.0095 0.964965 21.0752 1.1051C17.4783 1.67344 13.0873 2.91134 12.6513 5.52726L11.8105 9.17865C11.7482 9.38886 11.6626 9.67692 11.5847 9.77813C11.5769 9.78592 11.5691 9.80149 11.5613 9.80928C11.5224 9.85599 11.4134 9.93384 11.2188 10.0662C9.49041 11.2574 8.6885 12.4875 8.82864 13.7176C8.99214 15.1968 10.4636 16.1856 11.8261 16.7929C12.3088 19.0351 13.7569 19.3932 14.6288 19.3932C14.6833 19.3932 14.7223 19.3932 14.769 19.3854C14.7923 20.2496 14.9714 21.0827 15.2906 21.8378C13.9437 21.9079 12.7915 22.2894 11.865 22.9823L6.83556 20.9114C5.9013 20.5221 4.85026 20.6311 4.00943 21.1917C3.59859 21.4679 3.26218 21.8412 3.02997 22.2785C2.79776 22.7157 2.67691 23.2035 2.67811 23.6986V28.8759C2.67811 29.9192 3.19974 30.869 4.0795 31.4218C4.56998 31.7332 5.13054 31.8889 5.68331 31.8889C6.12708 31.8889 6.57085 31.7955 6.99127 31.5931L9.31134 30.4875C9.35027 30.9547 9.3892 31.2583 9.40477 31.3206C8.75857 32.0836 8.33037 33.049 8.19802 34.1078L7.7776 37.4633C7.72303 37.9439 7.80689 38.43 8.0193 38.8644C8.23171 39.2989 8.56383 39.6637 8.97657 39.9157C13.3987 42.6095 17.9532 43.9876 22.5 44.0031C27.0467 43.9876 31.6012 42.6095 36.0311 39.908C36.872 39.3941 37.3391 38.4287 37.2145 37.4555L36.8019 34.1078C36.6695 33.049 36.2413 32.0836 35.5951 31.3206C35.6107 31.2116 35.6574 30.9235 35.6886 30.4875L38.0086 31.5931C38.4291 31.7955 38.8728 31.8889 39.3166 31.8889C39.8694 31.8889 40.4299 31.7332 40.9204 31.4218C41.8002 30.869 42.3218 29.9192 42.3218 28.8759V23.6986C42.3218 22.6865 41.8235 21.7522 40.9905 21.1917ZM32.8079 24.8041C34.1237 26.3534 34.2093 28.7981 34.1393 30.1528C34.1176 30.1368 34.0941 30.1238 34.0692 30.1138C34.0692 30.1138 28.0199 27.0775 27.5839 26.8517C27.1946 26.6181 26.8287 26.0498 26.5328 25.2712C27.4204 24.8041 28.1911 24.1579 28.7984 23.3716H29.25C30.8226 23.3716 31.9905 23.8465 32.8079 24.8041ZM23.3719 24.4927H21.628C18.7006 24.4927 16.3105 22.1026 16.3105 19.1752V12.5576C17.7352 11.8024 22.9904 9.4745 28.6894 12.5576V19.1752C28.6894 22.1026 26.2993 24.4927 23.3719 24.4927ZM26.128 27.638C26.0501 28.004 24.8122 28.6424 22.5 28.6424C20.1954 28.6424 18.9576 28.0117 18.8797 27.638C19.3546 27.1242 19.6894 26.4624 19.923 25.824C20.4679 25.9642 21.0441 26.0498 21.628 26.0498H23.3719C23.9558 26.0498 24.532 25.9642 25.077 25.824C25.3183 26.4624 25.6609 27.1242 26.128 27.638ZM31.7102 16.1467C31.461 17.8673 30.6903 17.875 30.2465 17.8361V14.8543L30.4489 14.7531C31.0873 14.4339 31.4844 14.4339 31.5856 14.5039C31.6012 14.5195 31.9126 14.7608 31.7102 16.1467ZM14.7534 17.8361C14.3252 17.875 13.5389 17.8828 13.2897 16.1467C13.0873 14.7608 13.3987 14.5195 13.4143 14.5039C13.5155 14.4261 13.9048 14.4339 14.5432 14.7453L14.7534 14.8543V17.8361ZM15.1271 11.4364C14.8935 11.5844 14.7534 11.8335 14.7534 12.0982V13.1415C13.6245 12.721 12.9082 12.9468 12.4956 13.2505C12.0129 13.6008 11.7404 14.1769 11.6859 14.9711C10.8996 14.5117 10.4247 14.0057 10.378 13.5463C10.3079 12.9468 10.9229 12.1605 12.1608 11.3041C12.3321 11.1873 12.4878 11.0705 12.628 10.9304C18.9186 7.07657 26.5173 7.09214 32.4809 10.9849C32.6133 11.1095 32.7379 11.234 32.8936 11.343C34.077 12.1605 34.692 12.9468 34.6297 13.5385C34.5752 14.0057 34.1003 14.5039 33.314 14.9711C33.2517 14.1769 32.987 13.6008 32.5043 13.2505C32.0917 12.9468 31.3832 12.721 30.2465 13.1415V12.0982C30.2457 11.9623 30.2093 11.8291 30.141 11.7116C30.0727 11.5941 29.9749 11.4966 29.8572 11.4287C22.3442 7.05322 15.4152 11.2574 15.1271 11.4364ZM12.192 24.8041C13.0094 23.8465 14.1773 23.3716 15.7499 23.3716H16.2015C16.8115 24.1602 17.5842 24.8081 18.4671 25.2712C18.1868 26.0342 17.8131 26.6104 17.416 26.8517C16.9878 27.0697 10.9307 30.1138 10.9307 30.1138C10.9074 30.1216 10.884 30.1372 10.8607 30.1528C10.7906 28.8059 10.8684 26.3534 12.192 24.8041ZM12.1998 39.8924C11.3752 39.4944 10.5699 39.0579 9.78626 38.5844C9.45927 38.382 9.2802 38.0239 9.32691 37.6502L9.73954 34.3024C9.89525 33.0801 10.6115 32.0135 11.6236 31.5074C12.0363 31.2972 12.4178 31.1026 12.7837 30.9235C12.0596 33.1502 12.1141 37.5957 12.1998 39.8924ZM27.7007 41.5118L27.6072 41.7998C25.91 42.2203 24.205 42.4382 22.5 42.446C20.8027 42.4382 19.0899 42.2203 17.3927 41.7998L17.2915 41.4884C15.9446 37.6891 16.9178 31.8656 17.4005 29.5455C17.5095 29.055 17.5795 28.7669 17.5795 28.7591C17.5951 28.6891 17.5951 28.6268 17.5951 28.5567C17.852 28.9616 18.3269 29.382 19.1911 29.7012C20.0787 30.0204 21.2543 30.1995 22.5 30.1995C24.4385 30.1995 26.634 29.7557 27.4048 28.5723C27.4048 28.6346 27.4048 28.6969 27.4204 28.7591C27.4204 28.7669 27.4904 29.055 27.5994 29.5377C28.0821 31.8656 29.0553 37.6891 27.7007 41.5118ZM35.673 37.6502C35.7197 38.0239 35.5406 38.382 35.2136 38.5844C34.4117 39.0671 33.6098 39.4953 32.8001 39.8924C32.8858 37.5957 32.9403 33.1268 32.2162 30.9235C32.5822 31.1026 32.9636 31.2972 33.3763 31.4996C34.3884 32.0135 35.1047 33.0801 35.2604 34.3024L35.673 37.6502Z" />
                            <path
                                d="M26.8287 16.4349C26.8287 17.0033 26.3616 17.4704 25.7933 17.4704C25.2249 17.4704 24.7656 17.0033 24.7656 16.4349C24.7656 15.8666 25.2249 15.4072 25.7933 15.4072C26.3616 15.4072 26.8287 15.8666 26.8287 16.4349ZM20.2344 16.4349C20.2344 17.0033 19.7751 17.4704 19.2067 17.4704C18.6384 17.4704 18.1713 17.0033 18.1713 16.4349C18.1713 15.8666 18.6384 15.4072 19.2067 15.4072C19.7751 15.4072 20.2344 15.8666 20.2344 16.4349Z" />
                        </svg>
                        <span>Retention Rate</span>
                        <h2><strong class="counter">98</strong>%</h2>
                    </div>
                </div>
            </div>
        </div>
    </div> --}}
    <!-- home4 Counter Section End-->

    <!-- home4 location search Section Start-->
    {{-- <div class="home1-location-search-section two mb-100">
        <div class="container">
            <div class="location-search-wrapper wow animate fadeInUp" data-wow-delay="200ms" data-wow-duration="1500ms">
                <div class="location-search-content">
                    <h2>{{ $settings['custom_travel_heading'] ?? 'Customize Your Travel Package!' }}</h2>
                    <form class="location-search-area">
                        <div class="search-area">
                            <div class="dropdown" id="search_vendor">
                                <svg width="18" height="18" viewBox="0 0 18 18"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <g>
                                        <path
                                            d="M12.5944 8.99968C12.5944 10.9878 10.9826 12.5996 8.99443 12.5996C7.00627 12.5996 5.39465 10.9878 5.39465 8.99968C5.39465 7.01152 7.00627 5.3999 8.99443 5.3999C10.9826 5.3999 12.5944 7.01152 12.5944 8.99968Z" />
                                        <path
                                            d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                                    </g>
                                </svg>
                                <input type="text" class="dropdown-search" placeholder="Select Your Location">
                                <ul class="dropdown-list">
                                    <li class="not-found" style="display: none;">Results Not Found!</li>
                                    <li>Cox's Bazar, BD</li>
                                    <li>Bangkok, TH</li>
                                    <li>Dubai, AE</li>
                                    <li>Singapore, SG</li>
                                    <li>Paris, FR</li>
                                    <li>London, UK</li>
                                    <li>New York, US</li>
                                    <li>Toronto, CA</li>
                                    <li>Male, MV</li>
                                    <li>Tokyo, JP</li>
                                    <li>Kuala Lumpur, MY</li>
                                    <li>Delhi, IN</li>
                                </ul>
                            </div>
                            <a href="travel-package-01.html" class="primary-btn1 black-bg">
                                <span>
                                    Search Now
                                </span>
                                <span>
                                    Search Now
                                </span>
                            </a>
                        </div>
                    </form>
                    <ul>
                        <li>
                            <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="9" cy="9" r="8.5" />
                                <path
                                    d="M13.6193 7.07207L8.05903 12.6354C7.97043 12.721 7.85813 12.7654 7.74593 12.7654C7.68772 12.7655 7.63008 12.754 7.57632 12.7317C7.52256 12.7094 7.47376 12.6767 7.43272 12.6354L4.38073 9.58337C4.20642 9.41197 4.20642 9.13137 4.38073 8.95707L5.45912 7.87567C5.62462 7.71027 5.92002 7.71027 6.08552 7.87567L7.74593 9.53607L11.9146 5.36438C11.9557 5.32322 12.0045 5.29055 12.0581 5.26825C12.1118 5.24594 12.1694 5.23443 12.2275 5.23438C12.3456 5.23438 12.4579 5.28168 12.5406 5.36438L13.619 6.44587C13.7936 6.62017 13.7936 6.90077 13.6193 7.07207Z" />
                            </svg>
                            Make Your Favourite Package
                        </li>
                        <li>
                            <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="9" cy="9" r="8.5" />
                                <path
                                    d="M13.6193 7.07207L8.05903 12.6354C7.97043 12.721 7.85813 12.7654 7.74593 12.7654C7.68772 12.7655 7.63008 12.754 7.57632 12.7317C7.52256 12.7094 7.47376 12.6767 7.43272 12.6354L4.38073 9.58337C4.20642 9.41197 4.20642 9.13137 4.38073 8.95707L5.45912 7.87567C5.62462 7.71027 5.92002 7.71027 6.08552 7.87567L7.74593 9.53607L11.9146 5.36438C11.9557 5.32322 12.0045 5.29055 12.0581 5.26825C12.1118 5.24594 12.1694 5.23443 12.2275 5.23438C12.3456 5.23438 12.4579 5.28168 12.5406 5.36438L13.619 6.44587C13.7936 6.62017 13.7936 6.90077 13.6193 7.07207Z" />
                            </svg>
                            {{ $settings['custom_tours_label'] ?? 'Easily Customize Tours' }}
                        </li>
                        <li>
                            <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="9" cy="9" r="8.5" />
                                <path
                                    d="M13.6193 7.07207L8.05903 12.6354C7.97043 12.721 7.85813 12.7654 7.74593 12.7654C7.68772 12.7655 7.63008 12.754 7.57632 12.7317C7.52256 12.7094 7.47376 12.6767 7.43272 12.6354L4.38073 9.58337C4.20642 9.41197 4.20642 9.13137 4.38073 8.95707L5.45912 7.87567C5.62462 7.71027 5.92002 7.71027 6.08552 7.87567L7.74593 9.53607L11.9146 5.36438C11.9557 5.32322 12.0045 5.29055 12.0581 5.26825C12.1118 5.24594 12.1694 5.23443 12.2275 5.23438C12.3456 5.23438 12.4579 5.28168 12.5406 5.36438L13.619 6.44587C13.7936 6.62017 13.7936 6.90077 13.6193 7.07207Z" />
                            </svg>
                            Enjoy Your Trip
                        </li>
                    </ul>
                    <div class="contact-area">
                        <span>{{ $settings['tour_guide_label'] ?? 'Meet Our Local Tour Guider!' }}</span>
                        <a href="contact.html">Contact Now
                            <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                    stroke-width="1.5" stroke-linecap="round"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div> --}}
    <!-- home4 location search Section End-->

    @if ($inspirations->count() > 0)
        <!-- Independent Services Section - Enhanced with Unified CMS Cards -->
        <x-cms-section :title="$settings['inspirations_section_title'] ?? 'Independent Services'" :description="$settings['inspirations_section_description'] ??
            'Professional transportation and travel services designed to meet your unique needs'" :items="$inspirations" type="independent-services" :showPrice="true"
            :showDuration="false" :showRating="false" viewAllText="View All Services" sectionId="independent-services-section"
            :limit="3" customTemplate="blog-card2" />
    @endif

    @if ($blogs->count() > 0)
        <!-- Travel Blog Section - Enhanced with Unified CMS Cards -->
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
            <img src="{{ $settings['faq_section_vector'] ? Storage::url($settings['faq_section_vector']) : asset('assets/img/home4/vector/faq-section-vector.svg') }}"
                alt="" class="vector">
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

