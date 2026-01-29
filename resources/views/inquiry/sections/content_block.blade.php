@php
    $data = $section['data'] ?? [];
    $image = $data['image'] ?? null;
    $imageUrl = $image
        ? (\Illuminate\Support\Str::startsWith($image, ['http://', 'https://'])
            ? $image
            : (\Illuminate\Support\Str::startsWith($image, 'assets/')
                ? asset($image)
                : s3_asset($image)))
        : null;
    $imagePosition = $data['image_position'] ?? 'right';
    $showImage = !empty($imageUrl);
@endphp

<div class="inquiry-content-section">
    <div class="container">
        <div class="row align-items-center g-4">
            @if ($showImage && $imagePosition === 'left')
                <div class="col-lg-6">
                    <img src="{{ $imageUrl }}" alt="{{ $data['heading'] ?? 'Content' }}" class="img-fluid rounded">
                </div>
            @endif

            <div class="{{ $showImage ? 'col-lg-6' : 'col-lg-8 mx-auto' }}">
                <div class="section-title1 mb-3">
                    @if (!empty($data['kicker']))
                        <span>{{ $data['kicker'] }}</span>
                    @endif
                    @if (!empty($data['heading']))
                        <h2>{{ $data['heading'] }}</h2>
                    @endif
                </div>
                <div class="inquiry-content-body">
                    {!! $data['body'] ?? '' !!}
                </div>
            </div>

            @if ($showImage && $imagePosition !== 'left')
                <div class="col-lg-6">
                    <img src="{{ $imageUrl }}" alt="{{ $data['heading'] ?? 'Content' }}" class="img-fluid rounded">
                </div>
            @endif
        </div>
    </div>
</div>
