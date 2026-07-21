<div class="faq-wrap">
    <div class="accordion accordion-flush" id="{{ $accordionId }}">
        @foreach ($faqItems as $faq)
            @php
                $itemId = $accordionId . '-' . $faq->id;
                $isExpanded = (bool) $autoExpand && $loop->first;
            @endphp
            <article class="accordion-item wow animate fadeInDown" data-wow-delay="{{ min(800, 200 * $loop->iteration) }}ms"
                data-wow-duration="1500ms">
                <h3 class="accordion-header" id="{{ $itemId }}-heading">
                    <button class="accordion-button {{ $isExpanded ? '' : 'collapsed' }}" type="button"
                        data-bs-toggle="collapse" data-bs-target="#{{ $itemId }}-collapse"
                        aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                        aria-controls="{{ $itemId }}-collapse">
                        {{ $faq->question }}
                    </button>
                </h3>
                <div id="{{ $itemId }}-collapse" class="accordion-collapse collapse {{ $isExpanded ? 'show' : '' }}"
                    aria-labelledby="{{ $itemId }}-heading" data-bs-parent="#{{ $accordionId }}">
                    <div class="accordion-body">{!! $faq->answer !!}</div>
                </div>
            </article>
        @endforeach
    </div>
</div>
