@php
    $testimonialAuthorImages = [
        $settings['testimonial_author_img_1'] ?? 'assets/img/home4/testimonial-author-img1.png',
        $settings['testimonial_author_img_2'] ?? 'assets/img/home4/testimonial-author-img2.png',
        $settings['testimonial_author_img_3'] ?? 'assets/img/home4/testimonial-author-img3.png',
        $settings['testimonial_author_img_4'] ?? 'assets/img/home4/testimonial-author-img4.png',
        $settings['testimonial_author_img_5'] ?? 'assets/img/home4/testimonial-author-img5.png',
    ];
@endphp

<section class="t3-testimonials home4-testimonial-section" aria-labelledby="t3-testimonials-title">
    <div class="container">
        <header class="t3-testimonials__heading">
            <div>
                <span class="t3-kicker">Client voices</span>
                <h2 id="t3-testimonials-title">{{ $settings['testimonials_section_title'] ?? 'Hear It from Travelers' }}</h2>
            </div>
            <p>{{ $settings['testimonials_section_description'] ?? 'We go beyond just booking trips—we create unforgettable travel experiences that match your dreams!' }}</p>
        </header>

        <div class="t3-testimonials__stage">
            <div class="t3-testimonials__quote-mark" aria-hidden="true">“</div>

            <div class="t3-testimonials__main">
                <div class="swiper home4-testimonial-slider t3-testimonials__quotes">
                    <div class="swiper-wrapper">
                        @foreach ($testimonials as $testimonial)
                            <div class="swiper-slide">
                                <article class="t3-testimonials__quote testimonial-card five">
                                    <div class="t3-testimonials__quote-meta">
                                        <ul class="rating-area" aria-label="{{ (int) $testimonial->rating }} out of 5 stars">
                                            @for ($i = 1; $i <= 5; $i++)
                                                @if ($i <= $testimonial->rating)
                                                    <li><i class="bi bi-circle-fill" aria-hidden="true"></i></li>
                                                @else
                                                    <li><i class="bi bi-circle-half" aria-hidden="true"></i></li>
                                                @endif
                                            @endfor
                                        </ul>
                                        <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                    </div>

                                    <h3>{{ $testimonial->position ?? 'Customer Review' }}</h3>
                                    <blockquote>
                                        <p>{{ $testimonial->content }}</p>
                                    </blockquote>

                                    <footer class="author-area">
                                        <div class="author-info">
                                            <h4>{{ $testimonial->name }}</h4>
                                            <span>{{ $testimonial->company ?? $testimonial->location }}</span>
                                        </div>
                                    </footer>
                                </article>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="t3-testimonials__controls slider-btn-grp" aria-label="Testimonial navigation">
                    <button type="button" class="slider-btn testimonial-slider-prev" aria-label="Previous testimonial">
                        <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <path d="M11.002 13.0005C10.002 10.5005 5.00195 8.00049 2.00195 7.00049C5.00195 6.00049 9.50195 4.50049 11.002 1.00049" stroke-width="1.5" stroke-linecap="round" />
                        </svg>
                    </button>
                    <button type="button" class="slider-btn testimonial-slider-next" aria-label="Next testimonial">
                        <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <path d="M2.99805 13.0005C3.99805 10.5005 8.99805 8.00049 11.998 7.00049C8.99805 6.00049 4.49805 4.50049 2.99805 1.00049" stroke-width="1.5" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>
            </div>

            <aside class="t3-testimonials__navigator" aria-label="Testimonial authors">
                <span class="t3-testimonials__navigator-label">Review index</span>
                <div class="swiper home4-testimonial-img-slider t3-testimonials__authors">
                    <div class="swiper-wrapper">
                        @foreach ($testimonialAuthorImages as $authorImage)
                            <div class="swiper-slide">
                                <div class="testimonial-author-img">
                                    <img
                                        src="{{ s3_asset($authorImage) }}"
                                        alt=""
                                        width="160"
                                        height="160"
                                        loading="lazy">
                                    <span aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <img
        src="{{ s3_asset($settings['testimonial_vector'] ?? 'assets/img/home4/vector/home4-testimonial-vector.png') }}"
        alt=""
        class="t3-testimonials__vector vector"
        width="720"
        height="480"
        loading="lazy">
</section>
