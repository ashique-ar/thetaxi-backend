@php
    $data = $section['data'] ?? [];
    $image = $data['image'] ?? null;
    $imageUrl = $image
        ? (\Illuminate\Support\Str::startsWith($image, ['http://', 'https://'])
            ? $image
            : (\Illuminate\Support\Str::startsWith($image, 'assets/')
                ? asset($image)
                : s3_asset($image)))
        : asset('assets/img/home4/package-img.jpg');
@endphp

<div class="package-section mb-100">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 wow animate fadeInLeft" data-wow-delay="200ms" data-wow-duration="1500ms">
                <div class="package-content-wrap">
                    <div class="section-title1 mb-4">
                        @if (!empty($data['kicker']))
                            <span>{{ $data['kicker'] }}</span>
                        @endif
                        @if (!empty($data['heading']))
                            <h2>{{ $data['heading'] }}</h2>
                        @endif
                    </div>
                    @if (!empty($data['description']))
                        <p class="mb-4">{{ $data['description'] }}</p>
                    @endif

                    <div class="service-features">
                        @foreach ($data['features'] ?? [] as $feature)
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>{{ $feature }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="col-lg-6 wow animate fadeInRight" data-wow-delay="400ms" data-wow-duration="1500ms">
                <div class="package-img-area">
                    <img src="{{ $imageUrl }}" alt="{{ $data['heading'] ?? 'Service' }}" class="img-fluid">
                </div>
            </div>
        </div>
    </div>
</div>
