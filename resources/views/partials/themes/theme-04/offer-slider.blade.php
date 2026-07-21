<section class="t4-offers home4-offer-slider-section" aria-label="Current offers">
    <div class="container">
        <div class="swiper home4-offer-slider t4-offers__slider">
            <div class="swiper-wrapper">
                <div class="swiper-slide"><a href="{{ $settings['offer_slider_link_1'] ?? route('booking.search') }}" aria-label="View offer 1"><img src="{{ s3_asset($settings['offer_slider_img_1'] ?? 'assets/img/home4/home4-offer-slider-img1.jpg') }}" alt="" width="1600" height="620" loading="lazy"><span aria-hidden="true"><i class="bi bi-arrow-right"></i></span></a></div>
                <div class="swiper-slide"><a href="{{ $settings['offer_slider_link_2'] ?? route('booking.search') }}" aria-label="View offer 2"><img src="{{ s3_asset($settings['offer_slider_img_2'] ?? 'assets/img/home4/home4-offer-slider-img2.jpg') }}" alt="" width="1600" height="620" loading="lazy"><span aria-hidden="true"><i class="bi bi-arrow-right"></i></span></a></div>
            </div>
            <div class="swiper-pagination2 paginations two" aria-label="Offer pages"></div>
        </div>
    </div>
</section>
