@php
    $data = $section['data'] ?? [];
    $bannerImage = $data['banner_image'] ?? null;
    $bannerUrl = $bannerImage
        ? (\Illuminate\Support\Str::startsWith($bannerImage, ['http://', 'https://'])
            ? $bannerImage
            : (\Illuminate\Support\Str::startsWith($bannerImage, 'assets/')
                ? asset($bannerImage)
                : s3_asset($bannerImage)))
        : asset('assets/img/home4/home4-banner-img.jpg');
    $showForm = false;
    if (!empty($data['form_id']) && !empty($servicePage->form)) {
        $showForm = $servicePage->form->id === $data['form_id'];
    } elseif (!empty($data['show_form']) && !empty($servicePage->form)) {
        $showForm = (bool) $data['show_form'];
    }
@endphp

<div class="home4-banner-section mb-100">
    <div class="banner-video-area">
        <img src="{{ $bannerUrl }}" alt="{{ $data['heading'] ?? 'Service' }}" loading="lazy">
    </div>
    <div class="banner-content-wrap">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $data['heading'] ?? $servicePage->name }}</h1>
                @if (!empty($data['subheading']))
                    <p>{{ $data['subheading'] }}</p>
                @endif

                @if ($showForm)
                    <div class="filter-wrapper">
                        <div class="filter-input-wrap">
                            @include('inquiry.partials.form', ['form' => $servicePage->form, 'servicePage' => $servicePage])
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
