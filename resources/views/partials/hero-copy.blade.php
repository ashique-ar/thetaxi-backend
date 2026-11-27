@if (!empty($slide['eyebrow']))
    <span class="shared-hero-eyebrow"><span class="shared-hero-eyebrow__dot" aria-hidden="true"></span>{{ $slide['eyebrow'] }}</span>
@endif
@if ($slide['heading'] !== '')
    @if ($index === 0)
        <h1 class="shared-hero-heading">{{ $slide['heading'] }}</h1>
    @else
        <h2 class="shared-hero-heading">{{ $slide['heading'] }}</h2>
    @endif
@endif
@if ($slide['subheading'] !== '')
    <p class="shared-hero-description">{{ $slide['subheading'] }}</p>
@endif
@if (empty($hideBarMeta) && $slide['caption'] !== '')
    <small class="shared-hero-caption">{{ $slide['caption'] }}</small>
@endif
@if (($slide['ctaText'] ?? '') !== '')
    <div class="shared-hero-actions">
        <a class="shared-hero-action" href="{{ $slide['ctaUrl'] }}"><span>{{ $slide['ctaText'] }}</span> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
@endif
@if (empty($hideBarMeta) && !empty($slide['locationLabel']))
    <span class="shared-hero-location"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> <span>{{ $slide['locationLabel'] }}</span></span>
@endif
