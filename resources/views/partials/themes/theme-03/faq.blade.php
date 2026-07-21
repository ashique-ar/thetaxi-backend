<section class="t3-faq" aria-labelledby="t3-faq-title">
    <div class="container">
        <header class="t3-faq__heading">
            <p class="t3-kicker">{{ str_pad((string) 11, 2, '0', STR_PAD_LEFT) }} / FAQ</p>
            <h2 id="t3-faq-title">{{ $settings['faq_section_title'] ?? 'Questions & Answer' }}</h2>
            <p>We’re committed to offering more than just products—we provide exceptional experiences.</p>
        </header>

        <div class="t3-faq__layout">
            <div class="accordion accordion-flush t3-faq__accordion" id="accordionFlushExample">
                @foreach ($faqs as $index => $faq)
                    <article class="accordion-item t3-faq__item">
                        <h3 class="accordion-header" id="flush-heading{{ \Str::camel($faq->id) }}">
                            <button class="accordion-button {{ $index === 0 ? '' : 'collapsed' }}"
                                type="button" data-bs-toggle="collapse"
                                data-bs-target="#flush-collapse{{ \Str::camel($faq->id) }}"
                                aria-expanded="{{ $index === 0 ? 'true' : 'false' }}"
                                aria-controls="flush-collapse{{ \Str::camel($faq->id) }}">
                                <span aria-hidden="true">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                {{ $faq->question }}
                            </button>
                        </h3>
                        <div id="flush-collapse{{ \Str::camel($faq->id) }}"
                            class="accordion-collapse collapse {{ $index === 0 ? 'show' : '' }}"
                            aria-labelledby="flush-heading{{ \Str::camel($faq->id) }}"
                            data-bs-parent="#accordionFlushExample">
                            <div class="accordion-body">{!! $faq->answer !!}</div>
                        </div>
                    </article>
                @endforeach
            </div>

            <figure class="t3-faq__art" aria-hidden="true">
                <img src="{{ s3_asset($settings['faq_section_vector'] ?? 'assets/img/home4/vector/faq-section-vector.svg') }}"
                    alt="" loading="lazy" width="420" height="520">
            </figure>
        </div>
    </div>
</section>
