@php
    $theme04AuthorImages = [
        $settings['testimonial_author_img_1'] ?? 'assets/img/home4/testimonial-author-img1.png',
        $settings['testimonial_author_img_2'] ?? 'assets/img/home4/testimonial-author-img2.png',
        $settings['testimonial_author_img_3'] ?? 'assets/img/home4/testimonial-author-img3.png',
        $settings['testimonial_author_img_4'] ?? 'assets/img/home4/testimonial-author-img4.png',
        $settings['testimonial_author_img_5'] ?? 'assets/img/home4/testimonial-author-img5.png',
    ];
@endphp

<section class="t4-testimonials home4-testimonial-section" aria-labelledby="t4-testimonials-title" data-t4-testimonials>
    <div class="container">
        <header class="t4-section-heading">
            <span class="t4-kicker">Trusted by our clients</span>
            <h2 id="t4-testimonials-title">{{ $settings['testimonials_section_title'] ?? 'Hear It from Travelers' }}</h2>
            <p>{{ $settings['testimonials_section_description'] ?? 'We go beyond just booking trips—we create unforgettable travel experiences that match your dreams!' }}</p>
        </header>
        <div class="swiper t4-testimonial-slider">
            <div class="swiper-wrapper">
                @foreach ($testimonials as $testimonial)
                    <div class="swiper-slide">
                        <article class="t4-testimonial-card">
                            <span class="t4-testimonial-card__quote" aria-hidden="true">“</span>
                            <ul aria-label="{{ (int) $testimonial->rating }} out of 5 stars">@for ($i = 1; $i <= 5; $i++)<li><i class="bi {{ $i <= $testimonial->rating ? 'bi-circle-fill' : 'bi-circle-half' }}" aria-hidden="true"></i></li>@endfor</ul>
                            <p>{{ $testimonial->content }}</p>
                            <footer>
                                <img src="{{ s3_asset($theme04AuthorImages[$loop->index % count($theme04AuthorImages)]) }}" alt="" width="96" height="96" loading="lazy">
                                <span><strong>{{ $testimonial->name }}</strong><small>{{ $testimonial->position ?? 'Customer Review' }}</small><em>{{ $testimonial->company ?? $testimonial->location }}</em></span>
                            </footer>
                        </article>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="t4-testimonials__controls"><button type="button" class="t4-testimonial-prev" aria-label="Previous testimonials"><i class="bi bi-chevron-left" aria-hidden="true"></i></button><button type="button" class="t4-testimonial-next" aria-label="Next testimonials"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div>
    </div>
</section>
