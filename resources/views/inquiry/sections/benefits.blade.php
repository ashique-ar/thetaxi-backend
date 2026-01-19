@php
    $data = $section['data'] ?? [];
@endphp

<div class="testimonial-section mb-100">
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <div class="section-title1 text-center mb-5">
                    @if (!empty($data['kicker']))
                        <span>{{ $data['kicker'] }}</span>
                    @endif
                    @if (!empty($data['heading']))
                        <h2>{{ $data['heading'] }}</h2>
                    @endif
                </div>
            </div>
        </div>
        <div class="row g-4">
            @foreach ($data['items'] ?? [] as $item)
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        @if (!empty($item['icon']))
                            <div class="benefit-icon mb-3">
                                <i class="{{ $item['icon'] }}" style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                            </div>
                        @endif
                        <h5>{{ $item['title'] ?? 'Benefit' }}</h5>
                        @if (!empty($item['description']))
                            <p>{{ $item['description'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
