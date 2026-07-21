@php
    $theme04Proofs = [
        [$settings['why_feature_1'] ?? 'Customizable Package.', $settings['why_feature_icon_1'] ?? 'assets/img/home3/icon/destination-feature-icon1.svg'],
        [$settings['why_feature_2'] ?? '24/7 Support', $settings['why_feature_icon_2'] ?? 'assets/img/home3/icon/destination-feature-icon2.svg'],
        [$settings['why_feature_3'] ?? 'Trusted by Thousands', $settings['why_feature_icon_3'] ?? 'assets/img/home3/icon/destination-feature-icon3.svg'],
        [$settings['why_feature_4'] ?? 'Local Expertise', $settings['why_feature_icon_4'] ?? 'assets/img/home3/icon/destination-feature-icon4.svg'],
    ];
@endphp

<section class="t4-proof" aria-labelledby="t4-proof-title">
    <div class="container">
        <div class="t4-proof__panel">
            <div class="t4-proof__intro">
                <span class="t4-kicker">{{ $settings['why_section_title'] ?? 'Why Choose Us' }}</span>
                <h2 id="t4-proof-title">{{ $settings['offer_section_description'] ?? 'A curated list of the most popular travel packages based on different destinations.' }}</h2>
                <div class="t4-proof__review">
                    <a href="https://www.tripadvisor.com/">
                        <strong>4.5</strong>
                        <span><img src="{{ s3_asset($settings['tripadvisor_logo'] ?? 'assets/img/home1/icon/tripadvisor-logo.svg') }}" alt=""><img src="{{ s3_asset($settings['tripadvisor_stars'] ?? 'assets/img/home1/icon/tripadvisor-start.svg') }}" alt=""><small>Reviews</small></span>
                    </a>
                </div>
            </div>
            <div class="t4-proof__media">
                <img src="{{ $settings['why_video_image'] ? s3_asset($settings['why_video_image']) : asset('assets/img/home4/why-choose-video-img.jpg') }}" alt="" width="1600" height="1080" loading="lazy">
                <a data-fancybox="video-player" href="https://www.youtube.com/watch?v=u31qwQUeGuM" aria-label="Play video"><i class="bi bi-play-fill" aria-hidden="true"></i></a>
            </div>
        </div>
        <ol class="t4-proof__features">
            @foreach ($theme04Proofs as [$label, $icon])
                <li><img src="{{ s3_asset($icon) }}" alt="" loading="lazy"><span>{{ $label }}</span></li>
            @endforeach
            <li class="t4-proof__help">
                <span>{{ $settings['why_help_text'] ?? 'Need help? Our transport team is ready to assist.' }}</span>
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '') }}">{{ $settings['company_phone'] ?? 'Call us' }}</a>
            </li>
        </ol>
    </div>
</section>
