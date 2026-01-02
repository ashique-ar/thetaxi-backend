@extends('layouts.app')

@section('content')
    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ s3_asset($settings['about_breadcrumb_image'] ?? 'assets/img/innerpages/breadcrumb-bg2.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $settings['about_hero_heading'] ?? 'About TheTaxi' }}</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>{{ $settings['about_hero_subheading'] ?? 'About TheTaxi' }}</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- About Section Start-->
    <div class="about-section pt-100 mb-100">
        <div class="container">
            <div class="about-wrapper">
                <div class="row align-items-center justify-content-between">
                    <div class="col-xl-6 col-lg-7 wow animate fadeInLeft" data-wow-delay="200ms" data-wow-duration="1500ms">
                        <div class="about-content">
                            <div class="section-title">
                                <h2>{{ $settings['about_section_title'] ?? "Why We're Best Agency" }}</h2>
                                <h4>{{ $settings['about_section_subtitle'] ?? 'Welcome to TheTaxi Travel Agency – Your Gateway to Unforgettable Journeys!' }}
                                </h4>
                                <p>{{ $settings['about_paragraph_1'] ?? 'TheTaxi Travel Agency is a trusted name in the travel industry, offering seamless travel planning, personalized itineraries, and unforgettable adventures. With years of experience and a network of global partners, we ensure a hassle-free and memorable journey for every traveler.' }}
                                </p>
                                <p>{{ $settings['about_paragraph_2'] ?? "We believe that travel is more than just moving from one place to another—it's about discovering new cultures, creating unforgettable experiences, and making lifelong memories." }}
                                </p>
                            </div>
                            <div class="founder-area">
                                <img src="{{ $settings['founder_signature'] ? Storage::url($settings['founder_signature']) : asset('assets/img/innerpages/about-page-founder-signature.png') }}"
                                    alt="{{ $settings['founder_name'] ?? 'Founder Signature' }}">
                                <div class="founder-info">
                                    <h6>{{ $settings['founder_name'] ?? 'Robert Harringson' }}</h6>
                                    <span>{{ $settings['founder_title'] ?? 'Founder at TheTaxi' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5 d-lg-block d-none wow animate fadeInRight" data-wow-delay="200ms"
                        data-wow-duration="1500ms">
                        <div class="about-img">
                            <img src="{{ s3_asset($settings['about_hero_image'] ?? 'assets/img/home3/about-img.png') }}"
                                alt="{{ $settings['about_hero_heading'] ?? 'About Us' }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- About Section End-->

    <!-- home1 service Section Start-->
    <div class="home1-service-section mb-100">
        <div class="container">
            <div class="service-wrapper">
                <div class="row justify-content-center wow animate fadeInDown" data-wow-delay="200ms"
                    data-wow-duration="1500ms">
                    <div class="col-lg-9">
                        <div class="section-title">
                            <h2>{{ $settings['about_services_title'] ?? "We're Providing Best Service Ever!" }}</h2>
                            <svg height="6" viewBox="0 0 872 6" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M5 2.5L0 0.113249V5.88675L5 3.5V2.5ZM867 3.5L872 5.88675V0.113249L867 2.5V3.5ZM4.5 3.5H867.5V2.5H4.5V3.5Z" />
                            </svg>
                        </div>
                    </div>
                </div>
                @php
                    $services = [];
                    try {
                        $raw = $settings['about_services'] ?? null;
                        if ($raw) {
                            $services = is_array($raw) ? $raw : json_decode($raw, true) ?? [];
                        }
                    } catch (\Throwable $e) {
                        $services = [];
                    }

                    // Fallback to legacy individual service keys if array is empty
                    if (empty($services)) {
                        for ($i = 1; $i <= 4; $i++) {
                            $titleKey = "service_{$i}_title";
                            $descKey = "service_{$i}_description";
                            if (!empty($settings[$titleKey]) || !empty($settings[$descKey])) {
                                $services[] = [
                                    'title' => $settings[$titleKey] ?? '',
                                    'description' => $settings[$descKey] ?? '',
                                    'icon' => $settings["service_{$i}_icon"] ?? '',
                                ];
                            }
                        }
                    }
                @endphp
                <ul class="service-list wow animate fadeInUp" data-wow-delay="200ms" data-wow-duration="1500ms">
                    @foreach ($services as $s)
                        <li class="single-service">
                            <div class="icon">{!! $s['icon'] ?? '' !!}</div>
                            <div class="content">
                                <h4>{{ $s['title'] ?? '' }}</h4>
                                <p>{{ $s['description'] ?? '' }}</p>
                            </div>
                        </li>
                    @endforeach
                    <li class="single-service">
                        <div class="icon">
                            <svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M15 0C21.4662 0 26.7081 5.24194 26.7081 11.7081C26.7081 18.1743 21.4662 23.4163 15 23.4163C8.53375 23.4163 3.29187 18.1743 3.29187 11.7081C3.29187 5.24194 8.53375 0 15 0ZM23.7899 20.886C22.1639 22.4438 20.1246 23.5736 17.8531 24.0947L19.8539 28.7401L22.0141 25.2591L26.0274 26.081L23.7899 20.886ZM12.758 24.2191C10.4637 23.8108 8.38243 22.7867 6.69112 21.324L3.97262 27.1631L8.00831 26.4579L10.0666 30L12.758 24.2191ZM15.1981 7.58544C15.1294 7.40431 14.8707 7.40431 14.8019 7.58544L13.7848 10.2644C13.7706 10.3033 13.7451 10.337 13.7117 10.3613C13.6782 10.3856 13.6383 10.3994 13.597 10.4009L10.7348 10.5404C10.5401 10.5498 10.4604 10.7951 10.6124 10.9172L12.8459 12.7124C12.8785 12.7379 12.9027 12.7726 12.9154 12.8119C12.9282 12.8512 12.929 12.8934 12.9177 12.9332L12.1659 15.6984C12.1147 15.8865 12.3233 16.0381 12.4864 15.9313L14.8839 14.3618C14.9182 14.3387 14.9586 14.3264 15 14.3264C15.0413 14.3264 15.0817 14.3387 15.116 14.3618L17.5136 15.9313C17.6767 16.0381 17.8853 15.8865 17.8341 15.6984L17.0823 12.9333C17.071 12.8935 17.0717 12.8513 17.0845 12.8119C17.0973 12.7726 17.1215 12.738 17.154 12.7125L19.3876 10.9172C19.5395 10.7951 19.4598 10.5499 19.2652 10.5404L16.403 10.4009C16.3617 10.3994 16.3218 10.3856 16.2883 10.3613C16.2549 10.337 16.2294 10.3033 16.2152 10.2645L15.1981 7.58544ZM16.1065 3.43C15.4404 2.93137 14.5596 2.93137 13.8935 3.43C12.1593 4.72812 12.5526 4.58494 10.3897 4.70525C9.55893 4.75144 8.88418 5.31763 8.69443 6.12775C8.20037 8.23687 8.40962 7.87438 6.83012 9.35688C6.22343 9.92631 6.0705 10.7937 6.44587 11.5363C7.42312 13.4695 7.35043 13.0574 7.09337 15.2083C6.99462 16.0344 7.43506 16.7972 8.19987 17.1248C10.1912 17.9776 9.87056 17.7085 11.0562 19.5215C11.5116 20.2179 12.3392 20.5191 13.1357 20.2784C15.2093 19.6517 14.7908 19.6517 16.8644 20.2784C17.6609 20.5191 18.4886 20.2178 18.9439 19.5215C20.1296 17.7086 19.809 17.9776 21.8002 17.1248C22.5651 16.7972 23.0055 16.0344 22.9067 15.2083C22.6497 13.0573 22.577 13.4695 23.5542 11.5363C23.9296 10.7937 23.7766 9.92631 23.17 9.35688C21.5904 7.87444 21.7997 8.23687 21.3057 6.12775C21.1159 5.31763 20.4412 4.7515 19.6104 4.70525C17.4474 4.58494 17.8407 4.72806 16.1065 3.43Z" />
                            </svg>
                        </div>
                        <div class="content">
                            <h4>{{ $settings['service_1_title'] ?? 'Local Guidance' }}</h4>
                            <p>{{ $settings['service_1_description'] ?? 'Travel agencies have experienced professionals guidance.' }}
                            </p>
                        </div>
                    </li>
                    <li class="single-service">
                        <div class="icon">
                            <svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M20.4896 4.14404C19.8384 3.81275 19.0419 4.07233 18.7107 4.72379L8.87091 24.0772C8.53971 24.7285 8.79929 25.5249 9.45066 25.8561C9.63601 25.9507 9.84112 26 10.0492 26C10.5313 26 10.996 25.7356 11.2296 25.2763L21.0694 5.92297C21.4006 5.27161 21.141 4.47523 20.4896 4.14404ZM13.4243 10.5172C13.4243 7.75042 11.1733 5.49943 8.40653 5.49943C5.63991 5.49943 3.38892 7.75042 3.38892 10.5172C3.38892 13.2839 5.63991 15.5349 8.40662 15.5349C11.1734 15.5349 13.4243 13.2839 13.4243 10.5172ZM8.40662 12.8889C7.09894 12.8889 6.03496 11.8249 6.03496 10.5172C6.03496 9.20945 7.09894 8.14548 8.40662 8.14548C9.71438 8.14548 10.7784 9.20937 10.7784 10.5172C10.7783 11.8249 9.71438 12.8889 8.40662 12.8889ZM21.5934 14.4653C18.8266 14.4653 16.5756 16.7163 16.5756 19.483C16.5756 22.2497 18.8265 24.5007 21.5934 24.5007C24.3601 24.5007 26.6111 22.2498 26.6111 19.483C26.6111 16.7162 24.3602 14.4653 21.5934 14.4653ZM21.5934 21.8546C20.2856 21.8546 19.2216 20.7906 19.2216 19.4829C19.2216 18.1752 20.2856 17.1113 21.5934 17.1113C22.901 17.1113 23.965 18.1752 23.965 19.4829C23.965 20.7906 22.9011 21.8546 21.5934 21.8546Z" />
                            </svg>
                        </div>
                        <div class="content">
                            <h4>{{ $settings['service_2_title'] ?? 'Deals & Discounts' }}</h4>
                            <p>{{ $settings['service_2_description'] ?? 'Agencies have special discounts on flights, hotels, & packages.' }}
                            </p>
                        </div>
                    </li>
                    <li class="single-service">
                        <div class="icon">
                            <svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M27.6977 12.3954C27.6977 11.446 27.32 10.5343 26.6493 9.86234C25.9771 9.19137 25.0661 8.81436 24.1163 8.81396H5.88376C4.93437 8.81396 4.02274 9.19164 3.35074 9.86234C2.67978 10.5346 2.30277 11.4455 2.30237 12.3954V25.4186C2.30237 26.368 2.68004 27.2796 3.35074 27.9516C4.02303 28.6226 4.93394 28.9996 5.88376 29H24.1163C25.0657 29 25.9773 28.6223 26.6493 27.9516C27.3203 27.2794 27.6973 26.3684 27.6977 25.4186V12.3954ZM25.7442 12.3954V25.4186C25.7435 25.8502 25.5718 26.2638 25.2667 26.569C24.9615 26.8741 24.5479 27.0458 24.1163 27.0465H5.88376C5.45223 27.0458 5.03856 26.8741 4.73342 26.569C4.42828 26.2638 4.25655 25.8502 4.25586 25.4186V12.3954C4.25655 11.9638 4.42828 11.5502 4.73342 11.245C5.03856 10.9399 5.45223 10.7681 5.88376 10.7675H24.1163C24.5479 10.7681 24.9615 10.9399 25.2667 11.245C25.5718 11.5502 25.7435 11.9638 25.7442 12.3954Z" />
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M15.5497 5.9527L16.7986 3.27511L23.2907 6.30172L21.8555 9.37781C21.746 9.61268 21.7343 9.88143 21.823 10.1249C21.9116 10.3684 22.0934 10.5667 22.3283 10.6762C22.5631 10.7857 22.8319 10.7974 23.0754 10.7088C23.3189 10.6201 23.5172 10.4384 23.6267 10.2035L25.4734 6.24181C25.5826 6.00725 25.5944 5.73893 25.506 5.49573C25.4176 5.25254 25.2363 5.05436 25.0019 4.94469L16.7387 1.09111C16.504 0.98194 16.2355 0.970407 15.9923 1.05905C15.7491 1.14769 15.551 1.32926 15.4416 1.56386L13.7798 5.12832C13.7206 5.24516 13.6856 5.37271 13.6767 5.50338C13.6678 5.63405 13.6853 5.76517 13.7281 5.88894C13.7709 6.01271 13.8382 6.12661 13.9259 6.22386C14.0137 6.32111 14.1201 6.39972 14.2388 6.45502C14.3575 6.51031 14.4861 6.54117 14.617 6.54575C14.7479 6.55033 14.8784 6.52855 15.0007 6.48168C15.123 6.43482 15.2346 6.36384 15.3289 6.27296C15.4232 6.18209 15.4983 6.07317 15.5497 5.9527Z" />
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M7.66406 10.2035L10.2869 4.57748L16.779 7.60409L15.9521 9.37785C15.8494 9.61152 15.8424 9.87607 15.9323 10.1149C16.0223 10.3537 16.2022 10.5478 16.4334 10.6557C16.6647 10.7636 16.929 10.7767 17.1698 10.6922C17.4106 10.6077 17.6088 10.4323 17.7219 10.2035L18.9617 7.54418C19.071 7.30962 19.0827 7.04129 18.9943 6.7981C18.9059 6.55491 18.7247 6.35673 18.4903 6.24706L10.227 2.39348C9.99231 2.28431 9.72386 2.27278 9.48064 2.36142C9.23742 2.45006 9.03934 2.63163 8.92992 2.86623L5.89289 9.37785C5.7834 9.61273 5.7717 9.88147 5.86035 10.125C5.94901 10.3685 6.13077 10.5668 6.36564 10.6763C6.60051 10.7858 6.86925 10.7975 7.11275 10.7088C7.35626 10.6202 7.55456 10.4384 7.66406 10.2035ZM27.6977 15C27.6977 14.7409 27.5948 14.4925 27.4116 14.3093C27.2285 14.1262 26.98 14.0232 26.721 14.0232H21.5104C20.5605 14.0232 19.6496 14.4006 18.978 15.0722C18.3063 15.7439 17.929 16.6548 17.929 17.6046V20.2093C17.929 21.1591 18.3063 22.0701 18.978 22.7417C19.6496 23.4134 20.5605 23.7907 21.5104 23.7907H26.721C26.98 23.7907 27.2285 23.6878 27.4116 23.5046C27.5948 23.3214 27.6977 23.073 27.6977 22.8139V15ZM25.7442 15.9767V21.8372H21.5104C20.6118 21.8372 19.8825 21.1079 19.8825 20.2093V17.6046C19.8825 16.706 20.6118 15.9767 21.5104 15.9767H25.7442Z" />
                                <path
                                    d="M22.1628 20.2094C22.8821 20.2094 23.4651 19.6263 23.4651 18.9071C23.4651 18.1878 22.8821 17.6047 22.1628 17.6047C21.4435 17.6047 20.8605 18.1878 20.8605 18.9071C20.8605 19.6263 21.4435 20.2094 22.1628 20.2094Z" />
                            </svg>
                        </div>
                        <div class="content">
                            <h4>{{ $settings['service_3_title'] ?? 'Saves Money' }}</h4>
                            <p>{{ $settings['service_3_description'] ?? 'Avoids hidden fees & tourist traps, Multi-destination & budget-friendly options.' }}
                            </p>
                        </div>
                    </li>
                </ul>
                <div class="bottom-area d-flex justify-content-center wow animate fadeInUp" data-wow-delay="200ms"
                    data-wow-duration="1500ms">
                    <div class="batch">
                        <span>Flat 30% Discounts All Packages</span>
                        <a href="travel-package-01.html">Check Offer
                            <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                    stroke-width="1.5" stroke-linecap="round" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- home1 service Section End-->

    <!-- About Page Journey Section Start-->
    <div class="about-page-journey-section mb-100">
        <div class="container">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-lg-8">
                    <div class="section-title text-center">
                        <h2>{{ $settings['about_journey_title'] ?? 'Behind The Journey' }}</h2>
                        <p>{{ $settings['about_journey_description'] ?? 'With years of experience in the travel industry, we specialize in crafting personalized journeys.' }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="jouney-content-wrapper">
                <div class="nav-area mb-50">
                    <div class="nav nav-pills" id="pills-tab" role="tablist">
                        <div class="swiper about-page-journey-slider">
                            <div class="swiper-wrapper">
                                @php
                                    $journeyItems = [];
                                    try {
                                        $raw = $settings['about_journey_items'] ?? null;
                                        if ($raw) {
                                            $journeyItems = is_array($raw) ? $raw : json_decode($raw, true) ?? [];
                                        }
                                    } catch (\Throwable $e) {
                                        $journeyItems = [];
                                    }

                                    // fallback sample items if empty
                                    if (empty($journeyItems)) {
                                        $journeyItems = [
                                            [
                                                'title' => '1986',
                                                'description' => 'The first-ever travel agency was founded...,',
                                                'image' => asset('assets/img/innerpages/about-page-journey-img1.jpg'),
                                            ],
                                            [
                                                'title' => '1996',
                                                'description' => 'A new era of exploration...',
                                                'image' => asset('assets/img/innerpages/about-page-journey-img2.jpg'),
                                            ],
                                            [
                                                'title' => '2006',
                                                'description' => 'Industry innovations...',
                                                'image' => asset('assets/img/innerpages/about-page-journey-img3.jpg'),
                                            ],
                                        ];
                                    }
                                @endphp

                                @foreach ($journeyItems as $i => $item)
                                    <div class="swiper-slide">
                                        <div class="nav-item" role="presentation">
                                            <div class="nav-link {{ $i == 0 ? 'active' : '' }}"
                                                id="pills-{{ $i + 1 }}-tab" data-bs-toggle="pill"
                                                data-bs-target="#pills-{{ $i + 1 }}" role="tab"
                                                aria-controls="pills-{{ $i + 1 }}"
                                                aria-selected="{{ $i == 0 ? 'true' : 'false' }}">
                                                <img src="{{ isset($item['image']) && $item['image'] ? (preg_match('/^https?:\/\//', $item['image']) || substr($item['image'], 0, 1) === '/' ? $item['image'] : s3_asset($item['image'])) : asset('assets/img/innerpages/about-page-journey-img' . ($i + 1) . '.jpg') }}"
                                                    alt="">
                                                <h4>{{ $item['title'] ?? '' }}</h4>
                                            </div>
                                            <span class="dot"></span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <svg class="line" height="6" viewBox="0 0 1320 6" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M5 2.5L0 0.113249V5.88675L5 3.5V2.5ZM1315 3.5L1320 5.88675V0.113249L1315 2.5V3.5ZM4.5 3.5H1315.5V2.5H4.5V3.5Z" />
                    </svg>
                </div>
                <div class="row justify-content-center">
                    <div class="col-xl-8 col-lg-10">
                        <div class="tab-content" id="pills-tabContent">
                            @php
                                try {
                                    $raw = $settings['about_journey_items'] ?? null;
                                    $journeyItems = $raw ? (is_array($raw) ? $raw : json_decode($raw, true) ?? []) : [];
                                } catch (\Throwable $e) {
                                    $journeyItems = [];
                                }

                                if (empty($journeyItems)) {
                                    $journeyItems = [
                                        [
                                            'title' => '1986 – The Birth of Travel Agencies',
                                            'description' =>
                                                'The first-ever travel agency was founded by Thomas Cook in England. He organized group trips, starting with a railway excursion for 500 people. Thomas Cook expanded his services internationally, arranging trips to Paris and beyond. He introduced the first-ever travel brochure, guiding travelers on destinations & routes.',
                                        ],
                                        [
                                            'title' => '1996 – A New Era of Exploration',
                                            'description' => 'A new era of exploration description placeholder.',
                                        ],
                                        [
                                            'title' => '2006 – Industry Innovations',
                                            'description' => 'Industry innovations description placeholder.',
                                        ],
                                    ];
                                }
                            @endphp

                            @foreach ($journeyItems as $i => $item)
                                <div class="tab-pane fade {{ $i == 0 ? 'show active' : '' }}"
                                    id="pills-{{ $i + 1 }}" role="tabpanel"
                                    aria-labelledby="pills-{{ $i + 1 }}-tab">
                                    <h4>{{ $item['title'] ?? '' }}</h4>
                                    <p>{!! nl2br(e($item['description'] ?? '')) !!}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
    <!-- About Page Journey Section End-->

    <!-- Why Choose Visa Section Start-->
    <div class="about-why-choose-section mb-100">
        <div class="container">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-lg-8">
                    <div class="section-title text-center">
                        <h2>{{ $settings['about_why_travel_title'] ?? 'Why Travel with Us?' }}</h2>
                        <p>{{ $settings['about_why_travel_description'] ?? "We specialize in crafting personalized journeys that suit every traveler's dream." }}
                        </p>
                    </div>
                </div>
            </div>
            @php
                $whyCards = [];
                try {
                    $raw = $settings['about_why_cards'] ?? null;
                    if ($raw) {
                        $whyCards = is_array($raw) ? $raw : json_decode($raw, true) ?? [];
                    }
                } catch (\Throwable $e) {
                    $whyCards = [];
                }

                if (empty($whyCards)) {
                    $whyCards = [
                        ['title' => 'Expertly Curated <br> Tours.', 'icon' => ''],
                        ['title' => 'Affordable & Flexible <br> Packages.', 'icon' => ''],
                        ['title' => '24/7 Customer <br> Support.', 'icon' => ''],
                        ['title' => 'Certified & <br> Experienced Guides.', 'icon' => ''],
                    ];
                }
            @endphp
            <div class="row g-xl-4 g-lg-3 g-4">
                @foreach ($whyCards as $i => $card)
                    <div class="col-lg-3 col-sm-6 wow animate fadeInDown" data-wow-delay="800ms"
                        data-wow-duration="1500ms">
                        <div class="why-choose-card four">
                            <svg width="50" height="50" viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M42.8751 2.92188C42.8751 2.85156 42.8672 2.78125 42.8438 2.71875C42.8438 2.71094 42.8438 2.70313 42.836 2.69531C42.7974 2.55996 42.7216 2.43815 42.6172 2.34375C42.5636 2.29585 42.503 2.25635 42.4376 2.22656C42.3319 2.16987 42.2137 2.14034 42.0938 2.14063H30.7501C30.6797 2.14063 30.6094 2.14844 30.5469 2.17188C30.5391 2.17188 30.5313 2.17188 30.5235 2.17969C30.3881 2.21825 30.2663 2.29404 30.1719 2.39844C30.1251 2.45313 30.086 2.50781 30.0547 2.57813C30.0391 2.59375 30.0391 2.60156 30.0313 2.61719L25.0001 13.0391L19.9688 2.61719C19.961 2.60156 19.961 2.59375 19.9454 2.57813C19.9156 2.51267 19.8761 2.45209 19.8282 2.39844C19.8126 2.375 19.7891 2.35938 19.7657 2.33594C19.6536 2.23789 19.5149 2.17535 19.3672 2.15625C19.3282 2.14063 19.2891 2.14063 19.2501 2.14063H7.90631C7.78131 2.14063 7.66412 2.17188 7.56256 2.22656C7.42851 2.28754 7.31662 2.38852 7.24225 2.51563C7.211 2.57031 7.17975 2.63281 7.16412 2.69531C7.15631 2.70313 7.15631 2.71094 7.15631 2.71875C7.12505 2.82643 7.11794 2.93968 7.13549 3.05042C7.15305 3.16115 7.19484 3.26665 7.25787 3.35938L14.1797 17.7188C13.6839 18.2937 13.3806 19.0095 13.3126 19.7656L13.1719 21.2813C13.1016 22.0391 12.6251 22.7031 11.9219 23L10.5235 23.6016C10.0464 23.8057 9.61846 24.1094 9.26836 24.4925C8.91826 24.8755 8.65408 25.3289 8.49354 25.8224C8.33299 26.3158 8.2798 26.8379 8.33751 27.3536C8.39523 27.8693 8.56253 28.3667 8.82818 28.8125L9.60943 30.125C10.0001 30.7813 10.0001 31.5938 9.60943 32.25L8.82818 33.5625C8.56287 34.0088 8.39568 34.5065 8.33775 35.0224C8.27983 35.5383 8.33249 36.0607 8.49225 36.5547C8.65413 37.0474 8.91897 37.5001 9.26918 37.8826C9.61939 38.2651 10.047 38.5688 10.5235 38.7734L11.9219 39.375C12.6251 39.6719 13.1016 40.3359 13.1719 41.0938L13.3126 42.6094C13.4063 43.6563 13.9454 44.6094 14.7969 45.2266C15.6485 45.8438 16.7188 46.0625 17.7501 45.8359L19.2266 45.5C19.9766 45.3281 20.7501 45.5781 21.2579 46.1563L22.2579 47.2969C22.9532 48.0938 23.9532 48.5469 25.0001 48.5469C26.0469 48.5469 27.0469 48.0938 27.7422 47.2969L28.7422 46.1563C29.2501 45.5781 30.0235 45.3281 30.7735 45.5L32.2501 45.8359C33.2735 46.0703 34.3516 45.8438 35.2032 45.2266C36.0547 44.6094 36.5938 43.6563 36.6876 42.6094L36.8282 41.0938C36.8985 40.3359 37.3751 39.6719 38.0782 39.375L39.4766 38.7734C39.9532 38.5688 40.3807 38.2651 40.7309 37.8826C41.0811 37.5001 41.346 37.0474 41.5079 36.5547C41.8282 35.5547 41.711 34.4609 41.1719 33.5625L40.3907 32.25C40.0001 31.5938 40.0001 30.7813 40.3907 30.125L41.1719 28.8125C41.4376 28.3667 41.6049 27.8693 41.6626 27.3536C41.7203 26.8379 41.6671 26.3158 41.5066 25.8224C41.346 25.3289 41.0819 24.8755 40.7318 24.4925C40.3816 24.1094 39.9537 23.8057 39.4766 23.6016L38.0782 23C37.7339 22.8532 37.4359 22.6159 37.2158 22.3133C36.9957 22.0106 36.8617 21.654 36.8282 21.2813L36.6876 19.7656C36.6195 19.0095 36.3162 18.2937 35.8204 17.7188L42.7422 3.35938C42.8282 3.23438 42.8751 3.08594 42.8751 2.92188ZM37.461 24.4375L38.8594 25.0391C39.4141 25.2734 39.836 25.7344 40.0235 26.3047C40.2032 26.875 40.1329 27.5 39.8282 28.0156L39.0469 29.3203C38.7132 29.886 38.5371 30.5307 38.5371 31.1875C38.5371 31.8443 38.7132 32.489 39.0469 33.0547L39.8282 34.3594C40.1329 34.875 40.2032 35.5 40.0235 36.0703C39.836 36.6406 39.4141 37.1016 38.8594 37.3359L37.461 37.9375C36.8587 38.1968 36.3376 38.6138 35.9526 39.1445C35.5676 39.6753 35.333 40.3001 35.2735 40.9531L35.1329 42.4688C35.0782 43.0703 34.7657 43.6094 34.2813 43.9609C33.7969 44.3203 33.1876 44.4453 32.6016 44.3125L31.1172 43.9766C29.8047 43.6797 28.4532 44.1172 27.5704 45.125L26.5626 46.2734C25.7735 47.1719 24.2266 47.1719 23.4376 46.2734L22.4297 45.125C22.0885 44.7354 21.6681 44.423 21.1966 44.2088C20.7251 43.9946 20.2133 43.8835 19.6954 43.8828C19.4219 43.8828 19.1563 43.9141 18.8829 43.9766L17.3985 44.3125C16.8126 44.4453 16.2032 44.3203 15.7188 43.9609C15.4784 43.7876 15.278 43.5646 15.1311 43.3072C14.9842 43.0498 14.8942 42.7639 14.8672 42.4688L14.7266 40.9531C14.6671 40.3001 14.4325 39.6753 14.0475 39.1445C13.6625 38.6138 13.1414 38.1968 12.5391 37.9375L11.1407 37.3359C10.586 37.1016 10.1641 36.6406 9.97662 36.0703C9.79693 35.5 9.86725 34.875 10.1719 34.3594L10.9532 33.0547C11.6329 31.9063 11.6329 30.4766 10.9532 29.3203L10.1719 28.0156C9.86725 27.5 9.79693 26.875 9.97662 26.3047C10.1641 25.7344 10.586 25.2734 11.1407 25.0391L12.5391 24.4375C13.142 24.1792 13.6637 23.7624 14.0489 23.2314C14.434 22.7005 14.6682 22.0752 14.7266 21.4219L14.8672 19.9063C14.9219 19.3047 15.2344 18.7656 15.7188 18.4141C16.2032 18.0625 16.8126 17.9375 17.3985 18.0625L18.8829 18.3984C20.1876 18.6953 21.5469 18.2578 22.4297 17.25L23.4376 16.1016C24.2266 15.2031 25.7735 15.2031 26.5626 16.1016L27.5704 17.25C28.0014 17.7457 28.5594 18.1145 29.1843 18.3169C29.8092 18.5192 30.4775 18.5474 31.1172 18.3984L32.6016 18.0625C33.1876 17.9375 33.7969 18.0625 34.2813 18.4141C34.7657 18.7656 35.0782 19.3047 35.1329 19.9063L35.2735 21.4219C35.3319 22.0752 35.5661 22.7005 35.9513 23.2314C36.3364 23.7624 36.8581 24.1792 37.461 24.4375Z" />
                                <path
                                    d="M25 19.8594C18.75 19.8594 13.6719 24.9375 13.6719 31.1875C13.6719 37.4375 18.75 42.5156 25 42.5156C31.25 42.5156 36.3281 37.4375 36.3281 31.1875C36.3281 24.9375 31.25 19.8594 25 19.8594ZM25 40.9531C19.6172 40.9531 15.2344 36.5703 15.2344 31.1875C15.2344 25.8047 19.6172 21.4219 25 21.4219C30.3828 21.4219 34.7656 25.8047 34.7656 31.1875C34.7656 36.5703 30.3828 40.9531 25 40.9531Z" />
                                <path
                                    d="M29.0899 27.8086L23.9884 32.911L20.9103 29.8328C20.7638 29.6864 20.5651 29.6041 20.3579 29.6041C20.1507 29.6041 19.9521 29.6864 19.8056 29.8328C19.6591 29.9793 19.5768 30.178 19.5768 30.3852C19.5768 30.5924 19.6591 30.791 19.8056 30.9375L23.436 34.568C23.5825 34.7145 23.7812 34.7967 23.9884 34.7967C24.1955 34.7967 24.3942 34.7145 24.5407 34.568L30.1946 28.9133C30.2672 28.8408 30.3247 28.7547 30.364 28.6599C30.4032 28.5651 30.4234 28.4635 30.4234 28.361C30.4234 28.2584 30.4032 28.1568 30.364 28.062C30.3247 27.9673 30.2672 27.8812 30.1946 27.8086C30.1221 27.7361 30.036 27.6786 29.9412 27.6393C29.8464 27.6 29.7449 27.5798 29.6423 27.5798C29.5397 27.5798 29.4381 27.6 29.3434 27.6393C29.2486 27.6786 29.1625 27.7361 29.0899 27.8086Z" />
                            </svg>
                            <h5>{!! nl2br(e($card['title'] ?? '')) !!}</h5>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <!-- Why Choose Visa Section End-->

    <!-- Partner area Section Start-->
    {{-- <div class="partner-section mb-100">
        <div class="container">
            <div class="partner-title wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                <h5>{{ $settings['partners_section_title'] ?? 'Those Company You Can Easily Trust!' }}</h5>
            </div>
            <div class="partner-wrap">
                <div class="marquee">
                    <div class="marquee__group">
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-01.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-02.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-03.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-04.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-05.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-06.png') }}" alt=""></a>
                    </div>
                    <div aria-hidden="true" class="marquee__group">
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-01.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-02.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-03.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-04.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-05.png') }}" alt=""></a>
                        <a href="#"><img src="{{ asset('assets/img/home1/partner-06.png') }}" alt=""></a>
                    </div>
                </div>
            </div>
        </div>
    </div> --}}
    <!-- Partner area Section End-->

    <!-- About Video Section Start-->
    {{-- <div class="about-video-section mb-100">
        <div class="container">
            <div class="video-wrap">
                <img src="{{ asset('assets/img/innerpages/about-video-img.jpg') }}" alt="">
                <a data-fancybox="video-player" href="https://www.youtube.com/watch?v=u31qwQUeGuM" class="play-btn">
                    <i class="bi bi-play-fill"></i>
                    <div class="waves-block">
                        <div class="waves wave-1"></div>
                        <div class="waves wave-2"></div>
                        <div class="waves wave-3"></div>
                    </div>
                </a>
            </div>
        </div>
    </div> --}}
    <!-- About Video Section End-->

    <!-- home1 testimonial Section Start-->
    {{-- <div class="home1-testimonial-section mb-100">
        <div class="container">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-6 col-lg-8">
                    <div class="section-title text-center">
                        <h2>Hear It from Travelers</h2>
                        <p>We go beyond just booking trips—we create unforgettable travel experiences that match your
                            dreams!</p>
                    </div>
                </div>
            </div>
            <div class="row mb-40">
                <div class="col-lg-12">
                    <div class="swiper home1-testimonial-slider">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <div class="testimonial-card">
                                    <div class="author-area">
                                        <div class="author-img">
                                            <img src="{{ s3_asset($settings['about_testimonial_img_1'] ?? 'assets/img/home1/testimonial-author-img1.png') }}"
                                                alt="">
                                            <a data-fancybox="video-player"
                                                href="https://www.youtube.com/watch?v=MLpWrANjFbI" class="play-btn">
                                                <svg width="26" height="26" viewBox="0 0 26 26"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <circle cx="13" cy="13" r="12.5" fill="white"
                                                        stroke="#110F0F" />
                                                    <g>
                                                        <path
                                                            d="M8.4375 13V9.7519C8.4375 8.4062 9.89453 7.5644 11.0586 8.23823L13.873 9.86323L16.6875 11.4882C17.8535 12.1601 17.8535 13.8437 16.6875 14.5156L13.873 16.1406L11.0586 17.7656C9.89453 18.4355 8.4375 17.5957 8.4375 16.25V13Z" />
                                                    </g>
                                                </svg>
                                            </a>
                                        </div>
                                        <div class="author-info">
                                            <h5>Robert Kcarery </h5>
                                            <span>TheTaxi Traveler</span>
                                        </div>
                                    </div>
                                    <ul class="rating-area">
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                    </ul>
                                    <h5>Excellent Tourist Place!</h5>
                                    <div class="content">
                                        <p>Our honeymoon package to the Maldives was beyond amazing! The travel agency took
                                            care of every detail.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testimonial-card two">
                                    <div class="author-area">
                                        <div class="author-img">
                                            <img src="{{ s3_asset($settings['about_testimonial_img_2'] ?? 'assets/img/home1/testimonial-author-img2.png') }}"
                                                alt="">
                                        </div>
                                        <div class="author-info">
                                            <h5>Selina Henry</h5>
                                            <span>TheTaxi Traveler</span>
                                        </div>
                                    </div>
                                    <ul class="rating-area trustpilot">
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                    </ul>
                                    <h5>Great Experience!</h5>
                                    <div class="content">
                                        <p>This was the best trip of my life! Everything was perfectly planned, from airport
                                            pickup to guided tours. The accommodations were fantastic, and the itinerary was
                                            well-balanced. Highly recommended!</p>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testimonial-card">
                                    <div class="author-area">
                                        <div class="author-img">
                                            <img src="{{ s3_asset($settings['about_testimonial_img_3'] ?? 'assets/img/home1/testimonial-author-img3.png') }}"
                                                alt="">
                                            <a data-fancybox="video-player"
                                                href="https://www.youtube.com/watch?v=MLpWrANjFbI" class="play-btn">
                                                <svg width="26" height="26" viewBox="0 0 26 26"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <circle cx="13" cy="13" r="12.5" fill="white"
                                                        stroke="#110F0F" />
                                                    <g>
                                                        <path
                                                            d="M8.4375 13V9.7519C8.4375 8.4062 9.89453 7.5644 11.0586 8.23823L13.873 9.86323L16.6875 11.4882C17.8535 12.1601 17.8535 13.8437 16.6875 14.5156L13.873 16.1406L11.0586 17.7656C9.89453 18.4355 8.4375 17.5957 8.4375 16.25V13Z" />
                                                    </g>
                                                </svg>
                                            </a>
                                        </div>
                                        <div class="author-info">
                                            <h5>James Bonde</h5>
                                            <span>TheTaxi Traveler</span>
                                        </div>
                                    </div>
                                    <ul class="rating-area">
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-half"></i></li>
                                    </ul>
                                    <h5>Average Experience</h5>
                                    <div class="content">
                                        <p>The tour was well-organized, and we enjoyed every bit of it. However, I wish we
                                            had more free time to explore on our own. Overall, a great experience!</p>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testimonial-card">
                                    <div class="author-area">
                                        <div class="author-img">
                                            <img src="{{ s3_asset($settings['about_testimonial_img_4'] ?? 'assets/img/home1/testimonial-author-img4.png') }}"
                                                alt="">
                                            <a data-fancybox="video-player"
                                                href="https://www.youtube.com/watch?v=MLpWrANjFbI" class="play-btn">
                                                <svg width="26" height="26" viewBox="0 0 26 26"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <circle cx="13" cy="13" r="12.5" fill="white"
                                                        stroke="#110F0F" />
                                                    <g>
                                                        <path
                                                            d="M8.4375 13V9.7519C8.4375 8.4062 9.89453 7.5644 11.0586 8.23823L13.873 9.86323L16.6875 11.4882C17.8535 12.1601 17.8535 13.8437 16.6875 14.5156L13.873 16.1406L11.0586 17.7656C9.89453 18.4355 8.4375 17.5957 8.4375 16.25V13Z" />
                                                    </g>
                                                </svg>
                                            </a>
                                        </div>
                                        <div class="author-info">
                                            <h5>Michael D Linda</h5>
                                            <span>TheTaxi Traveler</span>
                                        </div>
                                    </div>
                                    <ul class="rating-area">
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-fill"></i></li>
                                        <li><i class="bi bi-circle-half"></i></li>
                                    </ul>
                                    <h5>Great Visitors Venue!</h5>
                                    <div class="content">
                                        <p>Thank you so much for your work on our honeymoon. We really did have such a great
                                            time and it was everything we were hoping!</p>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testimonial-card two">
                                    <div class="author-area">
                                        <div class="author-img">
                                            <img src="{{ s3_asset($settings['about_testimonial_img_5'] ?? 'assets/img/home1/testimonial-author-img5.png') }}"
                                                alt="">
                                        </div>
                                        <div class="author-info">
                                            <h5>Amber Lashley</h5>
                                            <span>TheTaxi Traveler</span>
                                        </div>
                                    </div>
                                    <ul class="rating-area trustpilot">
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                        <li>
                                            <svg width="11" height="10" viewBox="0 0 11 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M5.25 7.57409L7.53125 6.99627L8.48437 9.93221L5.25 7.57409ZM10.5 3.77924H6.48437L5.25 0L4.01562 3.77924H0L3.25 6.12174L2.01562 9.90097L5.26562 7.55847L7.26562 6.12174L10.5 3.77924Z" />
                                            </svg>
                                        </li>
                                    </ul>
                                    <h5>Fantastic Service!</h5>
                                    <div class="content">
                                        <p>We have returned from Greece and want to let you know how terrific the trip was!
                                            Everything was great. We highly recommend them. Thank you so much!</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="review-wrap wow animate fadeInUp" data-wow-delay="200ms" data-wow-duration="1500ms">
                <div class="tripadvisor-rating-area">
                    <a href="https://www.tripadvisor.com/" class="tripadvisor-rating">
                        <img src="{{ s3_asset($settings['about_tripadvisor_logo'] ?? 'assets/img/home1/icon/tripadvisor-logo.svg') }}"
                            alt="">
                        <div class="rating-area">
                            <span>Reviews</span>
                            <img src="{{ asset('assets/img/home1/icon/tripadvisor-start.svg') }}" alt="">
                        </div>
                    </a>
                    <svg class="divider" width="6" height="52" viewBox="0 0 6 52"
                        xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M3.5 5L5.88675 0H0.113249L2.5 5H3.5ZM2.5 47L0.113249 52H5.88675L3.5 47H2.5ZM2.5 4.5V47.5H3.5V4.5H2.5Z" />
                    </svg>
                </div>
                <a href="http://trustpilot.com/" class="trustpilot-rating-area">
                    <strong>4.5</strong>
                    <div class="trustpilot-rating">
                        <img src="{{ s3_asset($settings['about_trustpilot_logo'] ?? 'assets/img/home1/icon/trustpilot-logo.svg') }}"
                            alt="">
                        <div class="rating-area">
                            <img src="{{ asset('assets/img/home1/icon/trustpilot-star.svg') }}" alt="">
                            <span>(2K reviews)</span>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div> --}}
    <!-- home1 testimonial Section End-->

    <!-- home1 faq Section Start-->
    {{-- <div class="home1-faq-section mb-100">
        <div class="container">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-6 col-lg-8">
                    <div class="section-title text-center">
                        <h2>Questions & Answer</h2>
                        <p>We’re committed to offering more than just products—we provide exceptional experiences.</p>
                    </div>
                </div>
            </div>
            <div class="row justify-content-center">
                <div class="col-xl-8 col-lg-10">
                    <div class="faq-wrap">
                        <div class="accordion accordion-flush" id="accordionFlushExample">
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="200ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingOne">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseOne" aria-expanded="false"
                                        aria-controls="flush-collapseOne">What Services Does Your Travel Agency
                                        Provide?</button>
                                </h5>
                                <div id="flush-collapseOne" class="accordion-collapse collapse show"
                                    aria-labelledby="flush-headingOne" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        A travel agency typically provides a wide range of services to ensure a smooth and
                                        enjoyable travel experience. As like- <span>Hotel booking, Flight Booking, Visa &
                                            Customized Travel Pakcge etc.</span>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="400ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseTwo" aria-expanded="false"
                                        aria-controls="flush-collapseTwo">Do You Offer Customized Travel Packages?</button>
                                </h5>
                                <div id="flush-collapseTwo" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingTwo" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        Absolutely! We offer fully customized travel packages based on your interests,
                                        budget, and schedule. Whether you're planning <span>a solo adventure, a family
                                            vacation, a romantic getaway, or a group tour</span>, our team will tailor every
                                        detail to create a personalized travel experience just for you.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="600ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseThree" aria-expanded="false"
                                        aria-controls="flush-collapseThree">Can I Book Flights, Hotels, and Tours
                                        Separately?</button>
                                </h5>
                                <div id="flush-collapseThree" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingThree" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        Yes, you can! We provide the flexibility to book <span>flights, hotels, and tours
                                            separately</span> based on your specific needs. Whether you need just a flight,
                                        only accommodation, or want to add a tour later — we’re here to help you plan each
                                        part of your trip your way.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="800ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingFour">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseFour" aria-expanded="false"
                                        aria-controls="flush-collapseFour">Do You Provide Visa Assistance?</button>
                                </h5>
                                <div id="flush-collapseFour" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingFour" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        Yes, we do! Our team offers complete <span>visa assistance services</span> to help
                                        you navigate the application process smoothly. From providing guidance on required
                                        documents to scheduling appointments and submitting applications, we're here to
                                        support you every step of the way.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="800ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingFive">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseFive" aria-expanded="false"
                                        aria-controls="flush-collapseFive">What Payment Methods Do You Accept?</button>
                                </h5>
                                <div id="flush-collapseFive" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingFive" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        We accept a variety of <span>payment methods</span> to make your booking process
                                        easy and convenient. These include <span>cash, bank transfers, mobile payments (such
                                            as bKash, Nagad), and major debit/credit cards</span>. If you have a preferred
                                        payment option, feel free to let us know!
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="800ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingSix">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseSix" aria-expanded="false"
                                        aria-controls="flush-collapseSix">What Travel Documents are Required for
                                        International Travel?</button>
                                </h5>
                                <div id="flush-collapseSix" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingSix" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        For international travel, you’ll typically need several important <span>travel
                                            documents</span>, including a <span>valid passport, visa (if required), airline
                                            tickets, travel insurance, and any COVID-19 related health certificates</span>.
                                        Depending on your destination, additional documents may be necessary. Our team will
                                        guide you through the specific requirements for your trip.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> --}}
    <!-- home1 faq Section End-->

    <!-- Counter Section Start-->
    {{-- <div class="counter-section">
        <div class="container">
            <div class="row gy-md-5 gy-4">
                <div class="col-lg-3 col-sm-6 divider">
                    <div class="single-counter">
                        <div class="icon">
                            <svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M39.5961 15.81C38.1214 17.2519 40.2368 20.3608 36.9514 22.3862C35.0583 23.5533 38.0077 25.8843 36.9514 28.6109C36.7987 29.0049 36.7675 29.4023 36.987 29.8303C34.3992 31.822 31.1578 33.0065 27.6398 33.0065C26.7047 33.0065 25.7892 32.9228 24.9003 32.7625L24.5591 33.9508C25.5749 34.1416 26.6063 34.2374 27.6398 34.237C36.7906 34.237 44.209 26.8186 44.209 17.6679C44.209 8.51713 36.7906 1.09863 27.6398 1.09863C24.0648 1.09863 20.6088 2.24297 17.7454 4.37818C19.8919 7.68524 19.849 12.0313 17.6364 15.2956C18.4686 16.7537 19.665 16.797 20.1282 16.9774C21.8931 17.6654 23.2203 21.7429 24.3251 23.0528C25.9039 22.1413 27.6183 21.8503 29.3902 22.4059C29.0978 20.9689 27.4158 19.3471 26.9044 18.8961C25.0823 17.2898 25.8475 16.5076 27.2823 16.1104C28.9332 15.6532 31.4705 15.7057 31.9733 15.5987C33.1508 15.3482 33.4901 14.5201 32.5894 13.7393C31.5322 12.823 29.6808 11.8148 29.2499 10.9945C28.6541 9.8601 29.0869 9.52163 29.8556 9.28389C31.4176 8.80084 34.3657 8.73281 32.891 3.25178C38.0294 5.12385 41.8141 9.62587 42.7515 15.026C41.0332 15.0163 40.1027 15.3148 39.5961 15.81ZM6.48202 37.3493L1.40625 33.2101L3.25916 31.9816L8.22243 33.7703L25.2854 23.919C28.1145 22.2856 33.7222 24.5118 28.3539 27.6113L24.4524 29.8638L20.8244 42.4993L18.3959 43.9014L18.5658 33.213C18.5658 33.213 8.38195 38.8263 6.48202 37.3493ZM9.44719 1.09863C14.2276 1.09863 18.1034 4.97426 18.1034 9.7548C18.1034 14.5354 14.2277 18.411 9.44719 18.411C4.66673 18.411 0.791016 14.5353 0.791016 9.7548C0.791016 4.97435 4.66673 1.09863 9.44719 1.09863ZM4.16821 14.9772C4.53076 12.3859 6.75615 10.3914 9.44728 10.3914C12.1385 10.3914 14.3635 12.3858 14.7261 14.9772C16.0532 13.6358 16.8728 11.7911 16.8728 9.7548C16.8728 5.65356 13.5483 2.3291 9.4471 2.3291C5.34595 2.3291 2.02148 5.65356 2.02148 9.7548C2.02148 11.7911 2.84115 13.6357 4.16821 14.9772ZM6.98344 6.5076C6.98344 7.86841 8.08655 8.97152 9.44736 8.97152C10.8082 8.97152 11.9113 7.86841 11.9113 6.5076C11.9113 5.14714 10.8082 4.04402 9.44736 4.04402C8.08655 4.04411 6.98344 5.14714 6.98344 6.5076ZM8.08436 26.0411L10.5127 24.6391L18.2438 26.5637L13.7191 29.176L8.08436 26.0411ZM13.7464 24.1761C13.0102 22.6071 12.547 20.924 12.3768 19.1992C11.9812 19.3217 11.5781 19.4192 11.1702 19.4911C11.3316 20.9699 11.6927 22.4201 12.2436 23.8019L13.7464 24.1761Z" />
                            </svg>
                        </div>
                        <div class="content">
                            <div class="number">
                                <h2 class="counter">26</h2>
                                <span>K+</span>
                            </div>
                            <span>Tour Completed</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-sm-6 divider d-flex justify-content-lg-center">
                    <div class="single-counter">
                        <div class="icon">
                            <svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M35.1512 26.1785V22.8616H35.6609C37.2742 22.8616 38.5842 21.5516 38.5842 19.9449C38.5842 18.3317 37.2742 17.0216 35.6609 17.0216H30.3436V11.9947H37.0613C37.4227 11.9947 37.7066 11.7108 37.7066 11.3495C37.7069 11.2646 37.6904 11.1806 37.6581 11.1022C37.6257 11.0237 37.5782 10.9525 37.5182 10.8925C37.4583 10.8325 37.387 10.785 37.3086 10.7527C37.2302 10.7204 37.1461 10.7039 37.0613 10.7042H31.957C31.6214 5.76761 27.5108 1.85059 22.4839 1.85059C18.296 1.85059 14.6436 4.53503 13.4045 8.5359C13.0367 9.7233 13.5464 10.9945 14.6435 11.6269V17.0216H9.32619C8.94205 17.0208 8.56153 17.0958 8.20647 17.2424C7.85141 17.389 7.5288 17.6043 7.25718 17.8759C6.98555 18.1475 6.77026 18.4701 6.62367 18.8252C6.47707 19.1802 6.40206 19.5608 6.40294 19.9449C6.40294 21.5517 7.71287 22.8616 9.32619 22.8616H9.83604V26.1785C6.40303 27.8885 4.20251 31.4055 4.20251 35.2708V39.6008C4.20251 41.5561 5.79641 43.15 7.75171 43.15H37.2485C39.2038 43.15 40.7977 41.5561 40.7977 39.6008V35.2708C40.7976 31.4055 38.5907 27.8885 35.1512 26.1785ZM15.9341 11.9947H29.0531V17.2669C29.0531 19.0543 27.1688 21.5516 23.8778 24.12C23.4832 24.4304 22.9957 24.5991 22.4936 24.5991C21.9915 24.5991 21.504 24.4304 21.1094 24.12C20.335 23.5198 19.3607 22.6938 18.4572 21.7646L18.4507 21.7582C17.102 20.3643 15.9341 18.7382 15.9341 17.2669V11.9947ZM25.0393 24.8492V26.8109C25.0393 28.2177 23.897 29.3598 22.4968 29.3598C21.0577 29.3598 19.9479 28.1918 19.9479 26.8109V24.8428C21.7482 26.2882 23.3744 26.185 25.0393 24.8492ZM11.7461 41.8594H7.75163C6.50622 41.8594 5.4931 40.8463 5.4931 39.6008V35.2708C5.4931 31.5152 7.90007 27.9854 11.7461 26.798V41.8594ZM31.9505 41.8594H13.0367V26.5076C13.4691 26.4431 13.9143 26.3979 14.366 26.3979H18.6573V26.8109C18.6573 28.9017 20.3415 30.6505 22.4969 30.6505C24.6134 30.6505 26.3299 28.9275 26.3299 26.8109V26.3979H30.6276C31.0794 26.3979 31.5181 26.4431 31.9505 26.5076V41.8594ZM39.507 39.6008C39.507 40.8463 38.4938 41.8594 37.2484 41.8594H33.2411V26.798C37.0484 27.9725 39.507 31.4636 39.507 35.2708V39.6008Z" />
                            </svg>
                        </div>
                        <div class="content">
                            <div class="number">
                                <h2 class="counter">12</h2>
                                <span>+</span>
                            </div>
                            <span>Travel Experience</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-sm-6 divider d-flex justify-content-lg-center">
                    <div class="single-counter">
                        <div class="icon">
                            <svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg">
                                <g>
                                    <path
                                        d="M38.0333 16.4395C38.3412 16.4394 38.6319 16.2974 38.8214 16.0547C39.0107 15.8121 39.0776 15.4959 39.003 15.1973L37.5909 9.54883C37.4796 9.10368 37.08 8.79105 36.6212 8.79102H8.37899C7.92011 8.79102 7.52055 9.10366 7.40926 9.54883L5.99715 15.1973C5.92256 15.4959 5.98936 15.812 6.17879 16.0547C6.3683 16.2974 6.65894 16.4395 6.96688 16.4395H38.0333ZM9.15926 10.791H35.8399L36.752 14.4395H8.24715L9.15926 10.791Z" />
                                    <g>
                                        <path
                                            d="M36.6209 10.791C36.9289 10.791 37.2195 10.649 37.409 10.4062C37.5984 10.1636 37.6652 9.84747 37.5907 9.54883V9.54688C37.5904 9.5458 37.5902 9.5441 37.5897 9.54199C37.5886 9.53763 37.5869 9.53098 37.5848 9.52246C37.5805 9.50533 37.5735 9.48005 37.5653 9.44727C37.5488 9.38137 37.525 9.28445 37.495 9.16406L36.6024 5.59375V5.59277C36.4298 4.90407 36.069 4.27658 35.5604 3.78125C35.0518 3.28608 34.4154 2.94228 33.7225 2.78809L23.9491 0.616211C23.5687 0.531687 23.2606 0.463331 23.0477 0.416016C22.9414 0.3924 22.8586 0.373787 22.8026 0.361328C22.7745 0.355098 22.7525 0.349873 22.7381 0.34668C22.7313 0.345176 22.7261 0.344541 22.7225 0.34375C22.721 0.343414 22.7195 0.342971 22.7186 0.342773H22.7166C22.5738 0.311048 22.4258 0.310103 22.283 0.341797V0.342773H22.2811C22.2802 0.342971 22.2787 0.343411 22.2772 0.34375C22.2736 0.34454 22.2683 0.345175 22.2616 0.34668C22.2472 0.349873 22.2251 0.355098 22.1971 0.361328C22.141 0.373787 22.0583 0.392397 21.952 0.416016C21.739 0.46333 21.431 0.531686 21.0506 0.616211L11.2772 2.78809C10.5843 2.94228 9.94787 3.28608 9.43929 3.78125C8.93063 4.27658 8.56983 4.90407 8.3973 5.59277V5.59375L7.50472 9.16406C7.47463 9.28445 7.45088 9.38137 7.43441 9.44727C7.42621 9.48005 7.41916 9.50533 7.41487 9.52246C7.41274 9.53098 7.41108 9.53763 7.40999 9.54199C7.40946 9.5441 7.40928 9.5458 7.40901 9.54688V9.54883C7.33443 9.84748 7.40123 10.1636 7.59066 10.4062C7.78016 10.649 8.07081 10.791 8.37874 10.791H36.6209ZM9.68734 8.67969C9.88241 7.89919 10.1299 6.91098 10.3377 6.0791L10.3719 5.95703C10.4633 5.67629 10.6224 5.42171 10.8348 5.21484C11.0775 4.97846 11.381 4.81382 11.7117 4.74023C13.444 4.35511 16.1954 3.74364 18.5135 3.22852C19.6725 2.97097 20.7232 2.73746 21.4842 2.56836C21.8645 2.48385 22.1726 2.41548 22.3856 2.36816C22.4272 2.35891 22.4656 2.35038 22.4998 2.34277C22.534 2.35037 22.5724 2.35891 22.6141 2.36816C22.827 2.41548 23.1352 2.48386 23.5155 2.56836C24.2765 2.73746 25.3272 2.97097 26.4862 3.22852C28.8043 3.74365 31.5557 4.35511 33.2879 4.74023L33.411 4.77246C33.6938 4.85644 33.9526 5.00811 34.1649 5.21484C34.4076 5.45122 34.5796 5.75046 34.6619 6.0791C34.8698 6.91098 35.1173 7.89919 35.3123 8.67969C35.3217 8.71716 35.3306 8.75454 35.3397 8.79102H9.65999C9.66911 8.75454 9.67797 8.71716 9.68734 8.67969Z" />
                                        <path
                                            d="M33.0908 24.9121C35.9799 24.9121 37.4763 24.1179 38.8564 23.3818C40.124 22.7058 41.2754 22.0879 43.6816 22.0879C44.086 22.0878 44.4506 21.8443 44.6054 21.4707C44.7602 21.0971 44.6746 20.6669 44.3886 20.3809L38.7402 14.7324C38.5527 14.5449 38.2983 14.4395 38.0332 14.4395H6.96676C6.70155 14.4395 6.44726 14.5449 6.25973 14.7324L0.611293 20.3809C0.325315 20.6669 0.23972 21.097 0.394496 21.4707C0.549318 21.8443 0.913924 22.0879 1.31832 22.0879C3.72449 22.0879 4.87591 22.7058 6.14352 23.3818C7.52358 24.1179 9.02 24.9121 11.9091 24.9121C14.7983 24.9121 16.2947 24.1179 17.6748 23.3818C18.9424 22.7058 20.0938 22.0879 22.5 22.0879C24.9061 22.0879 26.0576 22.7058 27.3252 23.3818C28.7052 24.1179 30.2017 24.9121 33.0908 24.9121ZM33.0908 22.9121C30.6846 22.9121 29.5332 22.2933 28.2656 21.6172C26.8856 20.8812 25.3889 20.0879 22.5 20.0879C19.6111 20.0879 18.1143 20.8812 16.7343 21.6172C15.4667 22.2933 14.3153 22.9121 11.9091 22.9121C9.50298 22.9121 8.35156 22.2933 7.08395 21.6172C6.11129 21.0984 5.08017 20.5533 3.54489 20.2754L7.38082 16.4395H37.6191L41.4541 20.2754C39.919 20.5534 38.8876 21.0984 37.915 21.6172C36.6475 22.2932 35.4968 22.9121 33.0908 22.9121Z" />
                                        <path
                                            d="M22.4994 44.6807C23.2757 44.6808 24.0347 44.4517 24.6801 44.0205C25.3255 43.5893 25.8285 42.976 26.1254 42.2588C26.4225 41.5417 26.5005 40.7525 26.349 39.9912C26.1976 39.2301 25.8236 38.5312 25.2748 37.9824L23.9135 36.6221L36.144 24.3916C36.5345 24.0011 36.5345 23.3671 36.144 22.9766C35.7535 22.5864 35.1204 22.5864 34.7299 22.9766L22.4994 35.208L10.2699 22.9766C9.87949 22.5864 9.24634 22.5864 8.85588 22.9766C8.46536 23.3671 8.46536 24.0011 8.85588 24.3916L21.0854 36.6221L19.725 37.9824C19.1762 38.5312 18.8023 39.2301 18.6508 39.9912C18.4994 40.7524 18.5765 41.5418 18.8735 42.2588L18.9946 42.5225C19.2986 43.1263 19.7549 43.6431 20.3197 44.0205C20.965 44.4515 21.7235 44.6807 22.4994 44.6807ZM22.4994 42.6816C22.1189 42.6817 21.7465 42.5688 21.4301 42.3574C21.1139 42.1461 20.8677 41.8455 20.7221 41.4941V41.4932C20.5765 41.1416 20.5376 40.755 20.6117 40.3818C20.686 40.0085 20.8699 39.6656 21.1391 39.3965L22.4994 38.0361L23.8608 39.3965C24.1299 39.6656 24.3129 40.0085 24.3871 40.3818C24.4613 40.755 24.4234 41.1416 24.2778 41.4932V41.4941C24.1321 41.8456 23.8851 42.146 23.5688 42.3574C23.2525 42.5687 22.8808 42.6817 22.5004 42.6816H22.4994Z" />
                                    </g>
                                </g>
                            </svg>
                        </div>
                        <div class="content">
                            <div class="number">
                                <h2 class="counter">20</h2>
                                <span>+</span>
                            </div>
                            <span>Happy Traveler</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-sm-6 d-flex justify-content-lg-end">
                    <div class="single-counter">
                        <div class="icon">
                            <svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M18.9117 14.3766C18.9382 14.3657 19.5657 14.1034 19.8841 14.4911C19.9487 14.5704 20.0284 14.6362 20.1186 14.6845C20.2088 14.7329 20.3077 14.7629 20.4096 14.7728C20.5114 14.7827 20.6142 14.7723 20.712 14.7423C20.8099 14.7122 20.9008 14.6631 20.9795 14.5977C21.139 14.4667 21.2398 14.2777 21.26 14.0723C21.2801 13.8669 21.2179 13.6619 21.0869 13.5023C20.2259 12.4536 18.8712 12.6849 18.2772 12.9542C18.0907 13.0387 17.9452 13.1936 17.8726 13.385C17.7999 13.5764 17.806 13.7887 17.8894 13.9757C18.0607 14.3681 18.5201 14.5448 18.9117 14.3766ZM24.5141 14.776C24.6292 14.7762 24.7429 14.7508 24.847 14.7016C24.9511 14.6525 25.0429 14.5808 25.1159 14.4919C25.4312 14.108 26.0463 14.3595 26.0876 14.3774C26.2748 14.4545 26.4847 14.4557 26.6727 14.3806C26.8608 14.3056 27.0122 14.1602 27.0949 13.9754C27.1775 13.7905 27.1849 13.5807 27.1154 13.3905C27.046 13.2003 26.9052 13.0447 26.7229 12.9566C26.128 12.6872 24.7741 12.456 23.9131 13.5047C23.8196 13.6185 23.7603 13.7566 23.7422 13.9029C23.7242 14.0491 23.748 14.1975 23.811 14.3307C23.874 14.4639 23.9735 14.5765 24.098 14.6553C24.2224 14.7342 24.3668 14.776 24.5141 14.776ZM19.7798 20.38C19.7074 20.4523 19.65 20.5381 19.6108 20.6325C19.5717 20.727 19.5515 20.8282 19.5515 20.9305C19.5515 21.0327 19.5717 21.134 19.6108 21.2284C19.65 21.3229 19.7074 21.4087 19.7798 21.4809C20.5295 22.2306 21.5151 22.6059 22.5 22.6059C23.4849 22.6059 24.4705 22.2306 25.2203 21.4809C25.2925 21.4086 25.3499 21.3228 25.389 21.2284C25.4281 21.1339 25.4483 21.0327 25.4483 20.9305C25.4483 20.8282 25.4281 20.727 25.389 20.6326C25.3499 20.5381 25.2925 20.4523 25.2203 20.38C25.148 20.3077 25.0622 20.2504 24.9677 20.2113C24.8733 20.1722 24.772 20.152 24.6698 20.152C24.5676 20.152 24.4664 20.1722 24.3719 20.2113C24.2775 20.2504 24.1917 20.3077 24.1194 20.38C23.6894 20.8085 23.1071 21.049 22.5 21.049C21.893 21.049 21.3107 20.8085 20.8806 20.38C20.8084 20.3077 20.7226 20.2503 20.6281 20.2111C20.5337 20.1719 20.4324 20.1518 20.3302 20.1518C20.2279 20.1518 20.1267 20.1719 20.0322 20.2111C19.9378 20.2503 19.852 20.3077 19.7798 20.38Z" />
                                <path
                                    d="M40.9905 21.1917C40.5794 20.9148 40.106 20.7443 39.6127 20.6954C39.1195 20.6464 38.6218 20.7207 38.1643 20.9114L33.1349 22.9823C32.2085 22.2894 31.0562 21.9079 29.7093 21.8378C30.0285 21.0827 30.2076 20.2496 30.2309 19.3854C30.2777 19.3932 30.3166 19.3932 30.3633 19.3932C31.2431 19.3932 32.6912 19.0351 33.1739 16.7929C34.5363 16.1856 36.0078 15.189 36.1791 13.7176C36.3114 12.4875 35.5095 11.2574 33.7811 10.0584C33.6643 9.98834 33.5787 9.91049 33.5008 9.81706C33.3685 9.67692 33.2673 9.46671 33.1972 9.20979L32.3642 5.57397C31.9126 2.91134 27.5216 1.67344 23.9169 1.1051C22.9904 0.964965 22.0095 0.964965 21.0752 1.1051C17.4783 1.67344 13.0873 2.91134 12.6513 5.52726L11.8105 9.17865C11.7482 9.38886 11.6626 9.67692 11.5847 9.77813C11.5769 9.78592 11.5691 9.80149 11.5613 9.80928C11.5224 9.85599 11.4134 9.93384 11.2188 10.0662C9.49041 11.2574 8.6885 12.4875 8.82864 13.7176C8.99214 15.1968 10.4636 16.1856 11.8261 16.7929C12.3088 19.0351 13.7569 19.3932 14.6288 19.3932C14.6833 19.3932 14.7223 19.3932 14.769 19.3854C14.7923 20.2496 14.9714 21.0827 15.2906 21.8378C13.9437 21.9079 12.7915 22.2894 11.865 22.9823L6.83556 20.9114C5.9013 20.5221 4.85026 20.6311 4.00943 21.1917C3.59859 21.4679 3.26218 21.8412 3.02997 22.2785C2.79776 22.7157 2.67691 23.2035 2.67811 23.6986V28.8759C2.67811 29.9192 3.19974 30.869 4.0795 31.4218C4.56998 31.7332 5.13054 31.8889 5.68331 31.8889C6.12708 31.8889 6.57085 31.7955 6.99127 31.5931L9.31134 30.4875C9.35027 30.9547 9.3892 31.2583 9.40477 31.3206C8.75857 32.0836 8.33037 33.049 8.19802 34.1078L7.7776 37.4633C7.72303 37.9439 7.80689 38.43 8.0193 38.8644C8.23171 39.2989 8.56383 39.6637 8.97657 39.9157C13.3987 42.6095 17.9532 43.9876 22.5 44.0031C27.0467 43.9876 31.6012 42.6095 36.0311 39.908C36.872 39.3941 37.3391 38.4287 37.2145 37.4555L36.8019 34.1078C36.6695 33.049 36.2413 32.0836 35.5951 31.3206C35.6107 31.2116 35.6574 30.9235 35.6886 30.4875L38.0086 31.5931C38.4291 31.7955 38.8728 31.8889 39.3166 31.8889C39.8694 31.8889 40.4299 31.7332 40.9204 31.4218C41.8002 30.869 42.3218 29.9192 42.3218 28.8759V23.6986C42.3218 22.6865 41.8235 21.7522 40.9905 21.1917ZM32.8079 24.8041C34.1237 26.3534 34.2093 28.7981 34.1393 30.1528C34.1176 30.1368 34.0941 30.1238 34.0692 30.1138C34.0692 30.1138 28.0199 27.0775 27.5839 26.8517C27.1946 26.6181 26.8287 26.0498 26.5328 25.2712C27.4204 24.8041 28.1911 24.1579 28.7984 23.3716H29.25C30.8226 23.3716 31.9905 23.8465 32.8079 24.8041ZM23.3719 24.4927H21.628C18.7006 24.4927 16.3105 22.1026 16.3105 19.1752V12.5576C17.7352 11.8024 22.9904 9.4745 28.6894 12.5576V19.1752C28.6894 22.1026 26.2993 24.4927 23.3719 24.4927ZM26.128 27.638C26.0501 28.004 24.8122 28.6424 22.5 28.6424C20.1954 28.6424 18.9576 28.0117 18.8797 27.638C19.3546 27.1242 19.6894 26.4624 19.923 25.824C20.4679 25.9642 21.0441 26.0498 21.628 26.0498H23.3719C23.9558 26.0498 24.532 25.9642 25.077 25.824C25.3183 26.4624 25.6609 27.1242 26.128 27.638ZM31.7102 16.1467C31.461 17.8673 30.6903 17.875 30.2465 17.8361V14.8543L30.4489 14.7531C31.0873 14.4339 31.4844 14.4339 31.5856 14.5039C31.6012 14.5195 31.9126 14.7608 31.7102 16.1467ZM14.7534 17.8361C14.3252 17.875 13.5389 17.8828 13.2897 16.1467C13.0873 14.7608 13.3987 14.5195 13.4143 14.5039C13.5155 14.4261 13.9048 14.4339 14.5432 14.7453L14.7534 14.8543V17.8361ZM15.1271 11.4364C14.8935 11.5844 14.7534 11.8335 14.7534 12.0982V13.1415C13.6245 12.721 12.9082 12.9468 12.4956 13.2505C12.0129 13.6008 11.7404 14.1769 11.6859 14.9711C10.8996 14.5117 10.4247 14.0057 10.378 13.5463C10.3079 12.9468 10.9229 12.1605 12.1608 11.3041C12.3321 11.1873 12.4878 11.0705 12.628 10.9304C18.9186 7.07657 26.5173 7.09214 32.4809 10.9849C32.6133 11.1095 32.7379 11.234 32.8936 11.343C34.077 12.1605 34.692 12.9468 34.6297 13.5385C34.5752 14.0057 34.1003 14.5039 33.314 14.9711C33.2517 14.1769 32.987 13.6008 32.5043 13.2505C32.0917 12.9468 31.3832 12.721 30.2465 13.1415V12.0982C30.2457 11.9623 30.2093 11.8291 30.141 11.7116C30.0727 11.5941 29.9749 11.4966 29.8572 11.4287C22.3442 7.05322 15.4152 11.2574 15.1271 11.4364ZM12.192 24.8041C13.0094 23.8465 14.1773 23.3716 15.7499 23.3716H16.2015C16.8115 24.1602 17.5842 24.8081 18.4671 25.2712C18.1868 26.0342 17.8131 26.6104 17.416 26.8517C16.9878 27.0697 10.9307 30.1138 10.9307 30.1138C10.9074 30.1216 10.884 30.1372 10.8607 30.1528C10.7906 28.8059 10.8684 26.3534 12.192 24.8041ZM12.1998 39.8924C11.3752 39.4944 10.5699 39.0579 9.78626 38.5844C9.45927 38.382 9.2802 38.0239 9.32691 37.6502L9.73954 34.3024C9.89525 33.0801 10.6115 32.0135 11.6236 31.5074C12.0363 31.2972 12.4178 31.1026 12.7837 30.9235C12.0596 33.1502 12.1141 37.5957 12.1998 39.8924ZM27.7007 41.5118L27.6072 41.7998C25.91 42.2203 24.205 42.4382 22.5 42.446C20.8027 42.4382 19.0899 42.2203 17.3927 41.7998L17.2915 41.4884C15.9446 37.6891 16.9178 31.8656 17.4005 29.5455C17.5095 29.055 17.5795 28.7669 17.5795 28.7591C17.5951 28.6891 17.5951 28.6268 17.5951 28.5567C17.852 28.9616 18.3269 29.382 19.1911 29.7012C20.0787 30.0204 21.2543 30.1995 22.5 30.1995C24.4385 30.1995 26.634 29.7557 27.4048 28.5723C27.4048 28.6346 27.4048 28.6969 27.4204 28.7591C27.4204 28.7669 27.4904 29.055 27.5994 29.5377C28.0821 31.8656 29.0553 37.6891 27.7007 41.5118ZM35.673 37.6502C35.7197 38.0239 35.5406 38.382 35.2136 38.5844C34.4117 39.0671 33.6098 39.4953 32.8001 39.8924C32.8858 37.5957 32.9403 33.1268 32.2162 30.9235C32.5822 31.1026 32.9636 31.2972 33.3763 31.4996C34.3884 32.0135 35.1047 33.0801 35.2604 34.3024L35.673 37.6502Z" />
                                <path
                                    d="M26.8287 16.4349C26.8287 17.0033 26.3616 17.4704 25.7933 17.4704C25.2249 17.4704 24.7656 17.0033 24.7656 16.4349C24.7656 15.8666 25.2249 15.4072 25.7933 15.4072C26.3616 15.4072 26.8287 15.8666 26.8287 16.4349ZM20.2344 16.4349C20.2344 17.0033 19.7751 17.4704 19.2067 17.4704C18.6384 17.4704 18.1713 17.0033 18.1713 16.4349C18.1713 15.8666 18.6384 15.4072 19.2067 15.4072C19.7751 15.4072 20.2344 15.8666 20.2344 16.4349Z" />
                            </svg>
                        </div>
                        <div class="content">
                            <div class="number">
                                <h2 class="counter">98</h2>
                                <span>%</span>
                            </div>
                            <span>Retention Rate</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> --}}
    <!-- Counter Section End-->
@endsection
