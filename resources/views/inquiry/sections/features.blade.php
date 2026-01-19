@php
    $data = $section['data'] ?? [];
    $vector = $data['vector'] ?? null;
    $vectorUrl = $vector
        ? (\Illuminate\Support\Str::startsWith($vector, ['http://', 'https://'])
            ? $vector
            : (\Illuminate\Support\Str::startsWith($vector, 'assets/')
                ? asset($vector)
                : s3_asset($vector)))
        : asset('assets/img/home4/vector/feature-card-vector.svg');
@endphp

<div class="home4-feature-section mb-100">
    <div class="container">
        <div class="row text-center mb-5">
            <div class="col-12">
                @if (!empty($data['heading']))
                    <h2 class="wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                        {{ $data['heading'] }}
                    </h2>
                @endif
                @if (!empty($data['description']))
                    <p class="wow animate fadeInDown" data-wow-delay="300ms" data-wow-duration="1500ms">
                        {{ $data['description'] }}
                    </p>
                @endif
            </div>
        </div>
        <div class="row g-4">
            @foreach ($data['items'] ?? [] as $index => $item)
                @php
                    $icon = $item['icon'] ?? null;
                    $iconUrl = $icon
                        ? (\Illuminate\Support\Str::startsWith($icon, ['http://', 'https://'])
                            ? $icon
                            : (\Illuminate\Support\Str::startsWith($icon, 'assets/')
                                ? asset($icon)
                                : s3_asset($icon)))
                        : null;
                    $cardClass = $index === 1 ? 'two' : ($index === 2 ? 'three' : '');
                @endphp
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="feature-card {{ $cardClass }}">
                        @if ($iconUrl)
                            <div class="icon">
                                <img src="{{ $iconUrl }}" alt="{{ $item['title'] ?? 'Feature' }}">
                            </div>
                        @endif
                        <h4>{{ $item['title'] ?? 'Feature' }}</h4>
                        @if (!empty($item['description']))
                            <p>{{ $item['description'] }}</p>
                        @endif
                        <img src="{{ $vectorUrl }}" alt="" class="vector">
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
