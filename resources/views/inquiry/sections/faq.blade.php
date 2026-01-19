@php
    $data = $section['data'] ?? [];
    $items = $data['items'] ?? [];
@endphp

<div class="faq-section mb-100">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="section-title1 text-center mb-5">
                    @if (!empty($data['kicker']))
                        <span>{{ $data['kicker'] }}</span>
                    @endif
                    @if (!empty($data['heading']))
                        <h2>{{ $data['heading'] }}</h2>
                    @endif
                </div>

                <div class="accordion" id="inquiryFaqAccordion">
                    @foreach ($items as $index => $item)
                        @php
                            $collapseId = 'inquiryFaqCollapse' . $index;
                            $headingId = 'inquiryFaqHeading' . $index;
                            $isFirst = $index === 0;
                        @endphp
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="{{ $headingId }}">
                                <button class="accordion-button {{ $isFirst ? '' : 'collapsed' }}" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}">
                                    {{ $item['question'] ?? 'Question' }}
                                </button>
                            </h2>
                            <div id="{{ $collapseId }}" class="accordion-collapse collapse {{ $isFirst ? 'show' : '' }}"
                                data-bs-parent="#inquiryFaqAccordion">
                                <div class="accordion-body">
                                    {{ $item['answer'] ?? '' }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
