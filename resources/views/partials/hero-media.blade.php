@if ($slide['type'] === 'video')
    <video class="shared-hero-slide__media" muted playsinline preload="metadata"
        @if (!empty($slide['poster'])) poster="{{ s3_asset($slide['poster']) }}" @endif
        aria-label="{{ $slide['alt'] }}">
        <source src="{{ s3_asset($slide['video']) }}">
    </video>
@else
    <picture>
        @if (!empty($slide['mobile']))
            <source media="(max-width: 767px)" srcset="{{ s3_asset($slide['mobile']) }}">
        @endif
        <img class="shared-hero-slide__media" src="{{ s3_asset($slide['desktop']) }}"
            alt="{{ $slide['alt'] }}" width="1920" height="1080"
            loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
            @if ($index === 0) fetchpriority="high" @endif>
    </picture>
@endif
