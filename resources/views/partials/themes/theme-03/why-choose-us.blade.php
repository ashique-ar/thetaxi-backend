@php
    $proofs = [
        [
            'label' => $settings['why_feature_1'] ?? 'Customizable Package.',
            'icon' => $settings['why_feature_icon_1'] ?? 'assets/img/home3/icon/destination-feature-icon1.svg',
        ],
        [
            'label' => $settings['why_feature_2'] ?? '24/7 Support',
            'icon' => $settings['why_feature_icon_2'] ?? 'assets/img/home3/icon/destination-feature-icon2.svg',
        ],
        [
            'label' => $settings['why_feature_3'] ?? 'Trusted by Thousands',
            'icon' => $settings['why_feature_icon_3'] ?? 'assets/img/home3/icon/destination-feature-icon3.svg',
        ],
        [
            'label' => $settings['why_feature_4'] ?? 'Local Expertise',
            'icon' => $settings['why_feature_icon_4'] ?? 'assets/img/home3/icon/destination-feature-icon4.svg',
        ],
    ];
@endphp

<section class="t3-proof" aria-labelledby="t3-proof-title">
    <div class="container">
        <header class="t3-proof__heading">
            <div>
                <span class="t3-kicker">Why choose us</span>
                <h2 id="t3-proof-title">{{ $settings['why_section_title'] ?? 'Why Choose Us' }}</h2>
                <p>{{ $settings['offer_section_description'] ?? 'A curated list of the most popular travel packages based on different destinations.' }}</p>
            </div>

            <a href="https://www.tripadvisor.com/" class="t3-proof__rating single-rating">
                <strong>4.5</strong>
                <span class="t3-proof__rating-brand">
                    <img
                        src="{{ s3_asset($settings['tripadvisor_logo'] ?? 'assets/img/home1/icon/tripadvisor-logo.svg') }}"
                        alt="">
                    <span>
                        <small>Reviews</small>
                        <img
                            src="{{ s3_asset($settings['tripadvisor_stars'] ?? 'assets/img/home1/icon/tripadvisor-start.svg') }}"
                            alt="">
                    </span>
                </span>
            </a>
        </header>

        <div class="t3-proof__layout">
            <div class="t3-proof__media">
                <img
                    src="{{ $settings['why_video_image'] ? s3_asset($settings['why_video_image']) : asset('assets/img/home4/why-choose-video-img.jpg') }}"
                    alt=""
                    width="1600"
                    height="1080"
                    loading="lazy">

                <a
                    data-fancybox="video-player"
                    href="https://www.youtube.com/watch?v=u31qwQUeGuM"
                    class="t3-proof__play play-btn"
                    aria-label="Play video">
                    <i class="bi bi-play-fill" aria-hidden="true"></i>
                </a>
            </div>

            <div class="t3-proof__ledger">
                <ol>
                    @foreach ($proofs as $proof)
                        <li>
                            <span aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <img src="{{ s3_asset($proof['icon']) }}" alt="" loading="lazy">
                            <h3>{{ $proof['label'] }}</h3>
                        </li>
                    @endforeach
                </ol>

                <div class="t3-proof__contact contact-area">
                    <p>{{ $settings['why_help_text'] ?? 'Need help? Our transport team is ready to assist.' }}</p>
                    <div class="single-contact">
                        <span class="t3-proof__phone-icon" aria-hidden="true">
                            <i class="bi bi-telephone"></i>
                        </span>
                        <span>
                            <small>{{ $settings['header_help_label'] ?? 'Need Help?' }}</small>
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '') }}">{{ $settings['company_phone'] ?? 'Call us' }}</a>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
